<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/models.php';
require_once dirname(__DIR__) . '/lib/conversations.php';
require_once dirname(__DIR__) . '/lib/conv_title.php';
require_once dirname(__DIR__) . '/lib/quota.php';
require_once dirname(__DIR__) . '/lib/content_policy.php';
require_once dirname(__DIR__) . '/lib/message_transport.php';
require_once dirname(__DIR__) . '/lib/media.php';
require_once dirname(__DIR__) . '/lib/agents.php';
require_once dirname(__DIR__) . '/lib/reasoning.php';
require_once dirname(__DIR__) . '/lib/deep_think.php';

api_json_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!setting_bool('enable_chat', true)) {
    http_response_code(503);
    echo json_encode(['error' => '对话功能已暂停']);
    exit;
}

require_login();
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

$userId = (int) $user['id'];

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => '无效 JSON']);
    exit;
}

$messages = $input['messages'] ?? [];
if (!is_array($messages) || count($messages) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'messages 不能为空']);
    exit;
}

$modelId = (int) ($input['model_id'] ?? 0);
$agentRef = $input['agent'] ?? ($input['agent_ref'] ?? null);
$agent = agent_resolve_for_user($userId, $agentRef);

$llm = $modelId > 0 ? model_get($modelId, true) : null;
if ($agent && !empty($agent['model_id'])) {
    $agentModel = model_get((int) $agent['model_id'], true);
    if ($agentModel) {
        $llm = $agentModel;
        $modelId = (int) $agentModel['id'];
    }
}
if (!$llm) {
    $enabled = models_list_enabled();
    $llm = $enabled[0] ?? null;
    if ($llm) {
        $modelId = (int) $llm['id'];
    }
}
if (!$llm) {
    http_response_code(400);
    echo json_encode(['error' => '无可用模型，请在后台添加']);
    exit;
}

$convId = (int) ($input['conversation_id'] ?? 0);
$parsedAgentRef = agent_parse_ref($agentRef);
if ($convId <= 0) {
    if ($parsedAgentRef) {
        $existingAgentConv = conv_find_for_agent($userId, $parsedAgentRef);
        if ($existingAgentConv) {
            $convId = (int) $existingAgentConv['id'];
        } else {
            $title = trim((string) ($agent['display_name'] ?? '')) ?: '智能体对话';
            $convId = conv_create($userId, $modelId, $title, $parsedAgentRef);
        }
    } else {
        $convId = conv_create($userId, $modelId);
    }
} else {
    $conv = conv_get_for_user($convId, $userId);
    if (!$conv) {
        http_response_code(404);
        echo json_encode(['error' => '对话不存在']);
        exit;
    }
}

