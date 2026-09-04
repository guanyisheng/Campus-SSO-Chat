<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

function content_policy_enabled(): bool
{
    return setting_bool('content_policy_enabled', false);
}

function content_policy_refusal_message(): string
{
    $custom = trim(setting('content_policy_refusal', ''));
    if ($custom !== '') {
        return $custom;
    }
    return '抱歉，您的请求涉及敏感或违规内容（如反党、反社会、暴力色情等），我无法为您提供帮助。请修改后重试。';
}

/** 注入对话模型的系统提示词 */
function content_policy_system_prompt(): string
{
    $base = '你是校园智聊助手。对于学习、课程、科研、校园生活、编程、办公、常识问答等正常合法问题，'
        . '应积极、准确、完整地回答，不要无故拒绝。'
        . '仅在用户明确请求违法违规内容（如煽动颠覆政权、分裂国家、暴力恐怖、色情低俗、造谣诽谤、'
        . '教唆犯罪等）时，才礼貌、简要地拒绝并说明无法协助。'
        . '不要因为问题表述模糊、涉及一般性敏感话题讨论，或用户使用了某些字眼，就过度审查或回复「我无法…」。'
        . '若问题合法但信息不足，可正常作答或礼貌请用户补充说明。';

    $extra = trim(setting('content_policy_system_extra', ''));
    if ($extra !== '') {
        $base .= "\n\n" . $extra;
    }

    return $base;
}

/** @return list<string> */
function content_policy_sensitive_words(): array
{
    $raw = trim(setting('content_sensitive_words', ''));
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[\r\n,，;；|]+/u', $raw) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $word = trim($part);
        if ($word !== '' && mb_strlen($word) >= 1) {
            $out[] = $word;
        }
    }

    return array_values(array_unique($out));
}

function content_policy_default_sensitive_words_raw(): string
{
    return implode("\n", [
        '反党',
        '反社会',
        '颠覆国家',
        '分裂国家',
        '推翻政府',
        '恐怖活动',
        '制作炸弹',
        '制毒',
    ]);
}

/** 检测文本是否命中敏感词，命中则返回该词 */
function content_policy_match(string $text): ?string
{
    if (!content_policy_enabled()) {
        return null;
    }
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    $lower = mb_strtolower($text);
    foreach (content_policy_sensitive_words() as $word) {
        $w = mb_strtolower($word);
        if ($w !== '' && mb_strpos($lower, $w) !== false) {
            return $word;
        }
    }
    return null;
}

/**
 * 校验用户内容，违规则抛出 InvalidArgumentException
 */
function content_policy_assert_safe(string $text): void
{
    $hit = content_policy_match($text);
    if ($hit !== null) {
        throw new InvalidArgumentException(content_policy_refusal_message());
    }
}

/** @param list<array{role:string,content:string}> $messages */
function content_policy_inject_system(array $messages): array
{
    if (!content_policy_enabled()) {
        return $messages;
    }

    $systemPrompt = content_policy_system_prompt();
    $hasSystem = false;
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'system') {
            $hasSystem = true;
            break;
        }
    }

    if ($hasSystem) {
        $out = [];
        $injected = false;
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system' && !$injected) {
                $content = trim((string) ($m['content'] ?? ''));
                $out[] = [
                    'role'    => 'system',
                    'content' => $content !== '' ? ($content . "\n\n" . $systemPrompt) : $systemPrompt,
                ];
                $injected = true;
            } else {
                $out[] = $m;
            }
        }
        if (!$injected) {
            array_unshift($out, ['role' => 'system', 'content' => $systemPrompt]);
        }
        return $out;
    }

    return array_merge(
        [['role' => 'system', 'content' => $systemPrompt]],
        $messages
    );
}

function content_policy_stream_refusal(int $convId, int $userId, string $message): void
{
    require_once __DIR__ . '/conversations.php';

    if (function_exists('api_json_discard_buffer')) {
        api_json_discard_buffer();
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    echo 'data: ' . json_encode(['conversation_id' => $convId], JSON_UNESCAPED_UNICODE) . "\n\n";
    echo 'data: ' . json_encode(['content' => $message], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();

    conv_add_message($convId, $userId, 'assistant', $message);
    echo "data: [DONE]\n\n";
    flush();
}
