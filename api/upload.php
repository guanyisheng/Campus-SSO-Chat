<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/file_extract.php';
require_once dirname(__DIR__) . '/lib/conv_storage.php';

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
        throw new InvalidArgumentException('未选择文件');
    }

    $info = file_validate_upload($_FILES['file']);
    conv_redis_touch_user_activity($userId);
    $stored = conv_storage_save_upload($userId, $info['tmp'], $info['name'], $info['ext']);
    $savedPath = conv_storage_user_upload_dir($userId) . DIRECTORY_SEPARATOR . $stored;
    $text = file_extract_text($savedPath, $info['ext']);

    echo json_encode([
        'ok'       => true,
        'filename' => $info['name'],
        'stored'   => $stored,
        'ext'      => $info['ext'],
        'chars'    => mb_strlen($text),
        'text'     => $text,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
