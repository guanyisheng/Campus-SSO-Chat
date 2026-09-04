<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/agents.php';
require_once dirname(__DIR__) . '/lib/models.php';

api_json_headers();
require_login();

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) $user['id'];
agents_fix_schema();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'agents' => agents_list_for_user($userId),
        'status' => agents_status_for_user($userId),
        'models' => models_list_enabled(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$isMultipart = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');

if (!$isMultipart) {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => '无效 JSON'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $action = (string) ($input['action'] ?? $action);
} else {
    $input = $_POST;
}

try {
    if ($action === 'create') {
        $name = trim((string) ($input['display_name'] ?? ''));
        $prompt = trim((string) ($input['system_prompt'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $modelId = (int) ($input['model_id'] ?? 0);
        $avatarFile = '';
        if ($isMultipart && !empty($_FILES['avatar']) && is_array($_FILES['avatar'])) {
            $upload = agent_save_upload($_FILES['avatar'], 'user_agent');
            $avatarFile = $upload['filename'];
        }
        $id = user_agent_create($userId, $name, $prompt, $modelId, $description, $avatarFile);
        $row = user_agent_get($id, $userId);
        echo json_encode([
            'ok'     => true,
            'agent'  => $row ? agent_row_to_public($row, 'user') : null,
            'status' => agents_status_for_user($userId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update') {
        $id = (int) ($input['id'] ?? 0);
        $name = trim((string) ($input['display_name'] ?? ''));
        $prompt = trim((string) ($input['system_prompt'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $modelId = (int) ($input['model_id'] ?? 0);
        $avatarFile = '';
        if ($isMultipart && !empty($_FILES['avatar']) && is_array($_FILES['avatar'])) {
            $upload = agent_save_upload($_FILES['avatar'], 'user_agent');
            $avatarFile = $upload['filename'];
        }
        if (!user_agent_update($id, $userId, $name, $prompt, $modelId, $description, $avatarFile)) {
            http_response_code(404);
            echo json_encode(['error' => '智能体不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $row = user_agent_get($id, $userId);
        echo json_encode([
            'ok'     => true,
            'agent'  => $row ? agent_row_to_public($row, 'user') : null,
            'status' => agents_status_for_user($userId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if (!user_agent_delete($id, $userId)) {
            http_response_code(404);
            echo json_encode(['error' => '智能体不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'ok'     => true,
            'status' => agents_status_for_user($userId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => '未知操作'], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => '操作失败'], JSON_UNESCAPED_UNICODE);
}
