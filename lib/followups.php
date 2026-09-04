<?php
declare(strict_types=1);

require_once __DIR__ . '/conv_title.php';
require_once __DIR__ . '/models.php';

/** @return list<string> */
function followups_generate(array $llm, string $userText, string $assistantText): array
{
    $userText = conv_title_snippet($userText);
    $assistantText = conv_title_snippet($assistantText);
    if ($userText === '' || $assistantText === '') {
        return [];
    }

    $sys = '你是追问建议生成器。根据最后一轮问答，生成3到4条用户可能继续追问的简短中文问题。'
        . '每行一个问题，不要编号、不要引号、不要解释、不要空行。';
    $user = "用户：{$userText}\n\n助手：" . mb_substr($assistantText, 0, 600);

    $baseUrl = rtrim((string) ($llm['base_url'] ?? ''), '/');
    $payload = [
        'model'       => (string) $llm['model_name'],
        'messages'    => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
        'stream'      => false,
        'max_tokens'  => 160,
        'temperature' => 0.7,
    ];

    $apiKey = (string) ($llm['api_key'] ?? '');
    $timeout = min(25, defined('OLLAMA_TIMEOUT') ? (int) OLLAMA_TIMEOUT : 60);
    $json = conv_title_http_json($baseUrl . '/chat/completions', $payload, $apiKey, $timeout);
    $raw = trim((string) ($json['choices'][0]['message']['content'] ?? ''));

    return followups_parse_lines($raw);
}

/** @return list<string> */
function followups_parse_lines(string $raw): array
{
    $lines = preg_split('/\r\n|\r|\n/u', $raw) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        $line = preg_replace('/^\d+[\.\)、]\s*/u', '', $line) ?? $line;
        $line = trim($line, " \t\"'「」『』[]【】-*•");
        if ($line === '' || mb_strlen($line) < 4) {
            continue;
        }
        if (mb_strlen($line) > 80) {
            $line = mb_substr($line, 0, 80);
        }
        if (!in_array($line, $out, true)) {
            $out[] = $line;
        }
        if (count($out) >= 4) {
            break;
        }
    }

    return $out;
}
