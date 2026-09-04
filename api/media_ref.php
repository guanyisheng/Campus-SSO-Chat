<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/media.php';

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

$userId = (int) ($user['id'] ?? 0);

try {
    if (empty($_FILES['file'])) {
        throw new InvalidArgumentException('未选择图片');
    }
    $info = media_validate_ref_upload($_FILES['file']);
    $url = media_save_ref_upload($info['tmp'], $info['ext'], $userId);
    echo json_encode([
        'ok'   => true,
        'url'  => $url,
        'name' => $info['name'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
