<?php
declare(strict_types=1);

/**
 * 站点根 URL。config 里仍是 example 占位域名时，自动用当前浏览器访问地址。
 */

function site_path_looks_like_filesystem(string $path): bool
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '') {
        return false;
    }
    if (preg_match('#^[A-Za-z]:/#', $path)) {
        return true;
    }

    return (bool) preg_match('#^/(Users|home/[^/]+/|private/var)/#', $path);
}

function site_url_looks_like_filesystem(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    if (preg_match('#^https?://#i', $url)) {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return site_path_looks_like_filesystem($path);
    }

    return site_path_looks_like_filesystem($url);
}

function site_url_is_placeholder(string $url): bool
{
    $url = strtolower(trim($url));
    if ($url === '' || $url === 'http://localhost' || $url === 'https://localhost') {
        return true;
    }
    return (bool) preg_match(
        '#example\.(edu\.)?(cn|com)|\.example\.|your-domain|chat\.example#',
        $url
    );
}

/** 从 SCRIPT_NAME 推导 Web 路径；拒绝磁盘路径；/api 下运行时回退到站点根 */
function site_normalize_web_base_path(string $dir): string
{
    if ($dir === '/' || $dir === '\\' || $dir === '.' || trim($dir) === '') {
        return '';
    }
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    if (site_path_looks_like_filesystem($dir)) {
        return '';
    }
    if ($dir === '/api' || preg_match('#/api$#', $dir)) {
        $parent = dirname($dir);
        if ($parent === '/' || $parent === '.' || $parent === '\\') {
            return '';
        }
        $parent = rtrim(str_replace('\\', '/', $parent), '/');

        return site_path_looks_like_filesystem($parent) ? '' : $parent;
    }

    return $dir;
}

/** 非站点根的 PHP 路由目录（静态资源在上一级 Web 根） */
function site_internal_route_segments(): array
{
    return ['admin', 'api', 'auth'];
}

function site_strip_internal_route_suffix(string $dir): string
{
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    if ($dir === '' || $dir === '/') {
        return '';
    }
    foreach (site_internal_route_segments() as $segment) {
        $suffix = '/' . $segment;
        if ($dir === $suffix || str_ends_with($dir, $suffix)) {
            $parent = substr($dir, 0, -strlen($suffix));
            $parent = rtrim($parent, '/');

            return site_path_looks_like_filesystem($parent) ? '' : $parent;
        }
    }

    return $dir;
}

function site_request_script_base_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = dirname($script);
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        return '';
    }
    $dir = site_strip_internal_route_suffix(rtrim(str_replace('\\', '/', $dir), '/'));
    if ($dir === '') {
        return '';
    }

    return site_normalize_web_base_path($dir);
}

function site_configured_base_path(): string
{
    $configured = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
    if ($configured === '' || site_url_is_placeholder($configured) || site_url_looks_like_filesystem($configured)) {
        return '';
    }
    $parsed = parse_url($configured);
    if (!is_array($parsed)) {
        return '';
    }
    $path = isset($parsed['path']) && $parsed['path'] !== '/' ? rtrim((string) $parsed['path'], '/') : '';
    if (site_path_looks_like_filesystem($path)) {
        return '';
    }

    return site_normalize_web_base_path($path);
}

function site_url_from_request(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . site_request_script_base_path();
}

function site_base_url(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $configured = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
    if ($configured !== ''
        && !site_url_is_placeholder($configured)
        && !site_url_looks_like_filesystem($configured)
        && preg_match('#^https?://#i', $configured)) {
        $cached = $configured;
        return $cached;
    }

    $cached = site_url_from_request();
    return $cached;
}

/** 站点 Web 根路径（去掉误配的 /api 后缀，避免 /api/api/...） */
function site_app_base_path(): string
{
    return site_normalize_web_base_path(site_base_path());
}

/** 静态资源路径：优先相对路径，避免 SITE_URL 配错导致 CSS/JS 404；完整 URL 原样返回 */
function site_asset_path(string $path): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $path = '/' . ltrim($path, '/');
    $basePath = site_app_base_path();
    if ($basePath === '') {
        return $path;
    }

    return $basePath . $path;
}

/**
 * API 脚本 URL（始终相对站点根，避免在 /api/* 请求中拼出 /api/api/...）
 *
 * @param array<string, scalar|null> $query
 */
function site_api_path(string $script, array $query = []): string
{
    $script = ltrim(str_replace('\\', '/', $script), '/');
    if (!str_starts_with($script, 'api/')) {
        $script = 'api/' . $script;
    }
    $path = '/' . $script;
    if ($query !== []) {
        $path .= '?' . http_build_query($query);
    }
    $basePath = site_app_base_path();
    if ($basePath === '') {
        return $path;
    }

    return $basePath . $path;
}

/** 仅路径部分，如 /campus-sso-chat；根目录部署时为空字符串 */
function site_base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $configuredPath = site_configured_base_path();
    if ($configuredPath !== '') {
        $cached = $configuredPath;
        return $cached;
    }

    $cached = site_request_script_base_path();
    return $cached;
}
