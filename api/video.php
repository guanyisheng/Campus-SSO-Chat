<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/quota.php';
require_once dirname(__DIR__) . '/lib/media.php';
require_once dirname(__DIR__) . '/lib/media_queue.php';
require_once dirname(__DIR__) . '/lib/conversations.php';
require_once dirname(__DIR__) . '/lib/content_policy.php';

api_json_headers();

require_login();
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!setting_bool('enable_video_gen', true)) {
    http_response_code(503);
    echo json_encode(['error' => '生视频功能已关闭'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

/** 视频生成成功后再扣额度，同一任务只扣一次 */
function video_quota_consume_on_success(int $userId, string $saveKey): void
{
    if ($userId <= 0 || $saveKey === '') {
        return;
    }
    if (!isset($_SESSION['video_quota_consumed'][$userId]) || !is_array($_SESSION['video_quota_consumed'][$userId])) {
        $_SESSION['video_quota_consumed'][$userId] = [];
    }
    if (!empty($_SESSION['video_quota_consumed'][$userId][$saveKey])) {
        return;
    }
    quota_consume($userId, 'video');
    $_SESSION['video_quota_consumed'][$userId][$saveKey] = true;
}

if ($method === 'GET') {
    $taskId = trim((string) ($_GET['task_id'] ?? ''));
    $videoId = trim((string) ($_GET['video_id'] ?? ''));
    $providerId = (int) ($_GET['provider_id'] ?? 0);
    if ($taskId === '' && $videoId === '') {
        http_response_code(400);
        echo json_encode(['error' => '缺少 task_id 或 video_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $provider = $providerId > 0 ? media_provider_get($providerId, 'video') : media_default_provider('video');
    if (!$provider) {
        http_response_code(400);
        echo json_encode(['error' => '未配置视频 API'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $poll = media_poll_video_task(
            $provider,
            $taskId,
            $videoId,
            (string) ($provider['model_name'] ?? '')
        );
        $status = strtolower($poll['status']);
        $done = media_video_status_is_done($status)
            || (!empty($poll['video_url']) && !media_video_status_is_failed($status));
        $failed = media_video_status_is_failed($status);

        $saveKey = $videoId !== '' ? $videoId : $taskId;
        $response = [
            'task_id'     => $taskId,
            'video_id'    => $videoId,
            'status'      => $poll['status'],
            'provider_id' => (int) $provider['id'],
        ];
        if (isset($poll['progress'])) {
            $response['progress'] = (int) $poll['progress'];
        }

        if (!empty($poll['video_url'])) {
            $response['video_url'] = $poll['video_url'];
        }
        if (!empty($poll['error'])) {
            $response['error'] = $poll['error'];
        }

        if ($done && !empty($poll['video_url'])) {
            video_quota_consume_on_success($userId, $saveKey);

            $convId = (int) ($_GET['conversation_id'] ?? 0);
            $prompt = trim((string) ($_GET['prompt'] ?? ''));
            $prompts = $_SESSION['video_prompts'][$userId] ?? [];
            if ($prompt === '' && !empty($prompts[$saveKey])) {
                $prompt = (string) $prompts[$saveKey];
            }
            $saved = $_SESSION['video_saved'][$userId] ?? [];
            if ($convId > 0 && $prompt !== '' && empty($saved[$saveKey]) && conv_get_for_user($convId, $userId)) {
                $assistantContent = media_assistant_video_markdown($poll['video_url'], $prompt);
                if (!conv_replace_video_pending($convId, $userId, $assistantContent)) {
                    conv_add_message($convId, $userId, 'assistant', $assistantContent);
                }
                require_once __DIR__ . '/../lib/conv_title.php';
                conv_summarize_title($convId, $userId);
                $_SESSION['video_saved'][$userId][$saveKey] = true;
                unset($_SESSION['video_prompts'][$userId][$saveKey]);
                $response['content'] = $assistantContent;
            }
            $response['quota'] = quota_status($userId);
        }

        if ($failed) {
            http_response_code(502);
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => '无效 JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$prompt = trim((string) ($input['prompt'] ?? ''));
if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => '请填写视频描述'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    content_policy_assert_safe($prompt);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage(), 'refused' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!quota_check($userId, 'video')) {
    http_response_code(429);
    echo json_encode(['error' => quota_error_message('video', $userId), 'quota' => quota_status($userId)], JSON_UNESCAPED_UNICODE);
    exit;
}

$provider = media_pick_provider_for_user($userId, 'video');
if (!$provider) {
    http_response_code(400);
    echo json_encode(['error' => '未配置视频 API，请在后台添加'], JSON_UNESCAPED_UNICODE);
    exit;
}

$convId = (int) ($input['conversation_id'] ?? 0);
if ($convId <= 0) {
    $convId = conv_create($userId, 0, mb_substr($prompt, 0, 40) ?: '生视频');
} else {
    try {
        conv_require_for_user($convId, $userId);
    } catch (InvalidArgumentException $e) {
        http_response_code(404);
        echo json_encode(['error' => '对话不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $queueId = media_queue_enqueue($userId, 'video', (int) $provider['id'], [
        'prompt'          => $prompt,
        'width'           => (int) ($input['width'] ?? 1152),
        'height'          => (int) ($input['height'] ?? 768),
        'num_frames'      => (int) ($input['num_frames'] ?? 121),
        'frame_rate'      => (int) ($input['frame_rate'] ?? 24),
        'images'          => $input['images'] ?? [],
        'conversation_id' => $convId,
    ]);

    $pendingContent = conv_save_media_queue_pending(
        $convId,
        $userId,
        'video',
        '@视频 ' . $prompt,
        $prompt,
        $queueId,
        (int) $provider['id']
    );

    $job = media_queue_get($queueId);
    if (!$job) {
        throw new RuntimeException('排队任务创建失败');
    }

    $response = media_queue_public_status($job);
    $response['conversation_id'] = $convId;

    if ($response['status'] === 'completed') {
        $saveKey = ($response['video_id'] ?? '') !== '' ? (string) $response['video_id'] : (string) ($response['task_id'] ?? '');
        if ($saveKey !== '') {
            if (!isset($_SESSION['video_prompts'][$userId]) || !is_array($_SESSION['video_prompts'][$userId])) {
                $_SESSION['video_prompts'][$userId] = [];
            }
            $_SESSION['video_prompts'][$userId][$saveKey] = $prompt;
        }
    }

    if (($response['status'] ?? '') !== 'completed' && empty($response['pending_content'])) {
        $response['pending_content'] = $pendingContent;
    }

    if ($response['status'] === 'failed') {
        http_response_code(502);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