$userMsg = end_user_message($messages);
$willConsumeChat = false;
if ($userMsg !== '') {
    $policyHit = content_policy_match($userMsg);
    if ($policyHit !== null) {
        $refusal = content_policy_refusal_message();
        conv_maybe_set_title_from_message($convId, $userId, $userMsg);
        conv_add_message($convId, $userId, 'user', $userMsg);
        if (!empty($input['stream'])) {
            content_policy_stream_refusal($convId, $userId, $refusal);
            exit;
        }
        conv_add_message($convId, $userId, 'assistant', $refusal);
        conv_summarize_title($convId, $userId);
        echo json_encode([
            'content'         => $refusal,
            'model'           => (string) ($llm['model_name'] ?? ''),
            'model_id'        => $modelId,
            'conversation_id' => $convId,
            'refused'         => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existing = conv_messages($convId, $userId);
    $last = $existing !== [] ? $existing[count($existing) - 1] : null;
    $isDuplicate = is_array($last)
        && ($last['role'] ?? '') === 'user'
        && (string) ($last['content'] ?? '') === $userMsg;
    $willConsumeChat = !$isDuplicate;
}

if ($willConsumeChat && !quota_check($userId, 'chat')) {
    http_response_code(429);
    echo json_encode([
        'error' => quota_error_message('chat', $userId),
        'quota' => quota_status($userId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($userMsg !== '') {
    if ($willConsumeChat) {
        conv_maybe_set_title_from_message($convId, $userId, $userMsg);
        conv_add_message($convId, $userId, 'user', $userMsg);
        conv_add_message($convId, $userId, 'assistant', media_text_pending_marker());
        quota_consume($userId, 'chat');
    }
}

$maxTokens = setting_int('ollama_max_tokens', OLLAMA_MAX_TOKENS);
$numCtx = setting_int('ollama_num_ctx', OLLAMA_NUM_CTX);
$timeout = setting_int('ollama_timeout', OLLAMA_TIMEOUT);
$apiKey = (string) ($llm['api_key'] ?? '');
$baseUrl = rtrim((string) $llm['base_url'], '/');
$modelName = (string) $llm['model_name'];

$normalized = trim_message_history(
    agent_inject_system(
        content_policy_inject_system(normalize_messages($messages)),
        $agent ? (string) ($agent['system_prompt'] ?? '') : ''
    )
);

$deepThink = deep_think_enabled_from_input($input);
$normalized = deep_think_adjust_messages($normalized, $deepThink);

$payload = [
    'model'       => $modelName,
    'messages'    => $normalized,
    'stream'      => !empty($input['stream']),
    'max_tokens'  => $maxTokens,
    'temperature' => setting_float('ollama_temperature', OLLAMA_TEMPERATURE),
    'top_p'       => setting_float('ollama_top_p', OLLAMA_TOP_P),
    'options'     => [
        'num_ctx'     => $numCtx,
        'num_predict' => $maxTokens,
    ],
];

deep_think_apply_to_payload($payload, $deepThink, $modelName);

$endpoint = $baseUrl . '/chat/completions';

try {
    if ($payload['stream']) {
        stream_ollama($endpoint, $payload, $apiKey, $timeout, $convId, $userId, $deepThink);
        exit;
    }

    $result = request_ollama($endpoint, $payload, $apiKey, $timeout);
    $msg = $result['choices'][0]['message'] ?? [];
    if (!is_array($msg)) {
        $msg = [];
    }
    $content = (string) ($msg['content'] ?? '');
    $reasoning = (string) (
        $msg['reasoning_content']
        ?? $msg['reasoning']
        ?? $msg['thinking']
        ?? ''
    );
    if (!$deepThink) {
        $content = deep_think_strip_tags($content);
        $reasoning = '';
    }
    $stored = assistant_content_pack($content, $reasoning);
    $newTitle = null;

    if ($content !== '') {
        if (!conv_replace_text_pending($convId, $userId, $stored)) {
            conv_add_message($convId, $userId, 'assistant', $stored);
        }
        $newTitle = conv_summarize_title($convId, $userId);
    } else {
        conv_replace_text_pending($convId, $userId, '（模型未返回内容）');
    }

    echo json_encode([
        'content'           => $content,
        'model'             => $modelName,
        'model_id'          => $modelId,
        'conversation_id'   => $convId,
        'title'             => $newTitle ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function normalize_messages(array $messages): array
{
    $decoded = message_transport_decode_messages($messages);
    foreach ($decoded as &$m) {
        if (($m['role'] ?? '') === 'assistant') {
            $m['content'] = assistant_content_for_model((string) ($m['content'] ?? ''));
        }
    }
    unset($m);

    return $decoded;
}

function trim_message_history(array $messages): array
{
    $system = [];
    $rest = [];
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'system') {
            $system[] = $m;
        } else {
            $rest[] = $m;
        }
    }
    $turns = setting_int('ollama_history_turns', OLLAMA_HISTORY_TURNS);
    $maxMsgs = max(2, $turns * 2);
    if (count($rest) > $maxMsgs) {
        $rest = array_slice($rest, -$maxMsgs);
    }
    return array_merge($system, $rest);
}

function request_ollama(string $url, array $payload, string $apiKey, int $timeout): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('模型请求失败: ' . $err);
    }

    $json = json_decode($raw, true);
    if ($code >= 400) {
        $msg = is_array($json) ? ($json['error']['message'] ?? $json['error'] ?? $raw) : $raw;
        throw new RuntimeException('模型返回错误 (' . $code . '): ' . (is_string($msg) ? $msg : json_encode($msg)));
    }

    if (!is_array($json)) {
        throw new RuntimeException('模型响应无效');
    }

    return $json;
}

function stream_ollama(string $url, array $payload, string $apiKey, int $timeout, int $convId, int $userId, bool $deepThink = true): void
{
    api_json_discard_buffer();
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    echo 'data: ' . json_encode(['conversation_id' => $convId], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();

    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $buffer = '';
    $fullContent = '';
    $fullReasoning = '';
    $contentFilter = $deepThink ? null : new DeepThinkStreamFilter();

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$buffer, &$fullContent, &$fullReasoning, $deepThink, $contentFilter) {
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || strpos($line, 'data:') !== 0) {
                    continue;
                }
                $jsonStr = trim(substr($line, 5));
                if ($jsonStr === '[DONE]') {
                    echo "data: [DONE]\n\n";
                    flush();
                    continue;
                }
                $chunk = json_decode($jsonStr, true);
                if (!is_array($chunk)) {
                    continue;
                }
                $delta = $chunk['choices'][0]['delta'] ?? [];
                if (!is_array($delta)) {
                    $delta = [];
                }
                $reasoning = (string) (
                    $delta['reasoning_content']
                    ?? $delta['reasoning']
                    ?? $delta['thinking']
                    ?? ''
                );
                if ($reasoning !== '') {
                    if ($deepThink) {
                        $fullReasoning .= $reasoning;
                        echo 'data: ' . json_encode(['reasoning' => $reasoning], JSON_UNESCAPED_UNICODE) . "\n\n";
                        flush();
                    }
                    continue;
                }
                $content = (string) ($delta['content'] ?? '');
                if ($content !== '') {
                    if ($contentFilter instanceof DeepThinkStreamFilter) {
                        $content = $contentFilter->filterContent($content);
                    }
                    if ($content === '') {
                        continue;
                    }
                    $fullContent .= $content;
                    echo 'data: ' . json_encode(['content' => $content], JSON_UNESCAPED_UNICODE) . "\n\n";
                    flush();
                }
            }
            return strlen($data);
        },
    ]);

    curl_exec($ch);
    curl_close($ch);

    if ($contentFilter instanceof DeepThinkStreamFilter) {
        $tail = $contentFilter->flush();
        if ($tail !== '') {
            $fullContent .= $tail;
            echo 'data: ' . json_encode(['content' => $tail], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }
        $fullContent = deep_think_strip_tags($fullContent);
        $fullReasoning = '';
    }

    if ($fullContent !== '') {
        $packed = assistant_content_pack($fullContent, $deepThink ? $fullReasoning : '');
        if (!conv_replace_text_pending($convId, $userId, $packed)) {
            conv_add_message($convId, $userId, 'assistant', $packed);
        }
    } else {
        conv_replace_text_pending($convId, $userId, '（模型未返回内容）');
    }

    $newTitle = $fullContent !== '' ? conv_summarize_title($convId, $userId) : null;
    if ($newTitle !== null) {
        echo 'data: ' . json_encode(['title' => $newTitle], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    echo "data: [DONE]\n\n";
    flush();
}

function end_user_message(array $messages): string
{
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (!is_array($messages[$i]) || ($messages[$i]['role'] ?? '') !== 'user') {
            continue;
        }
        return trim(message_transport_decode_content(
            (string) ($messages[$i]['content'] ?? ''),
            (string) ($messages[$i]['content_encoding'] ?? '')
        ));
    }
    return '';
}
