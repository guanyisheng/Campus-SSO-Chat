<?php
declare(strict_types=1);

/** ComfyUI 本地 SDXL 生图驱动（POST /prompt → 轮询 /history → GET /view） */

function comfyui_base_url(): string
{
    if (defined('COMFYUI_BASE_URL') && trim((string) COMFYUI_BASE_URL) !== '') {
        return rtrim(trim((string) COMFYUI_BASE_URL), '/');
    }

    return 'http://127.0.0.1:8188';
}

/** @param array<string, mixed>|null $provider */
function comfyui_base_url_for_provider(?array $provider = null): string
{
    if ($provider) {
        $base = trim((string) ($provider['base_url'] ?? ''));
        if ($base !== '') {
            return rtrim($base, '/');
        }
    }

    return comfyui_base_url();
}

function comfyui_checkpoint_name(): string
{
    if (defined('COMFYUI_CHECKPOINT') && trim((string) COMFYUI_CHECKPOINT) !== '') {
        return trim((string) COMFYUI_CHECKPOINT);
    }

    return 'sd_xl_base_1.0.safetensors';
}

function comfyui_default_steps(): int
{
    if (defined('COMFYUI_DEFAULT_STEPS')) {
        return max(1, min(150, (int) COMFYUI_DEFAULT_STEPS));
    }

    return 20;
}

function comfyui_default_cfg(): float
{
    if (defined('COMFYUI_DEFAULT_CFG')) {
        return max(1.0, min(30.0, (float) COMFYUI_DEFAULT_CFG));
    }

    return 7.0;
}

function comfyui_poll_timeout_seconds(): int
{
    if (defined('COMFYUI_POLL_TIMEOUT')) {
        return max(60, (int) COMFYUI_POLL_TIMEOUT);
    }

    return 600;
}

function comfyui_log(string $message): void
{
    error_log('[ComfyUI] ' . $message);
}

/** @param array<string, mixed> $provider */
function media_provider_is_comfyui(array $provider): bool
{
    $name = strtolower(trim((string) ($provider['model_name'] ?? '')));
    if ($name === 'comfyui' || str_starts_with($name, 'comfyui:')) {
        return true;
    }

    $base = strtolower(rtrim(trim((string) ($provider['base_url'] ?? '')), '/'));
    if ($base === '') {
        return false;
    }

    $cfgBase = strtolower(comfyui_base_url());
    if ($base === $cfgBase) {
        return true;
    }

    return (bool) preg_match('#:8188(/|$)#', $base);
}

/** @return array{0:int,1:int} */
function comfyui_parse_size(string $size, int $defaultW = 1024, int $defaultH = 1024): array
{
    if (preg_match('/^(\d{3,5})x(\d{3,5})$/i', trim($size), $m)) {
        return [(int) $m[1], (int) $m[2]];
    }

    return [$defaultW, $defaultH];
}

function comfyui_workflow_template_path(): string
{
    return dirname(__DIR__) . '/storage/comfyui/sdxl_api_workflow.json';
}

/** @return array<string, mixed> */
function comfyui_load_workflow_template(): array
{
    $path = comfyui_workflow_template_path();
    if (!is_file($path)) {
        throw new RuntimeException('ComfyUI workflow 模板缺失: ' . $path);
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('无法读取 ComfyUI workflow 模板');
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('ComfyUI workflow 模板 JSON 无效');
    }

    return $json;
}

/**
 * @param array{
 *   positive:string,
 *   negative?:string,
 *   seed?:int,
 *   width?:int,
 *   height?:int,
 *   steps?:int,
 *   cfg?:float,
 *   checkpoint?:string,
 *   output_prefix?:string
 * } $params
 * @return array<string, mixed>
 */
