<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/code_runner.php';

api_json_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_login();
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$lang = strtolower(trim((string) ($input['language'] ?? '')));
$code = (string) ($input['code'] ?? '');

$allowed = [
    'javascript' => 'javascript',
    'python'     => 'python',
    'php'        => 'php',
    'java'       => 'java',
    'c'          => 'c',
    'cpp'        => 'cpp',
    'go'         => 'go',
    'bash'       => 'bash',
];

if (!isset($allowed[$lang])) {
    http_response_code(400);
    echo json_encode(['error' => '不支持该语言'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (trim($code) === '') {
    http_response_code(400);
    echo json_encode(['error' => '代码为空'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strlen($code) > 16384) {
    http_response_code(400);
    echo json_encode(['error' => '代码过长（最多 16KB）'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($lang === 'javascript') {
    http_response_code(400);
    echo json_encode(['error' => 'JavaScript 请在浏览器本地运行'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = code_run_execute($allowed[$lang], $code);
    echo json_encode([
        'stdout'    => $result['stdout'],
        'stderr'    => $result['stderr'],
        'exit_code' => $result['exit_code'],
        'language'  => $result['language'],
    ], JSON_UNESCAPED_UNICODE);
} catch (CodeRunException $e) {
    http_response_code(502);
    echo json_encode([
        'error'     => $e->getMessage(),
        'stdout'    => $e->stdout,
        'stderr'    => $e->stderr,
        'exit_code' => $e->exitCode,
    ], JSON_UNESCAPED_UNICODE);
}
