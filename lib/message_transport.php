<?php
declare(strict_types=1);

/**
 * 对话消息传输编解码 — 避免 HTML/代码中的 <> 等被 WAF 误拦
 */

function message_transport_decode_content(string $content, string $encoding = ''): string
{
    if ($encoding !== 'base64') {
        return $content;
    }

    $decoded = base64_decode($content, true);
    if ($decoded === false) {
        return $content;
    }

    return $decoded;
}

/** @param array<string, mixed> $message @return array{role:string,content:string} */
function message_transport_normalize_row(array $message): array
{
    $role = (string) ($message['role'] ?? '');
    $content = message_transport_decode_content(
        (string) ($message['content'] ?? ''),
        (string) ($message['content_encoding'] ?? '')
    );
    $content = trim($content);

    return [
        'role'    => $role,
        'content' => $content,
    ];
}

/** @param list<array<string, mixed>> $messages @return list<array{role:string,content:string}> */
function message_transport_decode_messages(array $messages): array
{
    $out = [];
    foreach ($messages as $m) {
        if (!is_array($m)) {
            continue;
        }
        $row = message_transport_normalize_row($m);
        if (!in_array($row['role'], ['system', 'user', 'assistant'], true) || $row['content'] === '') {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}
