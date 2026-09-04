<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/session.php';

logout_user();

if (SSO_LOGOUT_URL !== '') {
    $params = http_build_query([
        'post_logout_redirect_uri' => rtrim(SITE_URL, '/') . '/index.php',
    ]);
    header('Location: ' . SSO_LOGOUT_URL . '?' . $params);
    exit;
}

header('Location: ' . rtrim(SITE_URL, '/') . '/index.php');
exit;
