<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/models.php';
require_once dirname(__DIR__) . '/lib/model_remote.php';

models_fix_schema();
require_admin();

$base = site_base_url() . '/admin/models.php';
$returnType = model_normalize_type((string) ($_POST['return_type'] ?? $_GET['type'] ?? 'chat'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $base . '?type=' . urlencode($returnType));
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'toggle') {
    $id = (int) ($_POST['id'] ?? 0);
    $m = model_get($id);
    if ($m) {
        model_update_enabled($id, !((int) $m['is_enabled']));
    }
    header('Location: ' . $base . '?type=' . urlencode($returnType) . '&saved=1');
    exit;
}

if ($action === 'update') {
    try {
        $id = (int) ($_POST['id'] ?? 0);
        $m = model_get($id);
        if (!$m) {
            throw new InvalidArgumentException('API 不存在');
        }
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['base_url'] ?? '');
        $modelName = trim($_POST['model_name'] ?? '');
        if ($name === '' || $url === '' || $modelName === '') {
            throw new InvalidArgumentException('请填写完整');
        }
        model_update(
            $id,
            $name,
            $url,
            $modelName,
            trim($_POST['api_key'] ?? ''),
            (int) ($_POST['sort_order'] ?? 0),
            (string) ($_POST['model_type'] ?? $m['model_type'] ?? 'chat')
        );
        header('Location: ' . $base . '?type=' . urlencode($returnType) . '&saved=1');
        exit;
    } catch (Throwable $e) {
        header('Location: ' . $base . '?type=' . urlencode($returnType) . '&error=' . urlencode($e->getMessage()));
        exit;
    }
}

if ($action === 'bulk_create') {
    try {
        $url = trim($_POST['base_url'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');
        $type = model_normalize_type((string) ($_POST['model_type'] ?? 'chat'));
        $rawNames = $_POST['model_names'] ?? [];
        if (!is_array($rawNames)) {
            $rawNames = array_filter(array_map('trim', explode(',', (string) $rawNames)));
        }
        if ($url === '') {
            throw new InvalidArgumentException('请填写 API Base URL');
        }
        if ($rawNames === []) {
            throw new InvalidArgumentException('请至少选择一个模型');
        }
        $result = model_bulk_create($url, $apiKey, $rawNames, $type);
        $qs = 'type=' . urlencode($type) . '&saved=1'
            . '&added=' . (int) $result['added']
            . '&skipped=' . (int) $result['skipped'];
        header('Location: ' . $base . '?' . $qs);
        exit;
    } catch (Throwable $e) {
        header('Location: ' . $base . '?type=' . urlencode($returnType) . '&error=' . urlencode($e->getMessage()));
        exit;
    }
}

if ($action === 'create') {
    try {
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['base_url'] ?? '');
        $modelName = trim($_POST['model_name'] ?? '');
        $type = model_normalize_type((string) ($_POST['model_type'] ?? 'chat'));
        if ($name === '' || $url === '' || $modelName === '') {
            throw new InvalidArgumentException('请填写完整');
        }
        model_create(
            $name,
            $url,
            $modelName,
            trim($_POST['api_key'] ?? ''),
            (int) ($_POST['sort_order'] ?? 0),
            $type
        );
        header('Location: ' . $base . '?type=' . urlencode($type) . '&saved=1');
        exit;
    } catch (Throwable $e) {
        header('Location: ' . $base . '?type=' . urlencode($returnType) . '&error=' . urlencode($e->getMessage()));
        exit;
    }
}

header('Location: ' . $base . '?type=' . urlencode($returnType));
exit;
