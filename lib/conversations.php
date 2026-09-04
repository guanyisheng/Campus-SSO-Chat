<?php
declare(strict_types=1);

require_once __DIR__ . '/conv_storage.php';
require_once __DIR__ . '/models.php';

function conv_list_for_user(int $userId, int $limit = 40): array
{
    $list = conv_storage_list_summaries($userId, $limit);
    $list = array_values(array_filter($list, static function (array $row): bool {
        return empty($row['agent_type']) || empty($row['agent_id']);
    }));
    foreach ($list as &$row) {
        $modelId = (int) ($row['model_id'] ?? 0);
        $row['model_name'] = '';
        if ($modelId > 0) {
            $m = model_get($modelId, true);
            if ($m) {
                $row['model_name'] = (string) ($m['display_name'] ?? $m['model_name'] ?? '');
            }
        }
    }
    unset($row);
    return $list;
}

/** 是否为智能体专属对话 */
function conv_is_agent_row(array $row): bool
{
    return !empty($row['agent_type']) && !empty($row['agent_id']);
}

/**
 * 查找用户与某智能体绑定的唯一对话（一个智能体对应一个聊天页）
 *
 * @param array{type:string,id:int} $agentRef
 * @return array<string, mixed>|null
 */
function conv_find_for_agent(int $userId, array $agentRef): ?array
{
    $type = (string) ($agentRef['type'] ?? '');
    $agentId = (int) ($agentRef['id'] ?? 0);
    if ($agentId <= 0 || !in_array($type, ['preset', 'user'], true)) {
        return null;
    }

    $list = conv_storage_list_summaries($userId, 200);
    foreach ($list as $row) {
        if (($row['agent_type'] ?? '') !== $type || (int) ($row['agent_id'] ?? 0) !== $agentId) {
            continue;
        }
        $conv = conv_get_for_user((int) ($row['id'] ?? 0), $userId);
        if ($conv) {
            return $conv;
        }
    }

    return null;
}

function conv_get_for_user(int $convId, int $userId): ?array
{
    $doc = conv_storage_load_document($userId, $convId);
    if (!$doc || !conv_document_owned_by($doc, $userId)) {
        return null;
    }
    return [
        'id'         => (int) $doc['id'],
        'user_id'    => (int) $doc['user_id'],
        'model_id'   => $doc['model_id'],
        'agent_type' => isset($doc['agent_type']) ? (string) $doc['agent_type'] : null,
        'agent_id'   => isset($doc['agent_id']) ? (int) $doc['agent_id'] : null,
        'title'      => (string) $doc['title'],
        'created_at' => (string) ($doc['created_at'] ?? ''),
        'updated_at' => (string) ($doc['updated_at'] ?? ''),
    ];
}

/** @return array<string, mixed> */
function conv_require_for_user(int $convId, int $userId): array
{
    $conv = conv_get_for_user($convId, $userId);
    if (!$conv) {
        throw new InvalidArgumentException('对话不存在');
    }

    return $conv;
}

function conv_create(int $userId, int $modelId, string $title = '新对话', ?array $agentRef = null): int
{
    conv_redis_touch_user_activity($userId);
    $doc = conv_storage_new_document($userId, $modelId, $title, $agentRef);
    conv_storage_save_document($doc, true);
    return (int) $doc['id'];
}

/** 是否为空对话（无消息，可复用为「新对话」） */
function conv_is_empty_summary(array $row): bool
{
    return isset($row['message_count']) && (int) $row['message_count'] === 0;
}

/**
 * 查找可复用的空对话（同一用户、按 model 优先，updated_at 最新在前）
 *
 * @return array<string, mixed>|null
 */
function conv_find_reusable_empty(int $userId, int $modelId = 0): ?array
{
    $list = conv_list_for_user($userId, 50);
    if ($modelId > 0) {
        foreach ($list as $row) {
            if (!conv_is_empty_summary($row) || (int) ($row['model_id'] ?? 0) !== $modelId) {
                continue;
            }
            if (conv_get_for_user((int) ($row['id'] ?? 0), $userId)) {
                return $row;
            }
        }
    }
    foreach ($list as $row) {
        if (!conv_is_empty_summary($row)) {
            continue;
        }
        if (conv_get_for_user((int) ($row['id'] ?? 0), $userId)) {
            return $row;
        }
    }

    return null;
}

