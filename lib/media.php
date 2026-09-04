<?php
declare(strict_types=1);

require_once __DIR__ . '/models.php';

/** @return list<string> */
function media_mention_aliases(string $type): array
{
    require_once __DIR__ . '/settings.php';
    $key = $type === 'video' ? 'video_mention_aliases' : 'image_mention_aliases';
    $defaults = $type === 'video' ? '@视频,@video,@生视频' : '@图片,@image,@生图';
    $raw = setting($key, $defaults);
    $parts = preg_split('/[,，\s]+/u', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out ?: explode(',', $defaults);
}

/**
 * @return array{type:string,prompt:string}|null
 */
function media_parse_mention(string $text): ?array
{
    $trimmed = ltrim($text);
    if ($trimmed === '') {
        return null;
    }

    foreach (['image', 'video'] as $type) {
        foreach (media_mention_aliases($type) as $alias) {
            $alias = trim($alias);
            if ($alias === '') {
                continue;
            }
            $lowerText = mb_strtolower($trimmed);
            $lowerAlias = mb_strtolower($alias);
            if (str_starts_with($lowerText, $lowerAlias)) {
                $prompt = trim(mb_substr($trimmed, mb_strlen($alias)));
                if ($prompt === '') {
                    throw new InvalidArgumentException(
                        $type === 'image' ? '请在 @图片 后输入画面描述' : '请在 @视频 后输入视频描述'
                    );
                }
                return ['type' => $type, 'prompt' => $prompt];
            }
        }
    }

    return null;
}

function media_default_provider(string $type): ?array
{
    $list = models_list_enabled_by_type($type);
    return $list[0] ?? null;
}

function media_provider_get(int $id, string $type): ?array
{
    $m = model_get($id, true);
    if (!$m || ($m['model_type'] ?? 'chat') !== $type) {
        return null;
    }
    return $m;
}

/** @param array<string, mixed> $provider */
function media_provider_api_key(array $provider): string
{
    $key = trim((string) ($provider['api_key'] ?? ''));
    if ($key !== '') {
        return $key;
    }
    if (defined('AGNES_API_KEY') && AGNES_API_KEY !== '') {
        return (string) AGNES_API_KEY;
    }
    if (defined('OLLAMA_API_KEY') && OLLAMA_API_KEY !== '') {
        return (string) OLLAMA_API_KEY;
    }
    return '';
}

/** @param array<string, mixed> $provider */
function media_agnes_origin(array $provider): string
{
    $base = rtrim((string) ($provider['base_url'] ?? ''), '/');
    if (preg_match('#^(https?://[^/]+)#i', $base, $m)) {
        return $m[1];
    }
    return 'https://apihub.agnes-ai.com';
}

/** @param array<string, mixed> $provider */
function media_http_json_url(string $method, string $url, array $provider, ?array $body = null, int $timeout = 120): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $apiKey = media_provider_api_key($provider);
    if ($apiKey === '') {
        throw new RuntimeException('生图/生视频 API 未配置密钥，请在管理后台填写 API Key');
    }
    $headers[] = 'Authorization: Bearer ' . $apiKey;

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('媒体 API 请求失败: ' . $err);
    }

    $json = json_decode($raw, true);
    if ($code >= 400) {
        $msg = is_array($json)
            ? ($json['error']['message'] ?? $json['error'] ?? $json['message'] ?? $raw)
            : $raw;
        $msgStr = is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE);
        if ($code === 429) {
            throw new RuntimeException('视频服务请求过于频繁，请稍后再试（上游 API 限流）');
        }
        if ($code === 401 || $code === 403) {
            throw new RuntimeException('媒体 API 鉴权失败，请检查后台 API Key 配置');
        }
        throw new RuntimeException('媒体 API 错误 (' . $code . '): ' . $msgStr);
    }

    return is_array($json) ? $json : [];
}

/** @param array<string, mixed> $provider */
function media_http_json(string $method, array $provider, string $path, ?array $body = null, int $timeout = 120): array
{
    $base = rtrim((string) $provider['base_url'], '/');
    $path = '/' . ltrim($path, '/');
    return media_http_json_url($method, $base . $path, $provider, $body, $timeout);
}

