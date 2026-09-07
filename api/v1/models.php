<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/v1_gateway_auth.php';
require_once dirname(__DIR__, 2) . '/lib/ollama_gateway.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'GET') {
    if ($method === 'HEAD') {
        v1_gateway_require_user_id();
        http_response_code(200);
        exit;
    }
    v1_gateway_deny(405, '方法不允许', '请使用 GET 请求，并携带 Authorization: Bearer <API Key>');
}

try {
    v1_gateway_require_user_id();
    header('Content-Type: application/json; charset=utf-8');
    $models = ollama_gateway_public_models();
    echo json_encode([
        'object' => 'list',
        'data'   => $models,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    v1_gateway_json_error(500, $e->getMessage());
}
