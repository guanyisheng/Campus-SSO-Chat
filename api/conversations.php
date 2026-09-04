<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/conversations.php';
require_once dirname(__DIR__) . '/lib/models.php';
require_once dirname(__DIR__) . '/lib/agents.php';

api_json_headers();

require_login();
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $conv = conv_get_for_user($id, $userId);
        if (!$conv) {
            http_response_code(404);
            echo json_encode(['error' => '对话不存在']);
            exit;
        }
        echo json_encode([
            'conversation' => $conv,
            'messages'     => conv_messages($id, $userId),
            'agent'        => !empty($conv['agent_type']) && !empty($conv['agent_id'])
                ? agent_resolve_for_user($userId, [
                    'type' => (string) $conv['agent_type'],
                    'id'   => (int) $conv['agent_id'],
                ])
                : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'conversations' => conv_list_for_user($userId),
        'server_time'   => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$action = $input['action'] ?? '';

if ($action === 'create') {
    $modelId = (int) ($input['model_id'] ?? 0);
    $agentRef = agent_parse_ref($input['agent'] ?? ($input['agent_ref'] ?? null));
    $agent = null;
    if ($agentRef) {
        $agent = agent_resolve_for_user($userId, $agentRef);
        if (!$agent) {
            http_response_code(400);
            echo json_encode(['error' => '智能体不可用'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!empty($agent['model_id'])) {
            $modelId = (int) $agent['model_id'];
        }
        if ($modelId <= 0 || !model_get($modelId, true)) {
            $enabled = models_list_enabled();
            $modelId = $enabled[0]['id'] ?? 0;
        }
        if ($modelId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => '无可用模型']);
            exit;
        }
        $existingAgentConv = conv_find_for_agent($userId, $agentRef);
        if ($existingAgentConv) {
            echo json_encode([
                'id'       => (int) $existingAgentConv['id'],
                'model_id' => (int) ($existingAgentConv['model_id'] ?? $modelId),
                'existing' => true,
                'agent'    => $agentRef,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $title = trim((string) ($agent['display_name'] ?? '')) ?: '智能体对话';
        $id = conv_create($userId, $modelId, $title, $agentRef);
        echo json_encode([
            'id'       => $id,
            'model_id' => $modelId,
            'agent'    => $agentRef,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($modelId <= 0 || !model_get($modelId, true)) {
        $enabled = models_list_enabled();
        $modelId = $enabled[0]['id'] ?? 0;
    }
    if ($modelId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => '无可用模型']);
        exit;
    }
    $existing = conv_find_reusable_empty($userId, $modelId);
    if ($existing) {
        echo json_encode([
            'id'       => (int) $existing['id'],
            'model_id' => (int) ($existing['model_id'] ?? $modelId),
            'existing' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = conv_create($userId, $modelId, '新对话', $agentRef);
    echo json_encode([
        'id'       => $id,
        'model_id' => $modelId,
        'agent'    => $agentRef,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0 || !conv_delete($id, $userId)) {
        http_response_code(404);
        echo json_encode(['error' => '删除失败']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'update_model') {
    $id = (int) ($input['id'] ?? 0);
    $modelId = (int) ($input['model_id'] ?? 0);
    if ($id <= 0 || $modelId <= 0 || !model_get($modelId, true)) {
        http_response_code(400);
        echo json_encode(['error' => '参数无效']);
        exit;
    }
    if (!conv_update_model($id, $userId, $modelId)) {
        http_response_code(404);
        echo json_encode(['error' => '对话不存在']);
        exit;
    }
    echo json_encode(['ok' => true, 'model_id' => $modelId], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'clear_messages') {
    $id = (int) ($input['id'] ?? 0);
    $conv = $id > 0 ? conv_get_for_user($id, $userId) : null;
    if (!$conv) {
        http_response_code(404);
        echo json_encode(['error' => '对话不存在']);
        exit;
    }
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '' && !empty($conv['agent_type']) && !empty($conv['agent_id'])) {
        $agent = agent_resolve_for_user($userId, [
            'type' => (string) $conv['agent_type'],
            'id'   => (int) $conv['agent_id'],
        ]);
        if ($agent) {
            $title = trim((string) ($agent['display_name'] ?? ''));
        }
    }
    if (!conv_clear_messages($id, $userId, $title !== '' ? $title : null)) {
        http_response_code(404);
        echo json_encode(['error' => '清空失败']);
        exit;
    }
    echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'cancel_pending') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0 || !conv_get_for_user($id, $userId)) {
        http_response_code(404);
        echo json_encode(['error' => '对话不存在']);
        exit;
    }
    require_once dirname(__DIR__) . '/lib/reasoning.php';
    $partial = trim((string) ($input['partial_content'] ?? ''));
    if ($partial !== '') {
        $partial = assistant_content_pack($partial, (string) ($input['reasoning'] ?? ''));
    }
    $ok = conv_cancel_text_pending($id, $userId, $partial);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => '未知操作']);
