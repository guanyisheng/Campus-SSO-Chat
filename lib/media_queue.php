<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/quota.php';
require_once __DIR__ . '/conversations.php';
require_once __DIR__ . '/conv_title.php';

function media_queue_storage_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/media_queue';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function media_queue_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS media_queue (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id INT UNSIGNED NOT NULL,
              job_type ENUM(\'image\',\'video\') NOT NULL,
              provider_id INT UNSIGNED NOT NULL,
              status ENUM(\'queued\',\'processing\',\'completed\',\'failed\') NOT NULL DEFAULT \'queued\',
              payload JSON NOT NULL,
              result_json JSON NULL,
              error_message TEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              started_at DATETIME NULL,
              finished_at DATETIME NULL,
              KEY idx_provider_status (provider_id, status, id),
              KEY idx_user (user_id, id),
              KEY idx_status_created (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable) {
    }
}

/** 按用户 ID 配对分配到 API 线路（1、2→1号，3、4→2号…） */
function media_pick_provider_for_user(int $userId, string $type): ?array
{
    $providers = models_list_enabled_by_type($type);
    if ($providers === []) {
        return null;
    }
    if (count($providers) === 1) {
        return $providers[0];
    }
    $pairIndex = (int) floor(max(0, $userId - 1) / 2);
    $idx = $pairIndex % count($providers);
    return $providers[$idx];
}

function media_queue_lane_lock(int $providerId)
{
    $path = media_queue_storage_dir() . '/lane_' . $providerId . '.lock';
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        return null;
    }
    return $fh;
}

