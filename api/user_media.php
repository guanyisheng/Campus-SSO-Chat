<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/media.php';

require_login();
$user = current_user();
if (!$user) {
    http_response_code(401);
    exit;
}

$userId = (int) ($user['id'] ?? 0);
$filename = basename((string) ($_GET['f'] ?? ''));
$path = media_user_media_file_path($userId, $filename);
if (!$path) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    'webp'        => 'image/webp',
    'gif'         => 'image/gif',
    default       => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
