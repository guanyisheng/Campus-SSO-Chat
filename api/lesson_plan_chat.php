<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/models.php';
require_once dirname(__DIR__) . '/lib/quota.php';
require_once dirname(__DIR__) . '/lib/lesson_plan.php';

require_login();
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo '未登录';
    exit;
}

$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

if (!setting_bool('enable_lesson_plan', true)) {
    http_response_code(503);
    echo '教案工具已关闭';
    exit;
}

if (!setting_bool('enable_chat', true)) {
    http_response_code(503);
    echo '对话功能已暂停';
    exit;
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || trim($raw) === '') {
    http_response_code(400);
    echo 'Empty body';
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

$llmModelId = (int) ($payload['llm_model_id'] ?? 0);
$llm = model_resolve_for_chat($llmModelId);
if (!$llm) {
    http_response_code(400);
    echo '无可用对话模型，请在管理后台 → API 管理 → 对话 API 中添加并启用';
    exit;
}

$runtime = model_chat_runtime($llm);
$modelName = $runtime['model_name'];
if ($modelName === '') {
    http_response_code(400);
    echo '模型配置无效：缺少 model_name';
    exit;
}

if ($runtime['base_url'] === '') {
    http_response_code(500);
    echo '模型未配置 API Base URL，请在后台编辑该对话模型';
    exit;
}

if (!quota_check($userId, 'chat')) {
    http_response_code(429);
    echo quota_error_message('chat', $userId);
    exit;
}

$meta = lesson_plan_parse_payload($payload);
$recordId = lesson_plan_record_start($userId, $meta, $payload, $modelName);
quota_consume($userId, 'chat');

$forward = $payload;
unset($forward['llm_model_id'], $forward['provider_id']);
$forward['model'] = $modelName;

$maxTokens = (int) ($forward['max_tokens'] ?? 0);
if ($maxTokens <= 0) {
    $maxTokens = setting_int('lesson_plan_max_tokens', 65536);
}
$maxTokens = max($maxTokens, 16384);
$maxTokens = min($maxTokens, 131072);
$forward['max_tokens'] = $maxTokens;

$apiKey = $runtime['api_key'];
$baseUrl = $runtime['base_url'];
$timeout = setting_int('ollama_timeout', OLLAMA_TIMEOUT);

@ignore_user_abort(true);
if ($timeout > 0) {
    @set_time_limit($timeout + 120);
} else {
    @set_time_limit(0);
}

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ob_implicit_flush(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    ob_end_clean();
}

echo ": link\n\n";
echo ':' . str_repeat(' ', 2040) . "\n\n";
@ob_flush();
@flush();

$upstreamSnippet = '';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/chat/completions');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array_values(array_filter([
    'Content-Type: application/json',
    $apiKey !== '' ? 'Authorization: Bearer ' . $apiKey : null,
    'Expect:',
])));
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($forward, JSON_UNESCAPED_UNICODE));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
@curl_setopt($ch, CURLOPT_BUFFERSIZE, 8192);
@curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, $timeout > 0 ? $timeout : 0);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $data) use (&$upstreamSnippet) {
    if (strlen($upstreamSnippet) < 16384) {
        $upstreamSnippet .= $data;
    }
    echo $data;
    @ob_flush();
    @flush();
    return strlen($data);
});

$ok = curl_exec($ch);
$curlErrno = curl_errno($ch);
$err = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($ok === false || $code >= 400) {
    $detail = 'curl_errno=' . $curlErrno . '; curl_error=' . $err . '; http_code=' . $code;
    if ($upstreamSnippet !== '') {
        $detail .= '; upstream_snippet=' . mb_substr($upstreamSnippet, 0, 2000);
    }
    lesson_plan_record_error($recordId, '模型代理失败 ' . $detail);
    quota_refund($userId, 'chat');
}

exit;
