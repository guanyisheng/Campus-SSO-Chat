<?php
declare(strict_types=1);

/** API 请求：禁止 HTML 形式报错污染 JSON 响应 */
function api_json_begin(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ini_set('display_errors', '0');
    ini_set('html_errors', '0');

    if (ob_get_level() === 0) {
        ob_start();
    }
}

function api_json_discard_buffer(): void
{
    while (ob_get_level() > 0) {
        $buf = ob_get_clean();
        if (is_string($buf) && trim($buf) !== '') {
            error_log('API stray output: ' . substr($buf, 0, 1000));
        }
    }
}

function api_json_headers(): void
{
    api_json_discard_buffer();
    header('Content-Type: application/json; charset=utf-8');
}

function api_is_request(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return str_contains($script, '/api/');
}

function api_json_error(int $status, string $message): never
{
    api_json_discard_buffer();
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
