<?php
declare(strict_types=1);

require_once __DIR__ . '/conv_storage.php';
require_once __DIR__ . '/models.php';

/** 首条往返后由 AI 生成简短标题（仅当标题仍为「新对话」时） */
function conv_summarize_title(int $convId, int $userId): ?string
{
    $doc = conv_storage_load_document($userId, $convId);
    if (!$doc) {
        return null;
    }

    $current = trim((string) ($doc['title'] ?? ''));
    if ($current !== '' && $current !== '新对话') {
        return null;
    }

    $userText = '';
    $assistantText = '';
    foreach ($doc['messages'] ?? [] as $m) {
        if (!is_array($m)) {
            continue;
        }
        $role = (string) ($m['role'] ?? '');
        $content = conv_title_snippet((string) ($m['content'] ?? ''));
        if ($content === '') {
            continue;
        }
        if ($role === 'user' && $userText === '') {
            $userText = $content;
        } elseif ($role === 'assistant' && $assistantText === '') {
            $assistantText = $content;
        }
        if ($userText !== '' && $assistantText !== '') {
            break;
        }
    }

    if ($userText === '') {
        return null;
    }

    $modelId = (int) ($doc['model_id'] ?? 0);
    $llm = $modelId > 0 ? model_get($modelId, true) : null;
    if (!$llm) {
        $enabled = models_list_enabled();
        $llm = $enabled[0] ?? null;
    }

    $title = '';
    if ($llm) {
        try {
            $title = conv_request_title_llm($llm, $userText, $assistantText);
        } catch (Throwable $e) {
            $title = '';
        }
    }
    if ($title === '') {
        $title = conv_fallback_title_text($userText);
    }

    $title = mb_substr(trim($title), 0, 40) ?: '新对话';
    $doc['title'] = $title;
    conv_storage_save_document($doc, false);

    return $title;
}

function conv_title_snippet(string $content): string
{
    $t = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', '[图片]', $content) ?? $content;
    $t = preg_replace('/\[(?:🎬\s*)?生成视频\]\([^)]+\)/u', '[视频]', $t) ?? $t;
    $t = preg_replace('/\s+/u', ' ', trim($t)) ?? trim($t);

    return mb_substr($t, 0, 320);
}

function conv_fallback_title_text(string $userText): string
{
    $t = preg_replace('/^@\S+\s*/u', '', $userText) ?? $userText;
    $t = trim($t);
    if ($t === '') {
        return '新对话';
    }

    return mb_substr($t, 0, 16);
}

/** @param array<string, mixed> $llm */
function conv_request_title_llm(array $llm, string $userText, string $assistantText): string
{
    $sys = '你是标题生成器。根据对话内容生成一条不超过12个汉字的中文标题，概括主题。只输出标题本身，不要引号、标点或任何解释。';
    $user = '用户：' . $userText;
    if ($assistantText !== '') {
        $user .= "\n助手：" . mb_substr($assistantText, 0, 160);
    }

    $baseUrl = rtrim((string) ($llm['base_url'] ?? ''), '/');
    $payload = [
        'model'       => (string) $llm['model_name'],
        'messages'    => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
        'stream'      => false,
        'max_tokens'  => 32,
        'temperature' => 0.35,
    ];

    $apiKey = (string) ($llm['api_key'] ?? '');
    $timeout = min(30, defined('OLLAMA_TIMEOUT') ? (int) OLLAMA_TIMEOUT : 60);
    $json = conv_title_http_json($baseUrl . '/chat/completions', $payload, $apiKey, $timeout);

    $raw = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
    $raw = trim($raw, " \t\n\r\0\x0B\"'「」『』《》[]【】");

    return mb_substr($raw, 0, 20);
}

/** @param array<string, mixed> $payload @return array<string, mixed> */
function conv_title_http_json(string $url, array $payload, string $apiKey, int $timeout): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('标题生成请求失败: ' . $err);
    }

    $json = json_decode($raw, true);
    if ($code >= 400) {
        throw new RuntimeException('标题生成失败 (' . $code . ')');
    }
    if (!is_array($json)) {
        throw new RuntimeException('标题生成响应无效');
    }

    return $json;
}
