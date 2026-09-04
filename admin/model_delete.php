<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/models.php';

require_admin();

$base = site_base_url() . '/admin/models.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $returnType = model_normalize_type((string) ($_POST['return_type'] ?? 'chat'));
    if ($id > 0) {
        model_delete($id);
    }
    header('Location: ' . $base . '?type=' . urlencode($returnType) . '&saved=1');
    exit;
}

header('Location: ' . $base);
exit;
