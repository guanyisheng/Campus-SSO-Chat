<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/csrf.php';
require_once dirname(__DIR__) . '/lib/models.php';
require_once dirname(__DIR__) . '/lib/user_api_keys.php';

api_json_headers();

require_login();
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($user['id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        echo json_encode(user_api_key_status($userId), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    csrf_require();

    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => '无效 JSON'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = (string) ($input['action'] ?? $_POST['action'] ?? '');
    if ($action === 'generate') {
        $result = user_api_key_generate($userId);
        echo json_encode([
            'ok'     => true,
            'key'    => $result['key'],
            'prefix' => $result['prefix'],
            'status' => user_api_key_status($userId),
            'message'=> '请立即复制保存，关闭后将无法再次查看完整 Key',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'revoke') {
        user_api_key_revoke($userId);
        echo json_encode([
            'ok'     => true,
            'status' => user_api_key_status($userId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => '未知 action'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