function comfyui_build_workflow(array $params): array
{
    $workflow = comfyui_load_workflow_template();

    $workflow['4']['inputs']['ckpt_name'] = (string) ($params['checkpoint'] ?? comfyui_checkpoint_name());
    $workflow['5']['inputs']['width'] = (int) ($params['width'] ?? 1024);
    $workflow['5']['inputs']['height'] = (int) ($params['height'] ?? 1024);
    $workflow['6']['inputs']['text'] = (string) ($params['positive'] ?? '');
    $workflow['7']['inputs']['text'] = (string) ($params['negative'] ?? '');
    $workflow['3']['inputs']['seed'] = (int) ($params['seed'] ?? random_int(0, 2147483647));
    $workflow['3']['inputs']['steps'] = (int) ($params['steps'] ?? comfyui_default_steps());
    $workflow['3']['inputs']['cfg'] = (float) ($params['cfg'] ?? comfyui_default_cfg());
    if (isset($workflow['9']['inputs']['filename_prefix'])) {
        $prefix = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string) ($params['output_prefix'] ?? 'CampusChat'));
        $workflow['9']['inputs']['filename_prefix'] = $prefix !== '' ? $prefix : 'CampusChat';
    }

    return $workflow;
}

/** @return array<string, mixed> */
function comfyui_http(string $method, string $path, ?array $body = null, int $timeout = 120, ?string $baseUrl = null): array
{
    $base = rtrim($baseUrl ?? comfyui_base_url(), '/');
    $url = $base . '/' . ltrim($path, '/');

    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);

    if ($raw === false) {
        throw new RuntimeException('ComfyUI 请求失败: ' . $err . ' (' . $url . ')');
    }

    if ($path === '/view' || str_starts_with($path, '/view?')) {
        if ($code >= 400) {
            throw new RuntimeException('ComfyUI /view 错误 (' . $code . ')');
        }

        return ['binary' => $raw, 'http_code' => $code];
    }

    $json = json_decode($raw, true);
    if ($code >= 400) {
        $msg = is_array($json)
            ? (string) ($json['error'] ?? $json['message'] ?? $raw)
            : $raw;
        throw new RuntimeException('ComfyUI HTTP ' . $code . ': ' . $msg);
    }

    return is_array($json) ? $json : ['raw' => $raw];
}

