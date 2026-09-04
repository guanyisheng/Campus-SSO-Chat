<?php
declare(strict_types=1);

require_once __DIR__ . '/redis_client.php';

function conv_storage_enabled(): bool
{
    return true;
}

function conv_storage_user_dir(int $userId): string
{
    $base = rtrim(CONV_STORAGE_DIR, '/\\');
    return $base . DIRECTORY_SEPARATOR . (string) $userId;
}

/** 用户上传附件目录：storage/conversations/{userId}/upload */
function conv_storage_user_upload_dir(int $userId): string
{
    return conv_storage_user_dir($userId) . DIRECTORY_SEPARATOR . 'upload';
}

function conv_storage_safe_basename(string $filename): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $base = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $base) ?? 'file';
    $base = trim((string) $base, '._-');
    if ($base === '') {
        $base = 'file';
    }
    return mb_substr($base, 0, 80);
}

/**
 * 保存用户上传的原始文件到 upload 子目录，返回磁盘上的文件名（不含路径）
 */
function conv_storage_save_upload(int $userId, string $tmpPath, string $originalName, string $ext): string
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('无效用户');
    }

    $dir = conv_storage_user_upload_dir($userId);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建上传目录');
    }

    $ext = strtolower(preg_replace('/[^a-z0-9]/', '', $ext) ?? '');
    if ($ext === '') {
        throw new InvalidArgumentException('无效扩展名');
    }

    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . conv_storage_safe_basename($originalName) . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $stored;

    if (!is_uploaded_file($tmpPath)) {
        throw new RuntimeException('非法上传');
    }
    if (!move_uploaded_file($tmpPath, $dest)) {
        throw new RuntimeException('保存文件失败，请检查目录权限');
    }

    return $stored;
}

function conv_storage_file_path(int $userId, int $convId): string
{
    return conv_storage_user_dir($userId) . DIRECTORY_SEPARATOR . $convId . '.json';
}

function conv_storage_redis_doc_key(int $userId, int $convId): string
{
    return redis_key('u:' . $userId . ':c:' . $convId);
}

function conv_storage_redis_list_key(int $userId): string
{
    return redis_key('u:' . $userId . ':list');
}

/** 用户最后一次「发消息」时间戳（用于空闲清缓存） */
function conv_storage_redis_user_active_key(int $userId): string
{
    return redis_key('u:' . $userId . ':last_user_at');
}

function conv_document_owned_by(array $doc, int $userId): bool
{
    return (int) ($doc['user_id'] ?? 0) === $userId;
}

function conv_redis_idle_seconds(): int
{
    $sec = defined('CONV_REDIS_IDLE_SECONDS') ? (int) CONV_REDIS_IDLE_SECONDS : 1800;
    return $sec > 0 ? $sec : 1800;
}

/** 用户发送消息 / 新建对话时调用，刷新活跃时间 */
function conv_redis_touch_user_activity(int $userId): void
{
    $redis = redis_client();
    if (!$redis || $userId <= 0) {
        return;
    }
    $key = conv_storage_redis_user_active_key($userId);
    $redis->set($key, (string) time());
    $redis->expire($key, conv_redis_idle_seconds() + 86400);
}

/**
 * 超过空闲时长则删除该用户全部 Redis 对话缓存（JSON 文件不动）
 */
function conv_redis_purge_user_cache_if_idle(int $userId): void
{
    $redis = redis_client();
    if (!$redis || $userId <= 0) {
        return;
    }

    $activeKey = conv_storage_redis_user_active_key($userId);
    $last = $redis->get($activeKey);
    if ($last === false || $last === '') {
        return;
    }

    $lastTs = (int) $last;
    if ($lastTs <= 0 || (time() - $lastTs) < conv_redis_idle_seconds()) {
        return;
    }

    conv_redis_purge_user_cache($userId);
}

function conv_redis_purge_user_cache(int $userId): void
{
    $redis = redis_client();
    if (!$redis || $userId <= 0) {
        return;
    }

    $pattern = redis_key('u:' . $userId . ':*');
    $keys = $redis->keys($pattern);
    if (is_array($keys) && $keys !== []) {
        $redis->del($keys);
    }
}

