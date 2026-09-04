<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';

admin_logout();
header('Location: ' . rtrim(SITE_URL, '/') . '/admin/index.php');
exit;
