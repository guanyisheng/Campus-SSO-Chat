<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/models.php';

function model_normalize_base_url(string $url): string
{
    $baseUrl = rtrim(trim($url), '/');
    return rtrim(preg_replace('#/chat/completions/?$#', '', $baseUrl) ?? $baseUrl, '/');
}

function model_exists_by_name(string $modelName, string $type): bool
{
    models_fix_schema();
    $type = model_normalize_type($type);
    $stmt = db()->prepare(
        'SELECT id FROM llm_models WHERE model_type = ? AND model_name = ? LIMIT 1'
    );
    $stmt->execute([$type, trim($modelName)]);
    return (bool) $stmt->fetchColumn();
}

/**
 * @return list<string>
 */
function model_remote_list(string $baseUrl, string $apiKey): array
{
    $base = model_normalize_base_url($baseUrl);
    if ($base === '') {
        throw new InvalidArgumentException('请填写 API Base URL');
    }

    $url = $base . '/models';
    $headers = ['Accept: application/json'];
    $apiKey = trim($apiKey);
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('服务器未启用 cURL，无法请求模型列表');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => 'GET',
    ]);

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('请求失败: ' . $err);
    }

    $json = json_decode($raw, true);
    if ($code >= 400) {
        $msg = is_array($json)
            ? ($json['error']['message'] ?? $json['error'] ?? $json['message'] ?? $raw)
            : $raw;
        $msgStr = is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE);
        throw new RuntimeException('API 错误 (' . $code . '): ' . $msgStr);
    }

    if (!is_array($json)) {
        throw new RuntimeException('API 返回格式无效');
    }

    $ids = [];
    $items = $json['data'] ?? $json['models'] ?? $json;
    if (!is_array($items)) {
        throw new RuntimeException('未找到模型列表');
    }

    foreach ($items as $item) {
        if (is_string($item)) {
            $id = trim($item);
        } elseif (is_array($item)) {
            $id = trim((string) ($item['id'] ?? $item['model'] ?? $item['name'] ?? ''));
        } else {
            continue;
        }
        if ($id !== '') {
            $ids[$id] = true;
        }
    }

    $list = array_keys($ids);
    natcasesort($list);
    return array_values($list);
}

/**
 * @param list<string> $modelNames
 * @return array{added:int, skipped:int}
 */
function model_bulk_create(
    string $baseUrl,
    string $apiKey,
    array $modelNames,
    string $type,
    int $sortStart = 0
): array {
    $added = 0;
    $skipped = 0;
    $sort = $sortStart;
    $type = model_normalize_type($type);

    foreach ($modelNames as $modelName) {
        $modelName = trim((string) $modelName);
        if ($modelName === '') {
            continue;
        }
        if (model_exists_by_name($modelName, $type)) {
            $skipped++;
            continue;
        }
        $displayName = $modelName;
        model_create($displayName, $baseUrl, $modelName, $apiKey, $sort, $type);
        $added++;
        $sort++;
    }

    return ['added' => $added, 'skipped' => $skipped];
}
