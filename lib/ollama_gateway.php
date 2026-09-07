<?php
declare(strict_types=1);

require_once __DIR__ . '/models.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/quota.php';
require_once __DIR__ . '/content_policy.php';
require_once __DIR__ . '/user_api_keys.php';

function ollama_gateway_resolve_chat_model(string $requestedModel): array
{
    models_ensure_default();
    $requestedModel = trim($requestedModel);
    $enabled = models_list_enabled_by_type('chat');

    if ($requestedModel !== '') {
        foreach ($enabled as $row) {
            if ((string) ($row['model_name'] ?? '') === $requestedModel) {
                return $row;
            }
        }
        throw new InvalidArgumentException('不支持的模型：' . $requestedModel);
    }

    if ($enabled === []) {
        throw new RuntimeException('无可用对话模型');
    }

    return $enabled[0];
}

/** @return list<array{id:string,object:string,created:int,owned_by:string}> */
function ollama_gateway_public_models(): array
{
    $out = [];
    foreach (models_list_enabled_by_type('chat') as $row) {
        $name = trim((string) ($row['model_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $out[] = [
            'id'       => $name,
            'object'   => 'model',
            'created'  => time(),
            'owned_by' => 'campus-chat',
        ];
    }

    return $out;
}

function ollama_gateway_build_payload(array $input, array $llm): array
{
    $messages = $input['messages'] ?? [];
    if (!is_array($messages) || $messages === []) {
        throw new InvalidArgumentException('messages 不能为空');
    }

    $runtime = model_chat_runtime($llm);
    $maxTokens = setting_int('ollama_max_tokens', defined('OLLAMA_MAX_TOKENS') ? (int) OLLAMA_MAX_TOKENS : 2048);
    $numCtx = setting_int('ollama_num_ctx', defined('OLLAMA_NUM_CTX') ? (int) OLLAMA_NUM_CTX : 8192);

    $payload = [
        'model'       => $runtime['model_name'],
        'messages'    => $messages,
        'stream'      => !empty($input['stream']),
        'max_tokens'  => (int) ($input['max_tokens'] ?? $maxTokens),
        'temperature' => (float) ($input['temperature'] ?? setting_float('ollama_temperature', defined('OLLAMA_TEMPERATURE') ? (float) OLLAMA_TEMPERATURE : 0.7)),
        'top_p'       => (float) ($input['top_p'] ?? setting_float('ollama_top_p', defined('OLLAMA_TOP_P') ? (float) OLLAMA_TOP_P : 0.9)),
        'options'     => [
            'num_ctx'     => $numCtx,
            'num_predict' => (int) ($input['max_tokens'] ?? $maxTokens),
        ],
    ];

    foreach ($messages as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        if (($msg['role'] ?? '') === 'user') {
            content_policy_assert_safe((string) ($msg['content'] ?? ''));
        }
    }

    return [
        'payload'  => $payload,
        'runtime'  => $runtime,
        'model_id' => (int) ($llm['id'] ?? 0),
    ];
}

function ollama_gateway_forward(array $input, int $userId): void
{
    if (!setting_bool('enable_chat', true)) {
        throw new RuntimeException('对话功能已暂停', 503);
    }
    if (!quota_check($userId, 'chat')) {
        throw new RuntimeException(quota_error_message('chat', $userId), 429);
    }

    $llm = ollama_gateway_resolve_chat_model(trim((string) ($input['model'] ?? '')));
    $built = ollama_gateway_build_payload($input, $llm);
    $payload = $built['payload'];
    $runtime = $built['runtime'];
    $endpoint = rtrim($runtime['base_url'], '/') . '/chat/completions';
    $timeout = setting_int('ollama_timeout', defined('OLLAMA_TIMEOUT') ? (int) OLLAMA_TIMEOUT : 180);

    if (!empty($payload['stream'])) {
        ollama_gateway_stream_passthrough($endpoint, $payload, $runtime['api_key'], $timeout, $userId);
        return;
    }

    $json = ollama_gateway_request_json($endpoint, $payload, $runtime['api_key'], $timeout);
    quota_consume($userId, 'chat');
    echo json_encode($json, JSON_UNESCAPED_UNICODE);
}

function ollama_gateway_request_json(string $url, array $payload, string $apiKey, int $timeout): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
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
        throw new RuntimeException('模型请求失败: ' . $err, 502);
    }

    $json = json_decode($raw, true);
    if ($code >= 400) {
        $msg = is_array($json) ? ($json['error']['message'] ?? $json['error'] ?? $raw) : $raw;
        throw new RuntimeException(is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE), $code);
    }

    if (!is_array($json)) {
        throw new RuntimeException('模型响应无效', 502);
    }

    return $json;
}

function ollama_gateway_stream_passthrough(string $url, array $payload, string $apiKey, int $timeout, int $userId): void
{
    api_json_discard_buffer();
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $headers = ['Content-Type: application/json', 'Accept: text/event-stream'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $charged = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER    => $headers,
        CURLOPT_TIMEOUT       => $timeout,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$charged, $userId) {
            if (!$charged && $data !== '') {
                quota_consume($userId, 'chat');
                $charged = true;
            }
            echo $data;
            flush();
            return strlen($data);
        },
    ]);

    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($ok === false) {
        echo 'data: ' . json_encode(['error' => ['message' => $err]], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    } elseif ($code >= 400 && !$charged) {
        echo 'data: ' . json_encode(['error' => ['message' => 'upstream HTTP ' . $code]], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }
}