/** @return array{url:string,revised_prompt?:string,comfy_prompt_id?:string} */
function media_generate_image(array $provider, string $prompt, string $size = '1024x768', array $options = []): array
{
    require_once __DIR__ . '/comfyui.php';

    if (media_provider_is_comfyui($provider)) {
        return comfyui_generate_image_for_provider($provider, $prompt, $size, $options);
    }

    require_once __DIR__ . '/image_styles.php';

    $styleKey = trim((string) ($options['style_key'] ?? ''));
    if ($styleKey === '' && !empty($options['style'])) {
        $legacy = trim((string) $options['style']);
        if ($legacy !== '') {
            $prompt = $prompt . '，' . $legacy;
        }
    } else {
        $prompt = media_compose_image_prompt($prompt, $styleKey !== '' ? $styleKey : 'default');
    }

    $payload = [
        'model'  => (string) $provider['model_name'],
        'prompt' => $prompt,
        'size'   => $size,
        'extra_body' => [
            'response_format' => 'url',
        ],
    ];

    $negative = media_image_negative_prompt();
    if ($negative !== '') {
        $payload['extra_body']['negative_prompt'] = $negative;
    }

    if (!empty($options['images']) && is_array($options['images'])) {
        $payload['extra_body']['image'] = array_values($options['images']);
    }

    if (!empty($options['return_base64'])) {
        unset($payload['extra_body']['response_format']);
        $payload['return_base64'] = true;
    }

    $json = media_http_json('POST', $provider, '/images/generations', $payload, 180);
    $item = $json['data'][0] ?? null;
    if (!is_array($item)) {
        throw new RuntimeException('生图 API 未返回图片数据');
    }

    if (!empty($item['url'])) {
        return [
            'url'            => (string) $item['url'],
            'revised_prompt' => isset($item['revised_prompt']) ? (string) $item['revised_prompt'] : '',
        ];
    }

    if (!empty($item['b64_json'])) {
        $ownerId = (int) ($options['user_id'] ?? 0);
        $saved = media_save_base64_image((string) $item['b64_json'], $ownerId);
        return ['url' => $saved];
    }

    throw new RuntimeException('生图 API 响应缺少 url 或 b64_json');
}

function media_save_base64_image(string $b64, int $userId): string
{
    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '') {
        throw new RuntimeException('图片数据无效');
    }

    return media_save_ref_image_binary($bin, 'png', 'img', $userId);
}

/** @return array{tmp:string,ext:string,name:string} */
function media_validate_ref_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('图片上传失败');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new InvalidArgumentException('图片文件为空');
    }
    if ($size > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('参考图不能超过 10MB');
    }

    $name = (string) ($file['name'] ?? 'reference.jpg');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowed, true)) {
        throw new InvalidArgumentException('仅支持 JPG、PNG、WebP、GIF 参考图');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('无效的上传文件');
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string) finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }
    $okMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if ($mime !== '' && !in_array($mime, $okMimes, true)) {
        throw new InvalidArgumentException('文件不是有效图片');
    }

    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }

    return ['tmp' => $tmp, 'ext' => $ext, 'name' => $name];
}

function media_user_media_dir(int $userId): string
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('无效用户');
    }
    require_once __DIR__ . '/conv_storage.php';

    return conv_storage_user_dir($userId) . DIRECTORY_SEPARATOR . 'media';
}

function media_user_media_url(int $userId, string $filename): string
{
    require_once __DIR__ . '/site.php';

    return site_api_path('user_media.php', ['f' => $filename]);
}

function media_sanitize_stored_filename(string $filename, string $defaultExt = 'png'): string
{
    $filename = basename(trim($filename));
    $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $filename) ?? '';
    $filename = trim($filename, '._-');
    if ($filename === '') {
        return '';
    }
    if (!preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $filename)) {
        $filename .= '.' . preg_replace('/[^a-z0-9]/', '', strtolower($defaultExt));
    }
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*\.(jpg|jpeg|png|webp|gif)$/i', $filename)) {
        return '';
    }

    return $filename;
}

