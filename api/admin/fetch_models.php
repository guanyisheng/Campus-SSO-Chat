<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__, 2);
require_once $appRoot . '/config.php';
require_once $appRoot . '/lib/admin.php';
require_once $appRoot . '/lib/models.php';
require_once $appRoot . '/lib/model_remote.php';

api_json_headers();

if (!is_admin()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录管理后台'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => '无效 JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseUrl = trim((string) ($input['base_url'] ?? ''));
$apiKey = trim((string) ($input['api_key'] ?? ''));
$type = model_normalize_type((string) ($input['model_type'] ?? 'chat'));

try {
    $models = model_remote_list($baseUrl, $apiKey);
    $existing = [];
    foreach (models_list_all($type) as $row) {
        $name = (string) ($row['model_name'] ?? '');
        if ($name !== '') {
            $existing[$name] = true;
        }
    }

    $items = [];
    foreach ($models as $id) {
        $items[] = [
            'id'       => $id,
            'exists'   => isset($existing[$id]),
        ];
    }

    echo json_encode([
        'models' => $items,
        'count'  => count($items),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
