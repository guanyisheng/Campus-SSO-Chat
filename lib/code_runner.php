<?php
declare(strict_types=1);

/**
 * 编程模式代码执行 — 优先本机 Python/Java，可选 Piston 远程备用
 */

final class CodeRunException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        public readonly ?int $exitCode = null,
    ) {
        parent::__construct($message);
    }
}

function code_run_timeout_sec(): int
{
    return defined('CODE_RUN_TIMEOUT_SEC') ? max(3, (int) CODE_RUN_TIMEOUT_SEC) : 8;
}

function code_run_which(string $bin): ?string
{
    $bin = trim($bin);
    if ($bin === '') {
        return null;
    }
    if (str_contains($bin, '/') || str_contains($bin, '\\')) {
        return is_executable($bin) ? $bin : null;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $out = shell_exec('where ' . escapeshellarg($bin) . ' 2>nul');
    } else {
        $out = shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
    }
    if (is_string($out) && trim($out) !== '') {
        $line = trim(explode("\n", trim($out))[0]);
        if ($line !== '' && is_executable($line)) {
            return $line;
        }
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        foreach (['/usr/bin/' . $bin, '/usr/local/bin/' . $bin] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
    }

    return null;
}

function code_run_python_bin(): ?string
{
    if (defined('CODE_RUN_PYTHON_BIN') && CODE_RUN_PYTHON_BIN !== '') {
        return code_run_which((string) CODE_RUN_PYTHON_BIN);
    }
    return code_run_which('python3') ?? code_run_which('python');
}

function code_run_java_bins(): ?array
{
    if (defined('CODE_RUN_JAVA_HOME') && CODE_RUN_JAVA_HOME !== '') {
        $home = rtrim((string) CODE_RUN_JAVA_HOME, '/\\');
        $javac = $home . (PHP_OS_FAMILY === 'Windows' ? '\\bin\\javac.exe' : '/bin/javac');
        $java = $home . (PHP_OS_FAMILY === 'Windows' ? '\\bin\\java.exe' : '/bin/java');
        if (is_executable($javac) && is_executable($java)) {
            return ['javac' => $javac, 'java' => $java];
        }
    }
    $javac = code_run_which('javac');
    $java = code_run_which('java');
    if ($javac && $java) {
        return ['javac' => $javac, 'java' => $java];
    }
    return null;
}

function code_run_php_bin(): ?string
{
    if (defined('CODE_RUN_PHP_BIN') && CODE_RUN_PHP_BIN !== '') {
        return code_run_which((string) CODE_RUN_PHP_BIN);
    }
    return code_run_which('php') ?? (PHP_BINARY !== '' ? PHP_BINARY : null);
}

function code_run_temp_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'campus_code_' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new CodeRunException('无法创建临时目录');
    }
    return $dir;
}

function code_run_cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** @param list<string> $cmd */
function code_run_process(array $cmd, string $cwd, int $timeoutSec): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open(
        $cmd,
        $descriptors,
        $pipes,
        $cwd,
        null,
        PHP_OS_FAMILY === 'Windows' ? [] : ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        throw new CodeRunException('无法启动运行进程');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = time();

    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (time() - $start >= $timeoutSec) {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new CodeRunException('运行超时（' . $timeoutSec . ' 秒）', $stdout, $stderr, 124);
        }
        usleep(40000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'stdout'    => $stdout,
        'stderr'    => $stderr,
        'exit_code' => $exitCode,
    ];
}

/** @return array{filename:string, content:string, class:string} */
function code_run_java_prepare(string $code): array
{
    if (preg_match('/public\s+class\s+(\w+)/', $code, $m)) {
        $class = $m[1];
        return [
            'filename' => $class . '.java',
            'content'  => $code,
            'class'    => $class,
        ];
    }

    if (preg_match('/\bclass\s+(\w+)/', $code, $m)) {
        $class = $m[1];
        $content = preg_replace('/\bclass\s+' . preg_quote($class, '/') . '\b/', 'public class ' . $class, $code, 1) ?? $code;
        return [
            'filename' => $class . '.java',
            'content'  => $content,
            'class'    => $class,
        ];
    }

    $trimmed = trim($code);
    if (str_contains($trimmed, 'public static void main')) {
        $content = "public class Main {\n" . $trimmed . "\n}\n";
    } else {
        $lines = explode("\n", $trimmed);
        $body = '';
        foreach ($lines as $line) {
            $body .= '        ' . rtrim($line) . "\n";
        }
        $content = "public class Main {\n    public static void main(String[] args) throws Exception {\n"
            . $body
            . "    }\n}\n";
    }

    return [
        'filename' => 'Main.java',
        'content'  => $content,
        'class'    => 'Main',
    ];
}

