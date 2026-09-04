<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/sso_consent.php';

app_session_start();

$base = rtrim(SITE_URL, '/');
$action = (string) ($_POST['action'] ?? '');

if (!current_user() || !sso_consent_pending()) {
    header('Location: ' . $base . '/login.php');
    exit;
}

if ($action === 'accept') {
    sso_consent_clear_pending();
    header('Location: ' . $base . '/chat.php');
    exit;
}

if ($action === 'decline') {
    sso_consent_clear_pending();
    logout_user();
    header('Location: ' . $base . '/login.php');
    exit;
}

header('Location: ' . $base . '/auth/authorize.php');
exit;