function conv_delete(int $convId, int $userId): bool
{
    return conv_storage_delete($userId, $convId);
}

function conv_touch(int $convId, int $userId): void
{
    $doc = conv_storage_load_document($userId, $convId);
    if (!$doc) {
        return;
    }
    conv_storage_save_document($doc, false);
}

function conv_update_agent(int $convId, int $userId, ?array $agentRef): bool
{
    $doc = conv_storage_load_document($userId, $convId);
    if (!$doc) {
        return false;
    }
    if ($agentRef && !empty($agentRef['type']) && !empty($agentRef['id'])) {
        $doc['agent_type'] = (string) $agentRef['type'];
        $doc['agent_id'] = (int) $agentRef['id'];
    } else {
        unset($doc['agent_type'], $doc['agent_id']);
    }
    conv_storage_save_document($doc, false);
    return true;
}

function conv_update_model(int $convId, int $userId, int $modelId): bool
{
    if ($modelId <= 0) {
        return false;
    }
    $doc = conv_storage_load_document($userId, $convId);
    if (!$doc) {
        return false;
    }
    $doc['model_id'] = $modelId;
    conv_storage_save_document($doc, false);
    return true;
}

function conv_set_title(int $convId, int $userId, string $title): void
{
    $doc = conv_storage_load_document($userId, $convId);
    if (!$doc) {
        return;
    }
    $doc['title'] = mb_substr(trim($title), 0, 200) ?: '新对话';
    conv_storage_save_document($doc, false);
}

function conv_clear_messages(int $convId, int $userId, ?string $title = null): bool
{
    $doc = conv_storage_load_document($userId, $convId);
    if (!$doc) {
        return false;
    }
    $doc['messages'] = [];
    if ($title !== null && trim($title) !== '') {
        $doc['title'] = mb_substr(trim($title), 0, 200);
    }
    conv_storage_save_document($doc, false);
    conv_touch($convId, $userId);

    return true;
}

function conv_messages(int $convId, int $userId): array
{
    $doc = conv_storage_load_document($userId, $convId);
    if (!$doc || !isset($doc['messages']) || !is_array($doc['messages'])) {
        return [];
    }
    $out = [];
    foreach ($doc['messages'] as $m) {
        if (!is_array($m)) {
            continue;
        }
        $role = (string) ($m['role'] ?? '');
        if (!in_array($role, ['user', 'assistant', 'system'], true)) {
            continue;
        }
        $out[] = [
            'role'    => $role,
            'content' => (string) ($m['content'] ?? ''),
        ];
    }
    return $out;
}

/** 将匹配的 assistant 占位消息替换为最终内容（自后向前查找） */
function conv_replace_assistant_pending(int $convId, int $userId, string $newContent, callable $predicate): bool
{
    $newContent = trim($newContent);
    if ($newContent === '') {
        return false;
    }
    $doc = conv_storage_load_document($userId, $convId);
    if (!$doc || !isset($doc['messages']) || !is_array($doc['messages'])) {
        return false;
    }
    for ($i = count($doc['messages']) - 1; $i >= 0; $i--) {
        $msg = $doc['messages'][$i];
        if (!is_array($msg) || ($msg['role'] ?? '') !== 'assistant') {
            continue;
        }
        $content = (string) ($msg['content'] ?? '');
        if ($predicate($content)) {
            $doc['messages'][$i]['content'] = $newContent;
            conv_storage_save_document($doc, false);
            return true;
        }
    }
    return false;
}

/** 将最后一条「视频生成中」占位 assistant 消息替换为最终内容，避免重复两条回复 */
function conv_replace_video_pending(int $convId, int $userId, string $newContent): bool
{
    require_once __DIR__ . '/media.php';
    return conv_replace_assistant_pending($convId, $userId, $newContent, static function (string $content): bool {
        return media_parse_video_pending($content) !== null;
    });
}

