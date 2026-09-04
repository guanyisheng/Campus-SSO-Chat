<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/models.php';
require_once dirname(__DIR__) . '/lib/followups.php';

api_json_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!setting_bool('enable_chat', true)) {
    http_response_code(503);
    echo json_encode(['error' => '对话功能已暂停'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => '无效 JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userText = trim((string) ($input['user_message'] ?? ''));
$assistantText = trim((string) ($input['assistant_message'] ?? ''));
if ($userText === '' || $assistantText === '') {
    echo json_encode(['followups' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$modelId = (int) ($input['model_id'] ?? 0);
$llm = $modelId > 0 ? model_get($modelId, true) : null;
if (!$llm) {
    $enabled = models_list_enabled();
    $llm = $enabled[0] ?? null;
}

$followups = [];
if ($llm) {
    try {
        $followups = followups_generate($llm, $userText, $assistantText);
    } catch (Throwable) {
        $followups = [];
    }
}

echo json_encode(['followups' => $followups], JSON_UNESCAPED_UNICODE);
