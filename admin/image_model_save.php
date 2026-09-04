<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/image_models.php';
require_once dirname(__DIR__) . '/lib/comfyui.php';

require_admin();
image_models_ensure_ready();

$base = site_base_url();
$redirect = $base . '/admin/image_models.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = image_models_get($id);
        if ($row) {
            image_models_update_enabled($id, !((int) $row['is_enabled']));
        }
        header('Location: ' . $redirect . '?saved=1');
        exit;
    }

    if ($action === 'set_default') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = image_models_get($id);
        if (!$row) {
            throw new InvalidArgumentException('模型不存在');
        }
        if (!(int) $row['is_enabled']) {
            throw new InvalidArgumentException('请先启用该模型再设为默认');
        }
        image_models_set_default($id);
        header('Location: ' . $redirect . '?saved=1');
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            image_models_delete($id);
        }
        header('Location: ' . $redirect . '?saved=1');
        exit;
    }

    if ($action === 'bulk_create') {
        $raw = $_POST['checkpoints'] ?? [];
        if (!is_array($raw)) {
            $raw = array_filter(array_map('trim', explode(',', (string) $raw)));
        }
        if ($raw === []) {
            throw new InvalidArgumentException('请至少选择一个 checkpoint');
        }
        $maxSort = 0;
        foreach (image_models_list_all() as $row) {
            $maxSort = max($maxSort, (int) $row['sort_order']);
        }
        $result = image_models_bulk_create_from_checkpoints($raw, $maxSort + 1);
        $qs = 'saved=1&added=' . (int) $result['added'] . '&skipped=' . (int) $result['skipped'];
        header('Location: ' . $redirect . '?' . $qs);
        exit;
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $checkpoint = trim((string) ($_POST['checkpoint'] ?? ''));
        $outputPrefix = trim((string) ($_POST['output_prefix'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        image_models_update($id, $displayName, $checkpoint, $outputPrefix, $sortOrder);
        header('Location: ' . $redirect . '?saved=1');
        exit;
    }

    if ($action === 'create') {
        $modelKey = trim((string) ($_POST['model_key'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $checkpoint = trim((string) ($_POST['checkpoint'] ?? ''));
        $outputPrefix = trim((string) ($_POST['output_prefix'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isDefault = !empty($_POST['is_default']);

        if ($modelKey === '') {
            $modelKey = image_models_slug_from_checkpoint($checkpoint);
        }
        $modelKey = image_models_unique_key(image_models_validate_key($modelKey));

        image_models_create($modelKey, $displayName, $checkpoint, $outputPrefix, $sortOrder, $isDefault);
        header('Location: ' . $redirect . '?saved=1');
        exit;
    }
} catch (Throwable $e) {
    header('Location: ' . $redirect . '?error=' . urlencode($e->getMessage()));
    exit;
}

header('Location: ' . $redirect);
exit;
