<?php
declare(strict_types=1);

require_once __DIR__ . '/api_json.php';
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/user_api_keys.php';

/** 无 Key 时浏览器直接打开 → 返回 HTML 拒绝页 */
function v1_gateway_client_wants_html(): bool
{
    if (user_api_key_extract_bearer() !== '') {
        return false;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET' || $method === 'HEAD') {
        return true;
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if ($accept === '' || str_contains($accept, 'text/html')) {
        return true;
    }

    return false;
}

function v1_gateway_deny(int $status, string $title, string $detail = ''): never
{
    api_json_discard_buffer();
    http_response_code($status);

    if (v1_gateway_client_wants_html()) {
        v1_gateway_render_deny_html($status, $title, $detail);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'message' => $detail !== '' ? $detail : $title,
            'type'    => 'authentication_error',
            'code'    => $status,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function v1_gateway_render_deny_html(int $status, string $title, string $detail): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');

    $home = htmlspecialchars(rtrim(site_base_url(), '/') . '/chat.php', ENT_QUOTES, 'UTF-8');
    $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $detailEsc = htmlspecialchars($detail !== '' ? $detail : $title, ENT_QUOTES, 'UTF-8');
    $codeEsc = htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>{$codeEsc} {$titleEsc}</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif;
      background: #121018;
      color: #eceaf2;
    }
    .card {
      width: min(100%, 440px);
      padding: 28px 24px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,0.1);
      background: rgba(28, 24, 38, 0.96);
      box-shadow: 0 20px 60px rgba(0,0,0,0.35);
    }
    .code { font-size: 0.875rem; color: #b497cf; margin: 0 0 8px; }
    h1 { margin: 0 0 12px; font-size: 1.25rem; font-weight: 600; }
    p { margin: 0 0 16px; line-height: 1.6; color: #b8b3c7; font-size: 0.9375rem; }
    code {
      display: block;
      padding: 10px 12px;
      margin: 12px 0;
      border-radius: 10px;
      background: rgba(0,0,0,0.25);
      border: 1px solid rgba(255,255,255,0.08);
      font-size: 0.8125rem;
      word-break: break-all;
      color: #ddd6eb;
    }
    a {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 10px 16px;
      border-radius: 999px;
      background: #6d5a8f;
      color: #fff;
      text-decoration: none;
      font-size: 0.875rem;
    }
    a:hover { background: #7d6a9f; }
  </style>
</head>
<body>
  <div class="card">
    <p class="code">HTTP {$codeEsc}</p>
    <h1>{$titleEsc}</h1>
    <p>{$detailEsc}</p>
    <code>Authorization: Bearer cschat_你的Key</code>
    <p>请先在校园智聊登录，于个人中心生成 API Key 后再调用本接口。</p>
    <a href="{$home}">前往登录 / 个人中心</a>
  </div>
</body>
</html>
HTML;
    exit;
}

function v1_gateway_require_user_id(): int
{
    if (!user_api_keys_enabled()) {
        v1_gateway_deny(503, '服务不可用', '用户 API Key 功能已关闭');
    }

    $plain = user_api_key_extract_bearer();
    if ($plain === '') {
        v1_gateway_deny(
            401,
            '拒绝访问',
            '缺少 API Key。请在请求头携带 Authorization: Bearer <你的 Key>'
        );
    }

    $userId = user_api_key_verify($plain);
    if (!$userId) {
        v1_gateway_deny(401, '拒绝访问', 'API Key 无效或已撤销');
    }

    return $userId;
}

function v1_gateway_json_error(int $status, string $message): never
{
    api_json_discard_buffer();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'message' => $message,
            'type'    => 'invalid_request_error',
            'code'    => $status,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
