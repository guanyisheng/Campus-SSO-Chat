<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/agents.php';
require_once dirname(__DIR__) . '/lib/models.php';
require_once dirname(__DIR__) . '/lib/user.php';
require_once dirname(__DIR__) . '/lib/user_groups.php';

require_admin();
agents_fix_schema();

$base = site_base_url();
$action = (string) ($_POST['action'] ?? '');
$redirect = $base . '/admin/agents.php';

try {
    if ($action === 'create_preset' || $action === 'update_preset') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['display_name'] ?? ''));
        $prompt = trim((string) ($_POST['system_prompt'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $modelId = (int) ($_POST['model_id'] ?? 0);
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $enabled = !empty($_POST['is_enabled']);
        $avatarFile = '';
        $avatarWarn = '';

        if (!empty($_FILES['avatar']) && is_array($_FILES['avatar'])) {
            $uploadErr = (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadErr === UPLOAD_ERR_OK) {
                try {
                    $upload = agent_save_upload($_FILES['avatar'], 'preset_agent');
                    $avatarFile = $upload['filename'];
                } catch (InvalidArgumentException $e) {
                    $avatarWarn = $e->getMessage();
                }
            } elseif ($uploadErr !== UPLOAD_ERR_NO_FILE) {
                $avatarWarn = '头像上传失败（错误码 ' . $uploadErr . '）';
            }
        }

        if ($name === '' || $prompt === '') {
            header('Location: ' . $redirect . '?error=' . urlencode('名称与提示词不能为空'));
            exit;
        }

        if ($action === 'create_preset') {
            agent_preset_create($name, $prompt, $modelId, $description, $avatarFile, $sortOrder, $enabled);
        } else {
            if ($id <= 0 || !agent_preset_update($id, $name, $prompt, $modelId, $description, $avatarFile, $sortOrder, $enabled)) {
                header('Location: ' . $redirect . '?error=' . urlencode('预设不存在'));
                exit;
            }
        }

        $qs = 'saved=1';
        if ($avatarWarn !== '') {
            $qs .= '&warn=' . urlencode($avatarWarn);
        }
        header('Location: ' . $redirect . '?' . $qs);
        exit;
    }

    if ($action === 'delete_preset') {
        $id = (int) ($_POST['id'] ?? 0);
        agent_preset_delete($id);
        header('Location: ' . $redirect . '?saved=1');
        exit;
    }

    if ($action === 'assign_preset') {
        $presetId = (int) ($_POST['preset_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($presetId <= 0 || $userId <= 0) {
            header('Location: ' . $redirect . '?error=' . urlencode('请选择预设与用户'));
            exit;
        }
        if (!user_agent_assign_preset($userId, $presetId)) {
            header('Location: ' . $redirect . '?error=' . urlencode('分发失败'));
            exit;
        }
        header('Location: ' . $redirect . '?saved=1&assign=1');
        exit;
    }

    if ($action === 'unassign_preset') {
        $presetId = (int) ($_POST['preset_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);
        user_agent_unassign_preset($userId, $presetId);
        header('Location: ' . $redirect . '?saved=1');
        exit;
    }

    header('Location: ' . $redirect . '?error=' . urlencode('未知操作'));
} catch (Throwable $e) {
    header('Location: ' . $redirect . '?error=' . urlencode($e->getMessage()));
}
