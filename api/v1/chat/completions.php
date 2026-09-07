<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/v1_gateway_auth.php';
require_once dirname(__DIR__, 2) . '/lib/ollama_gateway.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'POST') {
    v1_gateway_deny(
        405,
        '拒绝访问',
        $method === 'GET'
            ? '本接口仅供 API 调用。请使用 POST，并在请求头携带 Authorization: Bearer <API Key>'
            : '请使用 POST 请求'
    );
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    v1_gateway_json_error(400, '无效 JSON');
}

try {
    $userId = v1_gateway_require_user_id();

    if (!empty($input['stream'])) {
        ollama_gateway_forward($input, $userId);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    ollama_gateway_forward($input, $userId);
} catch (InvalidArgumentException $e) {
    v1_gateway_json_error(400, $e->getMessage());
} catch (RuntimeException $e) {
    $code = $e->getCode();
    v1_gateway_json_error(is_int($code) && $code >= 400 ? $code : 502, $e->getMessage());
} catch (Throwable $e) {
    v1_gateway_json_error(502, $e->getMessage());
}
