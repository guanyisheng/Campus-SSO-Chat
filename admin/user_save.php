<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/user_groups.php';

require_admin();

$userId = (int) ($_POST['user_id'] ?? 0);
$groupId = (int) ($_POST['group_id'] ?? 0);
$redirect = site_base_url() . '/admin/users.php';

if ($userId <= 0) {
    header('Location: ' . $redirect);
    exit;
}

if ($groupId <= 0) {
    db()->prepare('UPDATE users SET group_id = NULL WHERE id = ?')->execute([$userId]);
} else {
    user_group_assign($userId, $groupId);
}

header('Location: ' . $redirect . '?saved=1');
exit;