function conv_replace_queue_pending(int $convId, int $userId, string $newContent): bool
{
    require_once __DIR__ . '/media.php';
    return conv_replace_assistant_pending($convId, $userId, $newContent, static function (string $content): bool {
        return media_parse_queue_pending($content) !== null;
    });
}

function conv_replace_text_pending(int $convId, int $userId, string $newContent): bool
{
    require_once __DIR__ . '/media.php';
    return conv_replace_assistant_pending($convId, $userId, $newContent, static function (string $content): bool {
        return media_parse_text_pending($content);
    });
}

/** 移除最后一条匹配的 assistant 占位消息 */
function conv_remove_assistant_pending(int $convId, int $userId, callable $predicate): bool
{
    $doc = conv_storage_load_document($userId, $convId);
    if (!$doc || !isset($doc['messages']) || !is_array($doc['messages'])) {
        return false;
    }
    for ($i = count($doc['messages']) - 1; $i >= 0; $i--) {
        $msg = $doc['messages'][$i];
        if (!is_array($msg) || ($msg['role'] ?? '') !== 'assistant') {
            continue;
        }
        if ($predicate((string) ($msg['content'] ?? ''))) {
            array_splice($doc['messages'], $i, 1);
            conv_storage_save_document($doc, false);
            return true;
        }
    }
    return false;
}

/** 取消文本生成占位：有 partial 则写入，否则删除占位 assistant */
function conv_cancel_text_pending(int $convId, int $userId, string $partialContent = ''): bool
{
    require_once __DIR__ . '/media.php';
    $partialContent = trim($partialContent);
    if ($partialContent !== '') {
        return conv_replace_text_pending($convId, $userId, $partialContent);
    }
    return conv_remove_assistant_pending($convId, $userId, static function (string $content): bool {
        return media_parse_text_pending($content);
    });
}

/** 入队后立即写入用户消息与排队占位，刷新页面可恢复「生成中」状态 */
function conv_save_media_queue_pending(
    int $convId,
    int $userId,
    string $jobType,
    string $userDisplay,
    string $prompt,
    int $queueId,
    int $providerId
): string {
    require_once __DIR__ . '/media.php';
    conv_maybe_set_title_from_message($convId, $userId, $prompt);

    $msgs = conv_messages($convId, $userId);
    $last = $msgs !== [] ? $msgs[count($msgs) - 1] : null;
    if (!is_array($last) || ($last['role'] ?? '') !== 'user' || (string) ($last['content'] ?? '') !== $userDisplay) {
        conv_add_message($convId, $userId, 'user', $userDisplay);
    }

    $pending = media_queue_pending_marker($queueId, $jobType, $prompt, $providerId);
    $last = null;
    $msgs = conv_messages($convId, $userId);
    if ($msgs !== []) {
        $last = $msgs[count($msgs) - 1];
    }
    if (is_array($last) && ($last['role'] ?? '') === 'assistant') {
        $existing = media_parse_queue_pending((string) ($last['content'] ?? ''));
        if ($existing !== null && (int) ($existing['queue_id'] ?? 0) === $queueId) {
            return (string) $last['content'];
        }
    }

    conv_add_message($convId, $userId, 'assistant', $pending);
    return $pending;
}

function conv_add_message(int $convId, int $userId, string $role, string $content): void
{
    if (!in_array($role, ['user', 'assistant', 'system'], true)) {
        return;
    }
    $content = trim($content);
    if ($content === '') {
        return;
    }
    if ($role === 'user') {
        conv_redis_touch_user_activity($userId);
    }
    $doc = conv_storage_load_document($userId, $convId);
    if (!$doc) {
        return;
    }
    if (!isset($doc['messages']) || !is_array($doc['messages'])) {
        $doc['messages'] = [];
    }
    $doc['messages'][] = [
        'role'    => $role,
        'content' => $content,
    ];
    conv_storage_save_document($doc, false);
}

function conv_maybe_set_title_from_message(int $convId, int $userId, string $userText): void
{
    // 标题改由 conv_summarize_title() 在首条助手回复后生成
}
