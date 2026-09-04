<?php
declare(strict_types=1);

/** 将思考过程与正文打包存储（assistant 消息 content 字段） */
function assistant_content_pack(string $content, string $reasoning = ''): string
{
    $content = trim($content);
    $reasoning = trim($reasoning);
    if ($reasoning === '') {
        return $content;
    }
    $b64 = rtrim(strtr(base64_encode($reasoning), '+/', '-_'), '=');

    return '<!--reasoning:' . $b64 . "-->\n" . $content;
}

/** @return array{reasoning:string,content:string} */
function assistant_content_unpack(string $raw): array
{
    $raw = (string) $raw;
    if (!preg_match('/^<!--reasoning:([A-Za-z0-9+\/_=-]+)-->\n?/s', $raw, $m)) {
        return ['reasoning' => '', 'content' => $raw];
    }
    $b64 = strtr($m[1], '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad > 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $reasoning = base64_decode($b64, true);
    $content = substr($raw, strlen($m[0]));

    return [
        'reasoning' => is_string($reasoning) ? $reasoning : '',
        'content'   => $content,
    ];
}

/** 发给模型前剥离思考块，只保留正文 */
function assistant_content_for_model(string $raw): string
{
    return assistant_content_unpack($raw)['content'];
}
