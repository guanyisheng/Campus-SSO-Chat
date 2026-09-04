<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/content_policy.php';
require_once dirname(__DIR__) . '/lib/image_prompt_optimize.php';

api_json_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!setting_bool('enable_image_gen', true)) {
    http_response_code(503);
    echo json_encode(['error' => '生图功能已关闭'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => '无效 JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$prompt = trim((string) ($input['prompt'] ?? ''));
if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => '请先输入图片描述'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    content_policy_assert_safe($prompt);
    $result = image_prompt_optimize($prompt);
    echo json_encode([
        'ok'         => true,
        'original'   => $prompt,
        'optimized'  => $result['optimized'],
        'translated' => $result['translated'],
        'model'      => $result['model'],
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
