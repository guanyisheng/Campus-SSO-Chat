<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/user_groups.php';

require_admin();

$base = site_base_url() . '/admin/groups.php';
$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'default') {
        $gid = (int) ($_POST['default_user_group_id'] ?? 0);
        if ($gid <= 0 || !user_group_get($gid)) {
            throw new InvalidArgumentException('请选择有效的默认用户组');
        }
        setting_save_many(['default_user_group_id' => (string) $gid]);
        header('Location: ' . $base . '?saved=1');
        exit;
    }

    if ($action === 'delete') {
        user_group_delete((int) ($_POST['id'] ?? 0));
        header('Location: ' . $base . '?saved=1');
        exit;
    }

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        user_group_save(
            $id > 0 ? $id : null,
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['slug'] ?? ''),
            (int) ($_POST['daily_chat_limit'] ?? 0),
            (int) ($_POST['daily_image_limit'] ?? 0),
            (int) ($_POST['daily_video_limit'] ?? 0),
            isset($_POST['can_access_admin']),
            (int) ($_POST['sort_order'] ?? 0)
        );
        header('Location: ' . $base . '?saved=1');
        exit;
    }
} catch (Throwable $e) {
    header('Location: ' . $base . '?error=' . rawurlencode($e->getMessage()));
    exit;
}

header('Location: ' . $base);
exit;
