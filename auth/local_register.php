<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/user.php';

$base = rtrim(SITE_URL, '/');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $base . '/register.php');
    exit;
}

try {
    $uid = $_POST['campus_uid'] ?? '';
    $pass = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';
    $name = $_POST['display_name'] ?? '';

    if ($pass !== $confirm) {
        throw new InvalidArgumentException('两次密码不一致');
    }

    $user = register_local_user($uid, $pass, $name);
    login_user(session_from_db_user($user));
    header('Location: ' . $base . '/chat.php');
    exit;
} catch (Throwable $e) {
    header('Location: ' . $base . '/register.php?error=' . urlencode($e->getMessage()));
    exit;
}
