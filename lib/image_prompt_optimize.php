<?php
declare(strict_types=1);

function image_prompt_optimize_enabled(): bool
{
    if (defined('IMAGE_PROMPT_OPTIMIZE_ENABLED')) {
        return (bool) IMAGE_PROMPT_OPTIMIZE_ENABLED;
    }

    return true;
}

function image_prompt_optimize_model(): string
{
    if (defined('IMAGE_PROMPT_OPTIMIZE_MODEL') && trim((string) IMAGE_PROMPT_OPTIMIZE_MODEL) !== '') {
        return trim((string) IMAGE_PROMPT_OPTIMIZE_MODEL);
    }

    return 'gemma4:31b';
}

function image_prompt_optimize_timeout(): int
{
    if (defined('IMAGE_PROMPT_OPTIMIZE_TIMEOUT')) {
        return max(15, (int) IMAGE_PROMPT_OPTIMIZE_TIMEOUT);
    }

    return 60;
}

function image_prompt_contains_cjk(string $text): bool
{
    return (bool) preg_match('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]/u', $text);
}

/** @return array{0:string,1:string} [baseUrl, apiKey] */
function image_prompt_optimize_ollama_runtime(): array
{
    $base = defined('OLLAMA_BASE_URL') ? rtrim(trim((string) OLLAMA_BASE_URL), '/') : '';
    if ($base === '') {
        throw new RuntimeException('未配置 OLLAMA_BASE_URL，无法优化提示词');
    }
    $apiKey = defined('OLLAMA_API_KEY') ? trim((string) OLLAMA_API_KEY) : '';

    return [$base, $apiKey];
}

/** @return array<string, mixed> */
function image_prompt_optimize_ollama_chat(array $payload, int $timeout): array
{
    [$base, $apiKey] = image_prompt_optimize_ollama_runtime();
    $url = $base . '/chat/completions';

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

    if ($raw === false) {
        throw new RuntimeException('优化模型请求失败: ' . $err);
    }

    $json = json_decode($raw, true);
    if ($code >= 400) {
        $msg = is_array($json) ? ($json['error']['message'] ?? $json['error'] ?? $raw) : $raw;
        throw new RuntimeException('优化模型错误 (' . $code . '): ' . (is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE)));
    }

    if (!is_array($json)) {
        throw new RuntimeException('优化模型响应无效');
    }

    return $json;
}

function image_prompt_optimize_sanitize_output(string $text): string
{
    $text = trim($text);
    $text = preg_replace('/^["\'`\s]+|["\'`\s]+$/u', '', $text) ?? $text;
    $text = preg_replace('/^(optimized prompt|prompt|output)\s*:\s*/i', '', $text) ?? $text;
    $text = preg_replace('/^```[\w]*\s*|\s*```$/u', '', $text) ?? $text;

    return trim(preg_replace("/\r\n|\r|\n/u", ' ', $text) ?? $text);
}

/**
 * @return array{optimized:string,translated:bool,model:string}
 */
function image_prompt_optimize(string $prompt): array
{
    if (!image_prompt_optimize_enabled()) {
        throw new RuntimeException('生图提示词优化已关闭');
    }

    $prompt = trim($prompt);
    if ($prompt === '') {
        throw new InvalidArgumentException('请先输入图片描述');
    }

    $model = image_prompt_optimize_model();
    $hasCjk = image_prompt_contains_cjk($prompt);
    $system = $hasCjk
        ? 'You translate and optimize image generation prompts. Translate Chinese into natural English, preserve all subjects, actions, composition, style, and details. Output ONE single-line English prompt suitable for Stable Diffusion / SDXL. Do not explain. No quotes. No markdown.'
        : 'You optimize image generation prompts for Stable Diffusion / SDXL. Output ONE single-line English prompt. Preserve meaning. Do not explain. No quotes. No markdown.';

    $user = $hasCjk
        ? "Translate and optimize this prompt for image generation:\n" . $prompt
        : "Optimize this prompt for image generation:\n" . $prompt;

    $json = image_prompt_optimize_ollama_chat([
        'model'    => $model,
        'stream'   => false,
        'temperature' => 0.3,
        'max_tokens'  => 512,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
    ], image_prompt_optimize_timeout());

    $content = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
    $optimized = image_prompt_optimize_sanitize_output($content);
    if ($optimized === '') {
        throw new RuntimeException('优化模型未返回有效提示词');
    }

    return [
        'optimized'  => $optimized,
        'translated' => $hasCjk,
        'model'      => $model,
    ];
}