function code_run_python(string $code): array
{
    $bin = code_run_python_bin();
    if (!$bin) {
        throw new CodeRunException('服务器未安装 Python（python3），无法运行');
    }

    $dir = code_run_temp_dir();
    try {
        $file = $dir . DIRECTORY_SEPARATOR . 'main.py';
        file_put_contents($file, $code);
        $result = code_run_process([$bin, $file], $dir, code_run_timeout_sec());
        $result['language'] = 'python';
        return $result;
    } finally {
        code_run_cleanup($dir);
    }
}

function code_run_java(string $code): array
{
    $bins = code_run_java_bins();
    if (!$bins) {
        throw new CodeRunException('服务器未安装 Java（javac/java），无法运行');
    }

    $prepared = code_run_java_prepare($code);
    $dir = code_run_temp_dir();
    try {
        $src = $dir . DIRECTORY_SEPARATOR . $prepared['filename'];
        file_put_contents($src, $prepared['content']);

        $compile = code_run_process([$bins['javac'], $prepared['filename']], $dir, code_run_timeout_sec());
        if (($compile['exit_code'] ?? 1) !== 0) {
            throw new CodeRunException(
                'Java 编译失败',
                (string) ($compile['stdout'] ?? ''),
                (string) ($compile['stderr'] ?? ''),
                (int) ($compile['exit_code'] ?? 1)
            );
        }

        $run = code_run_process(
            [$bins['java'], '-cp', $dir, $prepared['class']],
            $dir,
            code_run_timeout_sec()
        );
        $run['language'] = 'java';
        return $run;
    } finally {
        code_run_cleanup($dir);
    }
}

function code_run_php(string $code): array
{
    $bin = code_run_php_bin();
    if (!$bin) {
        throw new CodeRunException('服务器未找到 PHP 可执行文件');
    }

    $dir = code_run_temp_dir();
    try {
        $file = $dir . DIRECTORY_SEPARATOR . 'main.php';
        $wrapped = str_starts_with(trim($code), '<?php') ? $code : "<?php\n" . $code;
        file_put_contents($file, $wrapped);
        $result = code_run_process([$bin, $file], $dir, code_run_timeout_sec());
        $result['language'] = 'php';
        return $result;
    } finally {
        code_run_cleanup($dir);
    }
}

function code_run_piston(string $lang, string $code): array
{
    $url = defined('PISTON_API_URL') && PISTON_API_URL !== ''
        ? (string) PISTON_API_URL
        : 'https://emkc.org/api/v2/piston/execute';

    $payload = json_encode([
        'language' => $lang,
        'version'  => '*',
        'files'    => [['content' => $code]],
    ], JSON_UNESCAPED_UNICODE);

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if (defined('PISTON_API_TOKEN') && PISTON_API_TOKEN !== '') {
        $headers[] = 'Authorization: Bearer ' . PISTON_API_TOKEN;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => code_run_timeout_sec() + 6,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new CodeRunException('远程运行服务不可用: ' . $curlErr);
    }

    $data = json_decode($raw, true);
    if ($httpCode === 401 || $httpCode === 403) {
        throw new CodeRunException(
            '远程运行服务需要 API Token（请在 config.php 配置 PISTON_API_TOKEN，或安装本机 Python/Java）'
        );
    }
    if ($httpCode >= 400 || !is_array($data)) {
        $msg = is_array($data) ? (string) ($data['message'] ?? $data['error'] ?? '') : '';
        throw new CodeRunException(
            $msg !== '' ? ('远程运行失败: ' . $msg) : '远程运行服务返回异常（HTTP ' . $httpCode . '）'
        );
    }

    return [
        'stdout'    => (string) ($data['run']['output'] ?? ''),
        'stderr'    => (string) ($data['run']['stderr'] ?? ''),
        'exit_code' => $data['run']['code'] ?? null,
        'language'  => $lang,
    ];
}

/** @return array{stdout:string, stderr:string, exit_code:int|null, language:string} */
function code_run_execute(string $lang, string $code): array
{
    $lang = strtolower(trim($lang));
    $localFirst = ['python', 'java', 'php'];
    $errors = [];

    if (in_array($lang, $localFirst, true)) {
        try {
            return match ($lang) {
                'python' => code_run_python($code),
                'java'   => code_run_java($code),
                'php'    => code_run_php($code),
                default  => throw new CodeRunException('不支持该语言'),
            };
        } catch (CodeRunException $e) {
            $errors[] = $e->getMessage();
            if ($e->getStderr() !== '' || $e->getStdout() !== '') {
                throw $e;
            }
        }
    }

    if (defined('PISTON_API_TOKEN') && PISTON_API_TOKEN !== '') {
        return code_run_piston($lang, $code);
    }

    if (in_array($lang, ['c', 'cpp', 'go', 'bash'], true)) {
        try {
            return code_run_piston($lang, $code);
        } catch (CodeRunException $e) {
            $errors[] = $e->getMessage();
        }
    }

    $hint = $errors !== [] ? implode('；', $errors) : '运行环境未配置';
    throw new CodeRunException(
        $hint . '。请在服务器安装 Python3 / JDK，或在 config.php 填写 PISTON_API_TOKEN。'
    );
}
