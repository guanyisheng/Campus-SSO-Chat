<?php
declare(strict_types=1);

function branding_storage_dir(): string
{
    $dir = defined('BRANDING_STORAGE_DIR')
        ? BRANDING_STORAGE_DIR
        : (dirname(__DIR__) . '/storage/branding');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/** @return list<string> */
function branding_allowed_ext(): array
{
    return ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'ico'];
}

function branding_mime_for_ext(string $ext): string
{
    return match (strtolower($ext)) {
        'png'  => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        default => 'application/octet-stream',
    };
}

function branding_asset_public_url(string $filename): string
{
    $filename = basename($filename);
    if ($filename === '') {
        return '';
    }
    return site_base_url() . '/api/branding_asset.php?f=' . rawurlencode($filename);
}

/**
 * @return array{filename:string,url:string}
 */
function branding_save_upload(array $file, string $prefix = 'asset'): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('上传失败');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('图片不能超过 2MB');
    }

    $name = (string) ($file['name'] ?? 'upload.bin');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, branding_allowed_ext(), true)) {
        throw new InvalidArgumentException('仅支持 png、jpg、webp、gif、svg、ico');
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? finfo_file($finfo, (string) ($file['tmp_name'] ?? '')) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowedMimes = array_map('branding_mime_for_ext', branding_allowed_ext());
    if ($mime !== '' && !in_array($mime, $allowedMimes, true) && $mime !== 'image/x-icon') {
        throw new InvalidArgumentException('文件类型无效');
    }

    $filename = preg_replace('/[^a-z0-9_-]+/i', '-', $prefix) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = branding_storage_dir() . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new InvalidArgumentException('保存文件失败');
    }

    return ['filename' => $filename, 'url' => branding_asset_public_url($filename)];
}

function branding_resolve_file_url(string $storedFile, string $externalUrl): string
{
    $externalUrl = trim($externalUrl);
    if ($externalUrl !== '') {
        return $externalUrl;
    }
    $storedFile = trim($storedFile);
    if ($storedFile === '') {
        return '';
    }
    $path = branding_storage_dir() . DIRECTORY_SEPARATOR . basename($storedFile);
    if (!is_file($path)) {
        return '';
    }
    return branding_asset_public_url($storedFile);
}

function branding_delete_file(string $filename): void
{
    $filename = basename(trim($filename));
    if ($filename === '') {
        return;
    }
    $path = branding_storage_dir() . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path)) {
        unlink($path);
    }
}
