<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/quota.php';
require_once dirname(__DIR__) . '/lib/media.php';
require_once dirname(__DIR__) . '/lib/media_queue.php';
require_once dirname(__DIR__) . '/lib/conversations.php';
require_once dirname(__DIR__) . '/lib/content_policy.php';
require_once dirname(__DIR__) . '/lib/image_models.php';

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

$userId = (int) $user['id'];
$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => '无效 JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$prompt = trim((string) ($input['prompt'] ?? ''));
if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => '请填写图片描述'], JSON_UNESCAPED_UNICODE);
    exit;
}

$displayPrompt = trim((string) ($input['display_prompt'] ?? ''));
if ($displayPrompt === '') {
    $displayPrompt = $prompt;
}

try {
    content_policy_assert_safe($prompt);
    if ($displayPrompt !== $prompt) {
        content_policy_assert_safe($displayPrompt);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage(), 'refused' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!quota_check($userId, 'image')) {
    http_response_code(429);
    echo json_encode(['error' => quota_error_message('image', $userId), 'quota' => quota_status($userId)], JSON_UNESCAPED_UNICODE);
    exit;
}

$provider = media_pick_provider_for_user($userId, 'image');
if (!$provider) {
    http_response_code(400);
    echo json_encode(['error' => '未配置生图 API，请在后台添加'], JSON_UNESCAPED_UNICODE);
    exit;
}

$size = trim((string) ($input['size'] ?? '1024x768'));
$styleKey = trim((string) ($input['style_key'] ?? 'default'));
$modelInput = trim((string) ($input['model'] ?? ''));
try {
    $imageModel = media_image_model_resolve($modelInput);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
$refImages = media_normalize_image_urls($input['images'] ?? []);
$convId = (int) ($input['conversation_id'] ?? 0);
if ($convId <= 0) {
    $convId = conv_create($userId, 0, mb_substr($displayPrompt, 0, 40) ?: '生图');
} else {
    try {
        conv_require_for_user($convId, $userId);
    } catch (InvalidArgumentException $e) {
        http_response_code(404);
        echo json_encode(['error' => '对话不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $queueId = media_queue_enqueue($userId, 'image', (int) $provider['id'], [
        'prompt'          => $prompt,
        'display_prompt'  => $displayPrompt,
        'size'            => $size,
        'style_key'       => $styleKey,
        'model'           => $imageModel['key'],
        'images'          => $refImages,
        'conversation_id' => $convId,
    ]);

    $pendingContent = conv_save_media_queue_pending(
        $convId,
        $userId,
        'image',
        '@图片 ' . $displayPrompt,
        $displayPrompt,
        $queueId,
        (int) $provider['id']
    );

    $job = media_queue_get($queueId);
    if (!$job) {
        throw new RuntimeException('排队任务创建失败');
    }

    $response = media_queue_public_status($job);
    $response['conversation_id'] = $convId;
    if (($response['status'] ?? '') !== 'completed') {
        $response['pending_content'] = $pendingContent;
    }

    if ($response['status'] === 'failed') {
        http_response_code(502);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
