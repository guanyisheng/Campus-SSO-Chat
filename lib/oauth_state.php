<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/session.php';

/**
 * OAuth state：同时写入 Session + 签名 Cookie，避免跨站回调后 Session 丢失导致校验失败。
 */
function oauth_issue_state(): string
{
    app_session_start();
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $sig = hash_hmac('sha256', $state, SESSION_SECRET);
    setcookie('sso_oauth_state', $state . '.' . $sig, app_cookie_params(600));

    session_write_close();
    return $state;
}

function oauth_verify_state(string $returned): bool
{
    $returned = trim($returned);
    if ($returned === '') {
        return false;
    }

    app_session_start();

    $sessionState = $_SESSION['oauth_state'] ?? '';
    if ($sessionState !== '' && hash_equals($sessionState, $returned)) {
        oauth_clear_state();
        return true;
    }

    $packed = $_COOKIE['sso_oauth_state'] ?? '';
    if ($packed === '' || strpos($packed, '.') === false) {
        return false;
    }

    [$cookieState, $sig] = explode('.', $packed, 2);
    $expectedSig = hash_hmac('sha256', $cookieState, SESSION_SECRET);

    if (!hash_equals($cookieState, $returned) || !hash_equals($expectedSig, $sig)) {
        return false;
    }

    oauth_clear_state();
    return true;
}

function oauth_clear_state(): void
{
    unset($_SESSION['oauth_state']);
    setcookie('sso_oauth_state', '', app_cookie_params(0));
}

/** @return array<string, mixed> */
function app_cookie_params(int $lifetime): array
{
    $secure = app_is_https();
    $domain = app_cookie_domain();

    return [
        'expires'  => $lifetime > 0 ? time() + $lifetime : time() - 3600,
        'path'     => '/',
        'domain'   => $domain,
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}