function media_save_ref_image_binary(string $bin, string $ext = 'png', string $prefix = 'ref', int $userId = 0, ?string $preferredFilename = null): string
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('无效用户');
    }

    $ext = preg_replace('/[^a-z0-9]/', '', strtolower($ext)) ?: 'png';
    $prefix = preg_replace('/[^a-z0-9_]/', '', strtolower($prefix)) ?: 'ref';
    $dir = media_user_media_dir($userId);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建媒体存储目录');
    }

    $preferred = $preferredFilename !== null ? media_sanitize_stored_filename($preferredFilename, $ext) : '';
    if ($preferred !== '') {
        $name = $preferred;
        if (is_file($dir . DIRECTORY_SEPARATOR . $name)) {
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $fileExt = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: $ext;
            $name = $stem . '_' . bin2hex(random_bytes(2)) . '.' . $fileExt;
        }
    } else {
        $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    }
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    if (file_put_contents($path, $bin) === false) {
        throw new RuntimeException('保存参考图失败');
    }

    return media_user_media_url($userId, $name);
}

function media_save_ref_upload(string $tmpPath, string $ext, int $userId): string
{
    $bin = file_get_contents($tmpPath);
    if ($bin === false || $bin === '') {
        throw new RuntimeException('无法读取参考图');
    }
    return media_save_ref_image_binary($bin, $ext, 'ref', $userId);
}

/** 读取当前用户媒体文件路径（仅允许文件名，禁止路径穿越） */
function media_user_media_file_path(int $userId, string $filename): ?string
{
    if ($userId <= 0) {
        return null;
    }
    $filename = basename($filename);
    if ($filename === '' || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*\.(jpg|jpeg|png|webp|gif)$/i', $filename)) {
        return null;
    }
    $path = media_user_media_dir($userId) . DIRECTORY_SEPARATOR . $filename;

    return is_file($path) ? $path : null;
}

/** @param mixed $input @return list<string> */
function media_normalize_image_urls($input): array
{
    if (!is_array($input)) {
        return [];
    }
    $out = [];
    foreach ($input as $url) {
        $url = trim((string) $url);
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $out[] = $url;
        }
    }
    return array_values(array_slice($out, 0, 4));
}

/** @return array{task_id:string,video_id:string,status:string,progress?:int} */
function media_create_video_task(array $provider, string $prompt, array $options = []): array
{
    $payload = [
        'model'      => (string) $provider['model_name'],
        'prompt'     => $prompt,
        'width'      => (int) ($options['width'] ?? 1152),
        'height'     => (int) ($options['height'] ?? 768),
        'num_frames' => (int) ($options['num_frames'] ?? 121),
        'frame_rate' => (int) ($options['frame_rate'] ?? 24),
    ];

    if (!empty($options['images']) && is_array($options['images'])) {
        $payload['extra_body'] = ['image' => array_values($options['images'])];
    }

    $json = media_http_json('POST', $provider, '/videos', $payload, 60);
    $taskId = (string) ($json['task_id'] ?? $json['id'] ?? '');
    $videoId = (string) ($json['video_id'] ?? '');
    if ($taskId === '' && $videoId === '') {
        throw new RuntimeException('视频 API 未返回任务 ID');
    }

    return [
        'task_id'  => $taskId !== '' ? $taskId : $videoId,
        'video_id' => $videoId,
        'status'   => (string) ($json['status'] ?? 'queued'),
        'progress' => isset($json['progress']) ? (int) $json['progress'] : 0,
    ];
}

function media_extract_video_url(array $json): string
{
    foreach (['remixed_from_video_id', 'video_url', 'url', 'output_url', 'download_url'] as $key) {
        $val = trim((string) ($json[$key] ?? ''));
        if ($val !== '' && preg_match('#^https?://#i', $val)) {
            return $val;
        }
    }
    foreach (['data', 'output', 'result'] as $nest) {
        if (!empty($json[$nest]) && is_array($json[$nest])) {
            $inner = media_extract_video_url($json[$nest]);
            if ($inner !== '') {
                return $inner;
            }
        }
    }
    return '';
}

function media_video_status_is_done(string $status): bool
{
    return in_array(strtolower($status), ['completed', 'succeeded', 'success', 'done', 'finished'], true);
}

function media_video_status_is_failed(string $status): bool
{
    return in_array(strtolower($status), ['failed', 'error', 'cancelled', 'canceled'], true);
}

