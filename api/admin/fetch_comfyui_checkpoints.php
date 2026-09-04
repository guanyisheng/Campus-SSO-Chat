<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__, 2);
require_once $appRoot . '/config.php';
require_once $appRoot . '/lib/admin.php';
require_once $appRoot . '/lib/image_models.php';
require_once $appRoot . '/lib/comfyui.php';

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
if ($baseUrl === '') {
    $baseUrl = comfyui_resolve_admin_base_url();
}

try {
    $checkpoints = comfyui_list_checkpoints($baseUrl);
    $existing = [];
    foreach (image_models_list_all() as $row) {
        $name = (string) ($row['checkpoint'] ?? '');
        if ($name !== '') {
            $existing[$name] = true;
        }
    }

    $items = [];
    foreach ($checkpoints as $name) {
        $items[] = [
            'checkpoint' => $name,
            'model_key'  => image_models_slug_from_checkpoint($name),
            'exists'     => isset($existing[$name]),
        ];
    }

    echo json_encode([
        'ok'          => true,
        'base_url'    => rtrim($baseUrl, '/'),
        'checkpoints' => $items,
        'count'       => count($items),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
