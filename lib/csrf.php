<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';

function csrf_token(): string
{
    app_session_start();
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_meta_tag(): string
{
    $token = csrf_token();

    return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_field(): string
{
    $token = csrf_token();

    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_token_from_request(): ?string
{
    if (isset($_POST['_csrf']) && is_string($_POST['_csrf'])) {
        return $_POST['_csrf'];
    }

    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($header) && $header !== '') {
        return $header;
    }

    return null;
}

function csrf_verify(?string $token): bool
{
    if ($token === null || $token === '') {
        return false;
    }

    app_session_start();
    $expected = $_SESSION['_csrf_token'] ?? '';
    if (!is_string($expected) || $expected === '') {
        return false;
    }

    return hash_equals($expected, $token);
}

function csrf_fail(): void
{
    if (function_exists('api_is_request') && api_is_request()) {
        api_json_error(403, 'CSRF 验证失败');
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CSRF 验证失败';
    exit;
}

function csrf_require(): void
{
    if (!csrf_verify(csrf_token_from_request())) {
        csrf_fail();
    }
}
