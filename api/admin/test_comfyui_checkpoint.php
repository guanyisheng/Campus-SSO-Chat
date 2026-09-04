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

$checkpoint = trim((string) ($input['checkpoint'] ?? ''));
$baseUrl = trim((string) ($input['base_url'] ?? ''));
if ($baseUrl === '') {
    $baseUrl = comfyui_resolve_admin_base_url();
}

try {
    if ($checkpoint !== '') {
        $available = comfyui_checkpoint_available($checkpoint, $baseUrl);
        echo json_encode([
            'ok'        => true,
            'base_url'  => rtrim($baseUrl, '/'),
            'checkpoint'=> $checkpoint,
            'available' => $available,
            'message'   => $available ? 'ComfyUI 中已找到该 checkpoint' : 'ComfyUI 中未找到该 checkpoint',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = comfyui_test_connection($baseUrl);
    echo json_encode([
        'ok'       => true,
        'message'  => 'ComfyUI 连接正常，共 ' . (int) $result['checkpoint_count'] . ' 个 checkpoint',
        'result'   => $result,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