/** @param array<string, mixed> $payload */
function media_queue_enqueue(int $userId, string $jobType, int $providerId, array $payload): int
{
    media_queue_ensure_schema();
    $jobType = $jobType === 'video' ? 'video' : 'image';

    $stmt = db()->prepare(
        'INSERT INTO media_queue (user_id, job_type, provider_id, status, payload)
         VALUES (?, ?, ?, \'queued\', ?)'
    );
    $stmt->execute([
        $userId,
        $jobType,
        $providerId,
        json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    return (int) db()->lastInsertId();
}

function media_queue_get(int $jobId): ?array
{
    media_queue_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM media_queue WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? media_queue_normalize_row($row) : null;
}

function media_queue_get_for_user(int $jobId, int $userId): ?array
{
    $job = media_queue_get($jobId);
    if (!$job || (int) $job['user_id'] !== $userId) {
        return null;
    }
    return $job;
}

function media_queue_save_comfy_prompt_id(int $jobId, string $promptId): void
{
    if ($jobId <= 0 || trim($promptId) === '') {
        return;
    }
    media_queue_ensure_schema();
    db()->prepare(
        'UPDATE media_queue
         SET result_json = JSON_SET(COALESCE(result_json, JSON_OBJECT()), \'$.comfy_prompt_id\', ?)
         WHERE id = ?'
    )->execute([trim($promptId), $jobId]);
}

/** @param array<string, mixed> $row */
function media_queue_normalize_row(array $row): array
{
    $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
    $result = json_decode((string) ($row['result_json'] ?? 'null'), true);
    return [
        'id'            => (int) ($row['id'] ?? 0),
        'user_id'       => (int) ($row['user_id'] ?? 0),
        'job_type'      => (string) ($row['job_type'] ?? ''),
        'provider_id'   => (int) ($row['provider_id'] ?? 0),
        'status'        => (string) ($row['status'] ?? ''),
        'payload'       => is_array($payload) ? $payload : [],
        'result'        => is_array($result) ? $result : null,
        'error_message' => (string) ($row['error_message'] ?? ''),
        'created_at'    => (string) ($row['created_at'] ?? ''),
        'started_at'    => (string) ($row['started_at'] ?? ''),
        'finished_at'   => (string) ($row['finished_at'] ?? ''),
    ];
}

function media_queue_position(int $jobId, int $providerId): int
{
    media_queue_ensure_schema();
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM media_queue
         WHERE provider_id = ? AND status = \'queued\' AND id <= ?'
    );
    $stmt->execute([$providerId, $jobId]);
    return max(0, (int) $stmt->fetchColumn() - 1);
}

function media_queue_lane_stats(int $providerId): array
{
    media_queue_ensure_schema();
    $stmt = db()->prepare(
        'SELECT status, COUNT(*) AS cnt FROM media_queue
         WHERE provider_id = ? AND status IN (\'queued\',\'processing\')
         GROUP BY status'
    );
    $stmt->execute([$providerId]);
    $stats = ['queued' => 0, 'processing' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $stats[(string) $row['status']] = (int) ($row['cnt'] ?? 0);
    }
    return $stats;
}

function media_queue_recover_stale(int $providerId, int $maxSeconds = 900): void
{
    media_queue_ensure_schema();
    db()->prepare(
        'UPDATE media_queue
         SET status = \'failed\', error_message = \'任务超时\', finished_at = NOW()
         WHERE provider_id = ? AND status = \'processing\'
           AND started_at IS NOT NULL
           AND started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)'
    )->execute([$providerId, $maxSeconds]);
}

function media_queue_kick(int $providerId): void
{
    media_queue_process_lane($providerId);
}

/** Flush HTTP response to the client, then kick the queue worker (avoids CDN/proxy timeouts). */
function media_queue_finish_response_and_kick(int $providerId): void
{
    if ($providerId <= 0) {
        return;
    }

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    media_queue_kick($providerId);
}

function media_queue_process_lane(int $providerId): void
{
    media_queue_ensure_schema();
    media_queue_recover_stale($providerId);

    $lock = media_queue_lane_lock($providerId);
    if ($lock === null) {
        return;
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT id FROM media_queue
             WHERE provider_id = ? AND status = \'processing\'
             ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$providerId]);
        if ($stmt->fetchColumn()) {
            return;
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM media_queue
             WHERE provider_id = ? AND status = \'queued\'
             ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$providerId]);
        $jobId = (int) $stmt->fetchColumn();
        if ($jobId <= 0) {
            return;
        }

        $pdo->prepare(
            'UPDATE media_queue SET status = \'processing\', started_at = NOW() WHERE id = ? AND status = \'queued\''
        )->execute([$jobId]);

        $job = media_queue_get($jobId);
        if (!$job || $job['status'] !== 'processing') {
            return;
        }

        try {
            $result = media_queue_execute_job($job);
            $pdo->prepare(
                'UPDATE media_queue SET status = \'completed\', result_json = ?, finished_at = NOW(), error_message = NULL
                 WHERE id = ?'
            )->execute([
                json_encode($result, JSON_UNESCAPED_UNICODE),
                $jobId,
            ]);
        } catch (Throwable $e) {
            $pdo->prepare(
                'UPDATE media_queue SET status = \'failed\', error_message = ?, finished_at = NOW()
                 WHERE id = ?'
            )->execute([$e->getMessage(), $jobId]);
            media_queue_fail_conv_pending($job, $e->getMessage());
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @param array<string, mixed> $job @return array<string, mixed> */
function media_queue_execute_job(array $job): array
{
    $provider = media_provider_get((int) $job['provider_id'], $job['job_type']);
    if (!$provider) {
        throw new RuntimeException('API 线路不存在或已停用');
    }

    $payload = $job['payload'];
    $userId = (int) $job['user_id'];
    $convId = (int) ($payload['conversation_id'] ?? 0);
    $prompt = trim((string) ($payload['prompt'] ?? ''));
    $displayPrompt = trim((string) ($payload['display_prompt'] ?? $prompt));
    if ($displayPrompt === '') {
        $displayPrompt = $prompt;
    }

    if ($job['job_type'] === 'image') {
        $size = trim((string) ($payload['size'] ?? '1024x768'));
        $styleKey = trim((string) ($payload['style_key'] ?? 'default'));
        $imageModelKey = trim((string) ($payload['model'] ?? ''));
        $refImages = is_array($payload['images'] ?? null) ? $payload['images'] : [];

        $result = media_generate_image($provider, $prompt, $size, [
            'style_key'   => $styleKey,
            'image_model' => $imageModelKey,
            'images'      => $refImages,
            'user_id'     => $userId,
            'job_id'      => (int) ($job['id'] ?? 0),
        ]);
        $url = trim((string) ($result['url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('生图 API 未返回有效图片');
        }

        $userDisplay = '@图片 ' . $displayPrompt;
        conv_maybe_set_title_from_message($convId, $userId, $displayPrompt);

        $assistantContent = media_assistant_image_markdown($url, $displayPrompt);
        if (!conv_replace_queue_pending($convId, $userId, $assistantContent)) {
            conv_add_message($convId, $userId, 'user', $userDisplay);
            conv_add_message($convId, $userId, 'assistant', $assistantContent);
        }
        conv_summarize_title($convId, $userId);
        quota_consume($userId, 'image');

        $resultPayload = [
            'url'             => $url,
            'prompt'          => $prompt,
            'conversation_id' => $convId,
            'content'         => $assistantContent,
            'quota'           => quota_status($userId),
            'provider_id'     => (int) $provider['id'],
        ];
        if (!empty($result['comfy_prompt_id'])) {
            $resultPayload['comfy_prompt_id'] = (string) $result['comfy_prompt_id'];
        }

        return $resultPayload;
    }

    $refImages = media_normalize_image_urls($payload['images'] ?? []);
    $task = media_create_video_task($provider, $prompt, [
        'width'      => (int) ($payload['width'] ?? 1152),
        'height'     => (int) ($payload['height'] ?? 768),
        'num_frames' => (int) ($payload['num_frames'] ?? 121),
        'frame_rate' => (int) ($payload['frame_rate'] ?? 24),
        'images'     => $refImages,
    ]);

    $userDisplay = '@视频 ' . $prompt;
    conv_maybe_set_title_from_message($convId, $userId, $prompt);

    $pendingContent = media_video_pending_marker(
        $task['task_id'],
        (int) $provider['id'],
        $prompt,
        $task['video_id']
    );
    if (!conv_replace_queue_pending($convId, $userId, $pendingContent)) {
        conv_add_message($convId, $userId, 'user', $userDisplay);
        conv_add_message($convId, $userId, 'assistant', $pendingContent);
    }

    return [
        'task_id'         => $task['task_id'],
        'video_id'        => $task['video_id'],
        'status'          => $task['status'],
        'progress'        => $task['progress'] ?? 0,
        'provider_id'     => (int) $provider['id'],
        'conversation_id' => $convId,
        'prompt'          => $prompt,
        'pending_content' => $pendingContent,
    ];
}

/** @return array<string, mixed> */
function media_queue_public_status(array $job): array
{
    $position = $job['status'] === 'queued'
        ? media_queue_position((int) $job['id'], (int) $job['provider_id'])
        : 0;

    $out = [
        'queue_id'    => (int) $job['id'],
        'job_type'    => $job['job_type'],
        'status'      => $job['status'],
        'position'    => $position,
        'provider_id' => (int) $job['provider_id'],
    ];

    if ($job['status'] === 'completed' && is_array($job['result'])) {
        $out = array_merge($out, $job['result']);
    }
    if ($job['status'] === 'failed' && $job['error_message'] !== '') {
        $out['error'] = $job['error_message'];
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function media_queue_list_recent(int $limit = 50): array
{
    media_queue_ensure_schema();
    $limit = max(1, min(200, $limit));
    $stmt = db()->query(
        'SELECT mq.*, u.display_name, u.campus_uid, m.display_name AS provider_name
         FROM media_queue mq
         LEFT JOIN users u ON u.id = mq.user_id
         LEFT JOIN llm_models m ON m.id = mq.provider_id
         ORDER BY mq.id DESC
         LIMIT ' . $limit
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $job = media_queue_normalize_row($row);
        $job['user_label'] = trim((string) ($row['display_name'] ?? ''));
        if ($job['user_label'] === '') {
            $job['user_label'] = (string) ($row['campus_uid'] ?? ('#' . $job['user_id']));
        }
        $job['provider_name'] = (string) ($row['provider_name'] ?? ('API #' . $job['provider_id']));
        $out[] = $job;
    }
    return $out;
}

/** @return array<string, mixed> */
function media_queue_admin_summary(): array
{
    media_queue_ensure_schema();
    $summary = [
        'queued'     => 0,
        'processing' => 0,
        'today_done' => 0,
        'today_fail' => 0,
        'lanes'      => [],
    ];

    $stmt = db()->query(
        'SELECT status, COUNT(*) AS cnt FROM media_queue
         WHERE status IN (\'queued\',\'processing\')
         GROUP BY status'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $summary[$row['status']] = (int) ($row['cnt'] ?? 0);
    }

    $stmt = db()->query(
        'SELECT
           SUM(status = \'completed\' AND DATE(finished_at) = CURDATE()) AS done_today,
           SUM(status = \'failed\' AND DATE(finished_at) = CURDATE()) AS fail_today
         FROM media_queue'
    );
    $today = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $summary['today_done'] = (int) ($today['done_today'] ?? 0);
    $summary['today_fail'] = (int) ($today['fail_today'] ?? 0);

    foreach (['image', 'video'] as $type) {
        foreach (models_list_enabled_by_type($type) as $provider) {
            $pid = (int) $provider['id'];
            $stats = media_queue_lane_stats($pid);
            $summary['lanes'][] = [
                'id'         => $pid,
                'type'       => $type,
                'name'       => (string) ($provider['display_name'] ?? ''),
                'base_url'   => (string) ($provider['base_url'] ?? ''),
                'queued'     => $stats['queued'],
                'processing' => $stats['processing'],
            ];
        }
    }

    return $summary;
}

/** 排队/生成失败时把对话里的占位消息改成错误提示 */
function media_queue_fail_conv_pending(array $job, string $error): void
{
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $convId = (int) ($payload['conversation_id'] ?? 0);
    $userId = (int) ($job['user_id'] ?? 0);
    if ($convId <= 0 || $userId <= 0) {
        return;
    }
    $msg = '生成失败：' . trim($error);
    if ($msg === '生成失败：') {
        return;
    }
    if (!conv_replace_queue_pending($convId, $userId, $msg)) {
        conv_replace_video_pending($convId, $userId, $msg);
    }
}
