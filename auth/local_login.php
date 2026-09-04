<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/user.php';

$base = rtrim(SITE_URL, '/');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $base . '/login.php');
    exit;
}

try {
    $uid = $_POST['campus_uid'] ?? '';
    $pass = $_POST['password'] ?? '';
    $user = authenticate_local_user($uid, $pass);
    login_user(session_from_db_user($user));
    header('Location: ' . $base . '/chat.php');
    exit;
} catch (Throwable $e) {
    header('Location: ' . $base . '/login.php?error=' . urlencode($e->getMessage()));
    exit;
}