/** @param array<string, mixed> $workflow */
function comfyui_submit_prompt(array $workflow, ?string $baseUrl = null): string
{
    $clientId = bin2hex(random_bytes(16));
    $payload = [
        'prompt'    => $workflow,
        'client_id' => $clientId,
    ];

    $json = comfyui_http('POST', '/prompt', $payload, 60, $baseUrl);
    $promptId = trim((string) ($json['prompt_id'] ?? ''));
    if ($promptId === '') {
        throw new RuntimeException('ComfyUI 未返回 prompt_id: ' . json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    comfyui_log('submitted prompt_id=' . $promptId);

    return $promptId;
}

/** @return array<string, mixed>|null */
function comfyui_get_history(string $promptId, ?string $baseUrl = null): ?array
{
    $json = comfyui_http('GET', '/history/' . rawurlencode($promptId), null, 60, $baseUrl);
    if (!isset($json[$promptId]) || !is_array($json[$promptId])) {
        return null;
    }

    return $json[$promptId];
}

/**
 * @return array{filename:string,subfolder:string,type:string}
 */
function comfyui_extract_output_image(array $history): array
{
    $outputs = $history['outputs'] ?? null;
    if (!is_array($outputs)) {
        throw new RuntimeException('ComfyUI history 缺少 outputs');
    }

    foreach ($outputs as $node) {
        if (!is_array($node)) {
            continue;
        }
        $images = $node['images'] ?? null;
        if (!is_array($images) || $images === []) {
            continue;
        }
        $img = $images[0];
        if (!is_array($img)) {
            continue;
        }
        $filename = trim((string) ($img['filename'] ?? ''));
        if ($filename === '') {
            continue;
        }

        return [
            'filename'  => $filename,
            'subfolder' => trim((string) ($img['subfolder'] ?? '')),
            'type'      => trim((string) ($img['type'] ?? 'output')) ?: 'output',
        ];
    }

    throw new RuntimeException('ComfyUI 输出中未找到图片文件');
}

function comfyui_wait_for_history(string $promptId, ?string $baseUrl = null, ?int $timeoutSeconds = null): array
{
    $timeoutSeconds = $timeoutSeconds ?? comfyui_poll_timeout_seconds();
    $deadline = time() + $timeoutSeconds;
    $sleepUs = 500_000;

    while (time() < $deadline) {
        $history = comfyui_get_history($promptId, $baseUrl);
        if ($history !== null) {
            $status = $history['status'] ?? null;
            if (is_array($status)) {
                $completed = !empty($status['completed']);
                $statusStr = strtolower((string) ($status['status_str'] ?? ''));
                if ($statusStr === 'error') {
                    $messages = $status['messages'] ?? [];
                    throw new RuntimeException('ComfyUI 生成失败: ' . json_encode($messages, JSON_UNESCAPED_UNICODE));
                }
                if ($completed || !empty($history['outputs'])) {
                    return $history;
                }
            } elseif (!empty($history['outputs'])) {
                return $history;
            }
        }

        usleep($sleepUs);
        if ($sleepUs < 2_000_000) {
            $sleepUs += 100_000;
        }
    }

    throw new RuntimeException('ComfyUI 生成超时（prompt_id=' . $promptId . '，已等待 ' . $timeoutSeconds . ' 秒）');
}

/** @return array{binary:string,filename:string,subfolder:string,type:string} */
function comfyui_fetch_image(string $filename, string $subfolder = '', string $type = 'output', ?string $baseUrl = null): array
{
    $qs = http_build_query([
        'filename'  => $filename,
        'subfolder' => $subfolder,
        'type'      => $type,
    ]);
    $resp = comfyui_http('GET', '/view?' . $qs, null, 120, $baseUrl);
    $bin = (string) ($resp['binary'] ?? '');
    if ($bin === '') {
        throw new RuntimeException('ComfyUI /view 返回空内容');
    }

    return [
        'binary'    => $bin,
        'filename'  => $filename,
        'subfolder' => $subfolder,
        'type'      => $type,
    ];
}

/**
 * @param array<string, mixed> $provider
 * @return array{url:string,revised_prompt?:string,comfy_prompt_id?:string}
 */
function comfyui_generate_image_for_provider(array $provider, string $prompt, string $size = '1024x1024', array $options = []): array
{
    @set_time_limit(comfyui_poll_timeout_seconds() + 60);

    require_once __DIR__ . '/image_styles.php';
    require_once __DIR__ . '/image_models.php';

    $styleKey = trim((string) ($options['style_key'] ?? ''));
    $positive = $styleKey !== ''
        ? media_compose_image_prompt($prompt, $styleKey !== '' ? $styleKey : 'default')
        : trim($prompt);

    if (!empty($options['images']) && is_array($options['images']) && $options['images'] !== []) {
        comfyui_log('reference images ignored in ComfyUI SDXL phase-1');
    }

    [$width, $height] = comfyui_parse_size($size, 1024, 1024);
    $negative = media_image_negative_prompt();
    $baseUrl = comfyui_base_url_for_provider($provider);
    $imageModel = media_image_model_resolve(trim((string) ($options['image_model'] ?? '')));

    $workflow = comfyui_build_workflow([
        'positive'       => $positive,
        'negative'       => $negative,
        'width'          => $width,
        'height'         => $height,
        'steps'          => comfyui_default_steps(),
        'cfg'            => comfyui_default_cfg(),
        'checkpoint'     => $imageModel['checkpoint'],
        'output_prefix'  => $imageModel['output_prefix'],
    ]);

    comfyui_log('model=' . $imageModel['key'] . ' checkpoint=' . $imageModel['checkpoint']);

    $promptId = comfyui_submit_prompt($workflow, $baseUrl);
    $jobId = (int) ($options['job_id'] ?? 0);
    if ($jobId > 0) {
        require_once __DIR__ . '/media_queue.php';
        media_queue_save_comfy_prompt_id($jobId, $promptId);
    }
    $history = comfyui_wait_for_history($promptId, $baseUrl);
    $out = comfyui_extract_output_image($history);
    $fetched = comfyui_fetch_image($out['filename'], $out['subfolder'], $out['type'], $baseUrl);

    $userId = (int) ($options['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('ComfyUI 保存图片需要有效 user_id');
    }

    require_once __DIR__ . '/media.php';
    $ext = strtolower(pathinfo($out['filename'], PATHINFO_EXTENSION)) ?: 'png';
    $url = media_save_ref_image_binary($fetched['binary'], $ext, 'img', $userId, $out['filename']);

    comfyui_log('completed prompt_id=' . $promptId . ' saved=' . basename(parse_url($url, PHP_URL_QUERY) ?: $url));

    return [
        'url'             => $url,
        'revised_prompt'  => $positive,
        'comfy_prompt_id' => $promptId,
    ];
}

function comfyui_resolve_admin_base_url(): string
{
    require_once __DIR__ . '/models.php';
    foreach (models_list_enabled_by_type('image') as $provider) {
        if (media_provider_is_comfyui($provider)) {
            $url = trim((string) ($provider['base_url'] ?? ''));
            if ($url !== '') {
                return rtrim($url, '/');
            }
        }
    }

    return comfyui_base_url();
}

/** @return list<string> */
function comfyui_list_checkpoints(?string $baseUrl = null): array
{
    $baseUrl = rtrim($baseUrl ?? comfyui_resolve_admin_base_url(), '/');
    if ($baseUrl === '') {
        throw new RuntimeException('未配置 ComfyUI 地址');
    }

    $ids = [];

    try {
        $json = comfyui_http('GET', '/models/checkpoints', null, 30, $baseUrl);
        if (is_array($json)) {
            foreach ($json as $item) {
                if (is_string($item)) {
                    $name = trim($item);
                } elseif (is_array($item)) {
                    $name = trim((string) ($item['filename'] ?? $item['name'] ?? $item['path'] ?? ''));
                } else {
                    continue;
                }
                if ($name !== '') {
                    $ids[$name] = true;
                }
            }
        }
    } catch (Throwable) {
        // fallback to object_info
    }

    if ($ids === []) {
        $json = comfyui_http('GET', '/object_info/CheckpointLoaderSimple', null, 60, $baseUrl);
        $ckpt = $json['CheckpointLoaderSimple']['input']['required']['ckpt_name']
            ?? $json['input']['required']['ckpt_name']
            ?? null;
        if (is_array($ckpt)) {
            $options = $ckpt[0] ?? $ckpt;
            if (is_array($options)) {
                foreach ($options as $name) {
                    if (is_string($name) && trim($name) !== '') {
                        $ids[trim($name)] = true;
                    }
                }
            }
        }
    }

    if ($ids === []) {
        throw new RuntimeException('ComfyUI 未返回 checkpoint 列表，请确认已安装模型且服务正常');
    }

    $list = array_keys($ids);
    natcasesort($list);

    return array_values($list);
}

function comfyui_checkpoint_available(string $checkpoint, ?string $baseUrl = null): bool
{
    $checkpoint = trim($checkpoint);
    if ($checkpoint === '') {
        return false;
    }
    try {
        $list = comfyui_list_checkpoints($baseUrl);
    } catch (Throwable) {
        return false;
    }

    return in_array($checkpoint, $list, true);
}

function comfyui_test_connection(?string $baseUrl = null): array
{
    $baseUrl = rtrim($baseUrl ?? comfyui_resolve_admin_base_url(), '/');
    $json = comfyui_http('GET', '/system_stats', null, 15, $baseUrl);
    $checkpoints = comfyui_list_checkpoints($baseUrl);

    return [
        'base_url'          => $baseUrl,
        'system'            => $json['system'] ?? null,
        'checkpoint_count'  => count($checkpoints),
        'checkpoints'       => $checkpoints,
    ];
}

