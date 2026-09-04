<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/agents.php';
require_once dirname(__DIR__) . '/lib/branding_storage.php';

$filename = basename((string) ($_GET['f'] ?? ''));
if ($filename === '') {
    http_response_code(404);
    exit;
}

$path = '';
foreach (agents_avatar_storage_dirs() as $dir) {
    $candidate = $dir . DIRECTORY_SEPARATOR . $filename;
    if (is_file($candidate)) {
        $path = $candidate;
        break;
    }
}
if ($path === '') {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
header('Content-Type: ' . branding_mime_for_ext($ext));
header('Cache-Control: public, max-age=86400');
readfile($path);
