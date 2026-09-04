<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/media_queue.php';

api_json_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$userId = (int) $user['id'];
$jobId = (int) ($_GET['id'] ?? 0);
if ($jobId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '缺少任务 id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$job = media_queue_get_for_user($jobId, $userId);
if (!$job) {
    http_response_code(404);
    echo json_encode(['error' => '任务不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

$response = media_queue_public_status($job);
$shouldKick = ($job['status'] ?? '') === 'queued';

if ($job['status'] === 'failed') {
    http_response_code(502);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

if ($shouldKick) {
    media_queue_finish_response_and_kick((int) $job['provider_id']);
}
