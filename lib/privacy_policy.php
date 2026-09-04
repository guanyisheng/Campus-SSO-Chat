<?php
declare(strict_types=1);

/** 隐私政策 HTML（来自 includes/yszx.html） */
function privacy_policy_html(string $base): string
{
    $path = dirname(__DIR__) . '/includes/yszx.html';
    if (!is_readable($path)) {
        return '<p>隐私政策暂未配置。</p>';
    }

    $html = (string) file_get_contents($path);
    $base = rtrim($base, '/');
    $logoMain = htmlspecialchars($base . '/logo.webp', ENT_QUOTES, 'UTF-8');
    $logoPartner = htmlspecialchars($base . '/' . rawurlencode('透明ai.png'), ENT_QUOTES, 'UTF-8');

    $html = str_replace('/logo.webp', $logoMain, $html);
    $html = str_replace('/%E9%80%8F%E6%98%8Eai.png', $logoPartner, $html);

    return $html;
}

function privacy_policy_url(string $base): string
{
    return rtrim($base, '/') . '/privacy.php';
}
