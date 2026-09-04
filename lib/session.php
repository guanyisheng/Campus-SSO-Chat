<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('campus_sso_chat');

    $secure = app_is_https();
    $domain = app_cookie_domain();

    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'domain'   => $domain,
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (defined('SESSION_SECRET') && SESSION_SECRET !== '' && SESSION_SECRET !== 'CHANGE_ME_TO_RANDOM_64_CHARS') {
        ini_set('session.sid_length', '48');
    }

    session_start();
}

function app_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    if (strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') {
        return true;
    }
    return strncmp(SITE_URL, 'https://', 8) === 0;
}

function app_cookie_domain(): string
{
    $host = parse_url(SITE_URL, PHP_URL_HOST) ?: '';
    if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        return '';
    }
    return $host;
}

function current_user(): ?array
{
    app_session_start();
    return $_SESSION['user'] ?? null;
}

function refresh_current_user_from_db(): ?array
{
    app_session_start();
    $u = $_SESSION['user'] ?? null;
    if (!$u || empty($u['campus_uid'])) {
        return $u;
    }
    require_once __DIR__ . '/user_groups.php';
    user_groups_fix_schema();
    require_once __DIR__ . '/user.php';
    $row = fetch_user_by_uid((string) $u['campus_uid'], false);
    if ($row) {
        $_SESSION['user'] = session_from_db_user($row);
    }
    return $_SESSION['user'] ?? null;
}

function login_user(array $user): void
{
    app_session_start();
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
}

function logout_user(): void
{
    app_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'] ?? '/',
            'domain'   => $p['domain'] ?? '',
            'secure'   => $p['secure'] ?? false,
            'httponly' => $p['httponly'] ?? true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function require_login(): void
{
    if (current_user()) {
        return;
    }
    if (ALLOW_GUEST) {
        return;
    }
    if (function_exists('api_is_request') && api_is_request()) {
        api_json_error(401, '未登录');
    }
    header('Location: ' . site_base_url() . '/index.php');
    exit;
}