function conv_storage_generate_id(int $userId): int
{
    $dir = conv_storage_user_dir($userId);
    $max = 0;
    if (is_dir($dir)) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $name = (int) pathinfo($file, PATHINFO_FILENAME);
            if ($name > $max) {
                $max = $name;
            }
        }
    }
    $redis = redis_client();
    if ($redis) {
        $listKey = conv_storage_redis_list_key($userId);
        $raw = $redis->get($listKey);
        if ($raw) {
            $items = json_decode($raw, true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $id = (int) ($item['id'] ?? 0);
                    if ($id > $max) {
                        $max = $id;
                    }
                }
            }
        }
    }
    $next = $max + 1;
    return $next > 0 ? $next : 1;
}

/**
 * @return array<string, mixed>|null
 */
function conv_storage_new_document(int $userId, int $modelId, string $title = '新对话', ?array $agentRef = null): array
{
    $now = date('Y-m-d H:i:s');
    $id = conv_storage_generate_id($userId);
    $doc = [
        'id'         => $id,
        'user_id'    => $userId,
        'model_id'   => $modelId > 0 ? $modelId : null,
        'title'      => $title !== '' ? $title : '新对话',
        'created_at' => $now,
        'updated_at' => $now,
        'messages'   => [],
    ];
    if ($agentRef && !empty($agentRef['type']) && !empty($agentRef['id'])) {
        $doc['agent_type'] = (string) $agentRef['type'];
        $doc['agent_id'] = (int) $agentRef['id'];
    }
    return $doc;
}

/**
 * @param array<string, mixed> $doc
 */
function conv_storage_save_document(array $doc, bool $persistNow = false): void
{
    $userId = (int) ($doc['user_id'] ?? 0);
    $convId = (int) ($doc['id'] ?? 0);
    if ($userId <= 0 || $convId <= 0) {
        return;
    }

    $doc['updated_at'] = date('Y-m-d H:i:s');
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    $redis = redis_client();
    if ($redis) {
        $ttl = (int) CONV_REDIS_TTL;
        $redis->setex(conv_storage_redis_doc_key($userId, $convId), $ttl > 0 ? $ttl : 2592000, $json);
        conv_storage_refresh_list_cache($userId, $doc);
    }

    conv_storage_persist_to_disk($userId, $convId, $json);
}

function conv_storage_persist_to_disk(int $userId, int $convId, ?string $json = null): bool
{
    if ($json === null) {
        $doc = conv_storage_load_document($userId, $convId);
        if (!$doc) {
            return false;
        }
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
    }

    $dir = conv_storage_user_dir($userId);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $path = conv_storage_file_path($userId, $convId);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $path);
}

/**
 * @return array<string, mixed>|null
 */
function conv_storage_load_document(int $userId, int $convId): ?array
{
    if ($userId <= 0 || $convId <= 0) {
        return null;
    }

    conv_redis_purge_user_cache_if_idle($userId);

    $redis = redis_client();
    if ($redis) {
        $raw = $redis->get(conv_storage_redis_doc_key($userId, $convId));
        if ($raw) {
            $doc = json_decode($raw, true);
            if (is_array($doc) && conv_document_owned_by($doc, $userId)) {
                return $doc;
            }
        }
    }

    $path = conv_storage_file_path($userId, $convId);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $doc = json_decode($raw, true);
    if (!is_array($doc)) {
        return null;
    }

    if (!conv_document_owned_by($doc, $userId)) {
        return null;
    }

    if ($redis) {
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $ttl = (int) CONV_REDIS_TTL;
            $redis->setex(conv_storage_redis_doc_key($userId, $convId), $ttl > 0 ? $ttl : 2592000, $json);
        }
    }

    return $doc;
}

/**
 * @param array<string, mixed> $doc
 */
function conv_storage_refresh_list_cache(int $userId, array $doc): void
{
    $redis = redis_client();
    if (!$redis) {
        return;
    }

    $list = conv_storage_list_summaries($userId, 200);
    $id = (int) ($doc['id'] ?? 0);
    $found = false;
    foreach ($list as &$item) {
        if ((int) ($item['id'] ?? 0) === $id) {
            $item = conv_storage_summary_from_doc($doc);
            $found = true;
            break;
        }
    }
    unset($item);
    if (!$found) {
        $list[] = conv_storage_summary_from_doc($doc);
    }

    usort($list, static function ($a, $b) {
        return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
    });

    $ttl = (int) CONV_REDIS_TTL;
    $redis->setex(
        conv_storage_redis_list_key($userId),
        $ttl > 0 ? $ttl : 2592000,
        json_encode($list, JSON_UNESCAPED_UNICODE)
    );
}

