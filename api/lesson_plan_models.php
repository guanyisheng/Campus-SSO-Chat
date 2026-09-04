<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/models.php';
require_once dirname(__DIR__) . '/lib/api_json.php';

api_json_headers();
require_login();

if (!setting_bool('enable_lesson_plan', true)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '教案工具已关闭'], JSON_UNESCAPED_UNICODE);
    exit;
}

$models = [];
foreach (models_list_enabled_by_type('chat') as $m) {
    $apiName = trim((string) ($m['model_name'] ?? ''));
    if ($apiName === '') {
        continue;
    }
    $models[] = [
        'llm_model_id'   => (int) ($m['id'] ?? 0),
        'display_name'   => (string) ($m['display_name'] ?? $apiName),
        'model_api_name' => $apiName,
        'max_tokens'     => setting_int('lesson_plan_max_tokens', 65536),
        'provider_id'    => 0,
        'provider_name'  => (string) ($m['display_name'] ?? ''),
    ];
}

$hint = '';
if ($models === []) {
    $hint = '暂无可用对话模型。请在管理后台 → API 管理 → 对话 API 中添加并启用模型。';
}

echo json_encode([
    'ok'     => true,
    'models' => $models,
    'hint'   => $hint,
], JSON_UNESCAPED_UNICODE);
