<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/sso.php';

if (!setting_bool('enable_oidc_auth', true)) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/login.php?error=' . urlencode('统一认证已关闭'));
    exit;
}

header('Location: ' . sso_authorize_url());
exit;