/** @return array{status:string,video_url?:string,error?:string,progress?:int} */
function media_parse_video_poll_response(array $json): array
{
    $status = media_normalize_video_status($json);
    $url = media_extract_video_url($json);

    if ($url !== '' && !media_video_status_is_failed($status)) {
        $status = 'completed';
    }

    $out = [
        'status'   => $status,
        'progress' => isset($json['progress']) ? (int) $json['progress'] : null,
    ];
    if ($url !== '') {
        $out['video_url'] = $url;
    }
    if (!empty($json['error'])) {
        $out['error'] = is_string($json['error']) ? $json['error'] : json_encode($json['error'], JSON_UNESCAPED_UNICODE);
    }
    return $out;
}

function media_normalize_video_status(array $json): string
{
    return strtolower((string) ($json['status'] ?? 'unknown'));
}

/** @return array{status:string,video_url?:string,error?:string,progress?:int} */
function media_poll_video_task(array $provider, string $taskId, string $videoId = '', string $modelName = ''): array
{
    $taskId = trim($taskId);
    $videoId = trim($videoId);
    $modelName = $modelName !== '' ? $modelName : (string) ($provider['model_name'] ?? '');
    $lastError = null;

    if ($taskId !== '') {
        try {
            return media_parse_video_poll_response(
                media_http_json('GET', $provider, '/videos/' . rawurlencode($taskId), null, 60)
            );
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
        }
    }

    if ($videoId !== '' && $videoId !== $taskId) {
        try {
            $origin = media_agnes_origin($provider);
            $url = $origin . '/agnesapi?video_id=' . rawurlencode($videoId);
            if ($modelName !== '') {
                $url .= '&model_name=' . rawurlencode($modelName);
            }
            return media_parse_video_poll_response(
                media_http_json_url('GET', $url, $provider, null, 60)
            );
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
        }
    }

    if ($lastError !== null) {
        throw new RuntimeException($lastError);
    }

    throw new RuntimeException('缺少可查询的视频任务 ID');
}

function media_assistant_image_markdown(string $url, string $prompt): string
{
    $safePrompt = str_replace(['[', ']'], ['\\[', '\\]'], $prompt);
    return "![生成图片]({$url})\n\n> {$safePrompt}";
}

function media_assistant_video_markdown(string $url, string $prompt): string
{
    $safePrompt = str_replace(['[', ']'], ['\\[', '\\]'], $prompt);
    return "[生成视频]({$url})\n\n> {$safePrompt}";
}

function media_text_pending_marker(): string
{
    return '<!--text-pending-->';
}

function media_parse_text_pending(string $content): bool
{
    return trim($content) === '<!--text-pending-->';
}

function media_queue_pending_marker(int $queueId, string $jobType, string $prompt, int $providerId): string
{
    $payload = base64_encode(json_encode([
        'queue_id'    => $queueId,
        'job_type'    => $jobType,
        'provider_id' => $providerId,
        'prompt'      => $prompt,
    ], JSON_UNESCAPED_UNICODE));

    return '<!--queue-pending:' . $payload . '-->';
}

/** @return array<string, mixed>|null */
function media_parse_queue_pending(string $content): ?array
{
    $content = trim($content);
    if (!preg_match('/^<!--queue-pending:([A-Za-z0-9+\/=]+)-->$/', $content, $m)) {
        return null;
    }
    $json = base64_decode($m[1], true);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['queue_id'])) {
        return null;
    }
    return $data;
}

function media_video_pending_marker(string $taskId, int $providerId, string $prompt, string $videoId = ''): string
{
    $payload = base64_encode(json_encode([
        'task_id'     => $taskId,
        'video_id'    => $videoId,
        'provider_id' => $providerId,
        'prompt'      => $prompt,
    ], JSON_UNESCAPED_UNICODE));

    return '<!--video-pending:' . $payload . '-->';
}

function media_parse_video_pending(string $content): ?array
{
    $content = trim($content);
    if (!preg_match('/^<!--video-pending:([A-Za-z0-9+\/=]+)-->$/', $content, $m)) {
        return null;
    }
    $json = base64_decode($m[1], true);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    if (empty($data['task_id']) && empty($data['video_id'])) {
        return null;
    }
    return $data;
}
