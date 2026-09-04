<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';

function admin_login(string $username, string $password): bool
{
    if ($username !== ADMIN_USERNAME || $password !== ADMIN_PASSWORD) {
        return false;
    }
    app_session_start();
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    return true;
}

function admin_logout(): void
{
    app_session_start();
    unset($_SESSION['admin_logged_in']);
}

function is_admin(): bool
{
    app_session_start();
    if (!empty($_SESSION['admin_logged_in'])) {
        return true;
    }
    $user = current_user();
    if (!$user || empty($user['id'])) {
        return false;
    }
    require_once __DIR__ . '/user_groups.php';
    user_groups_fix_schema();
    return user_can_access_admin((int) $user['id']);
}

function require_admin(): void
{
    if (is_admin()) {
        return;
    }
    require_once __DIR__ . '/site.php';
    header('Location: ' . site_base_url() . '/admin/index.php');
    exit;
}