/**
 * @return array<int, array<string, mixed>>
 */
function conv_storage_summary_from_doc(array $doc): array
{
    $messages = $doc['messages'] ?? [];
    $messageCount = is_array($messages) ? count($messages) : 0;

    return [
        'id'            => (int) ($doc['id'] ?? 0),
        'title'         => (string) ($doc['title'] ?? '新对话'),
        'model_id'      => $doc['model_id'] ?? null,
        'agent_type'    => isset($doc['agent_type']) ? (string) $doc['agent_type'] : null,
        'agent_id'      => isset($doc['agent_id']) ? (int) $doc['agent_id'] : null,
        'updated_at'    => (string) ($doc['updated_at'] ?? ''),
        'created_at'    => (string) ($doc['created_at'] ?? ''),
        'message_count' => $messageCount,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function conv_storage_list_summaries(int $userId, int $limit = 40): array
{
    $byId = [];

    conv_redis_purge_user_cache_if_idle($userId);

    $redis = redis_client();
    if ($redis) {
        $raw = $redis->get(conv_storage_redis_list_key($userId));
        if ($raw) {
            $items = json_decode($raw, true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $id = (int) ($item['id'] ?? 0);
                    if ($id > 0) {
                        $byId[$id] = $item;
                    }
                }
            }
        }
    }

    $dir = conv_storage_user_dir($userId);
    if (is_dir($dir)) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $id = (int) pathinfo($file, PATHINFO_FILENAME);
            if ($id <= 0 || isset($byId[$id])) {
                continue;
            }
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $doc = json_decode($raw, true);
            if (is_array($doc)) {
                $byId[$id] = conv_storage_summary_from_doc($doc);
            }
        }
    }

    $list = array_values($byId);
    usort($list, static function ($a, $b) {
        return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
    });

    if ($limit > 0 && count($list) > $limit) {
        $list = array_slice($list, 0, $limit);
    }

    $clean = [];
    foreach ($list as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int) ($item['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $doc = conv_storage_load_document($userId, $id);
        if (!$doc) {
            continue;
        }
        $clean[] = conv_storage_summary_from_doc($doc);
    }

    return $clean;
}

function conv_storage_delete(int $userId, int $convId): bool
{
    $exists = conv_storage_load_document($userId, $convId) !== null;

    $redis = redis_client();
    if ($redis) {
        $redis->del(conv_storage_redis_doc_key($userId, $convId));
        $raw = $redis->get(conv_storage_redis_list_key($userId));
        if ($raw) {
            $items = json_decode($raw, true);
            if (is_array($items)) {
                $items = array_values(array_filter($items, static function ($item) use ($convId) {
                    return (int) ($item['id'] ?? 0) !== $convId;
                }));
                $ttl = (int) CONV_REDIS_TTL;
                $redis->setex(
                    conv_storage_redis_list_key($userId),
                    $ttl > 0 ? $ttl : 2592000,
                    json_encode($items, JSON_UNESCAPED_UNICODE)
                );
            }
        }
    }

    $path = conv_storage_file_path($userId, $convId);
    if (is_file($path)) {
        @unlink($path);
        $exists = true;
    }

    return $exists;
}

/**
 * 从旧 MySQL 迁移到 Redis+文件（一次性）
 */
function conv_storage_import_from_mysql(int $userId): int
{
    require_once __DIR__ . '/db.php';
    $count = 0;
    $stmt = db()->prepare(
        'SELECT id, user_id, model_id, title, created_at, updated_at FROM conversations WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll() ?: [];

    foreach ($rows as $row) {
        $convId = (int) $row['id'];
        $doc = [
            'id'         => $convId,
            'user_id'    => $userId,
            'model_id'   => $row['model_id'] ?? null,
            'title'      => (string) ($row['title'] ?? '新对话'),
            'created_at' => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => (string) ($row['updated_at'] ?? date('Y-m-d H:i:s')),
            'messages'   => [],
        ];
        $m = db()->prepare(
            'SELECT role, content FROM conversation_messages WHERE conversation_id = ? ORDER BY id ASC'
        );
        $m->execute([$convId]);
        foreach ($m->fetchAll() ?: [] as $msg) {
            $doc['messages'][] = [
                'role'    => (string) $msg['role'],
                'content' => (string) $msg['content'],
            ];
        }
        conv_storage_save_document($doc, true);
        $count++;
    }

    return $count;
}
