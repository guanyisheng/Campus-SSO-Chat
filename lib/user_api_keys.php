<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site.php';

function user_api_keys_enabled(): bool
{
    if (defined('USER_API_KEY_ENABLED')) {
        return (bool) USER_API_KEY_ENABLED;
    }

    return true;
}

function user_api_keys_fix_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $cols = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('api_key_hash', $cols, true)) {
            db()->exec(
                'ALTER TABLE users
                 ADD COLUMN api_key_hash CHAR(64) NULL DEFAULT NULL,
                 ADD COLUMN api_key_prefix VARCHAR(16) NULL DEFAULT NULL,
                 ADD COLUMN api_key_created_at DATETIME NULL DEFAULT NULL'
            );
        }
        $indexes = db()->query('SHOW INDEX FROM users')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasHashIdx = false;
        foreach ($indexes as $idx) {
            if (($idx['Key_name'] ?? '') === 'uk_api_key_hash') {
                $hasHashIdx = true;
                break;
            }
        }
        if (!$hasHashIdx) {
            db()->exec('ALTER TABLE users ADD UNIQUE KEY uk_api_key_hash (api_key_hash)');
        }
    } catch (Throwable) {
    }
}

function user_api_key_hash(string $plainKey): string
{
    return hash('sha256', $plainKey);
}

function user_api_key_prefix_from_plain(string $plainKey): string
{
    return substr($plainKey, 0, 12);
}

function user_api_key_generate_plain(): string
{
    return 'cschat_' . bin2hex(random_bytes(24));
}

/** @return array{has_key:bool,prefix:string,created_at:?string,enabled:bool,endpoint:string,base_url:string} */
function user_api_key_status(int $userId): array
{
    user_api_keys_fix_schema();
    $base = rtrim(site_base_url(), '/');
    $stmt = db()->prepare(
        'SELECT api_key_hash, api_key_prefix, api_key_created_at FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'has_key'    => trim((string) ($row['api_key_hash'] ?? '')) !== '',
        'prefix'     => (string) ($row['api_key_prefix'] ?? ''),
        'created_at' => !empty($row['api_key_created_at']) ? (string) $row['api_key_created_at'] : null,
        'enabled'    => user_api_keys_enabled(),
        'base_url'   => $base . '/api/v1',
        'endpoint'   => $base . '/api/v1/chat/completions',
    ];
}

/** @return array{key:string,prefix:string} */
function user_api_key_generate(int $userId): array
{
    if (!user_api_keys_enabled()) {
        throw new RuntimeException('用户 API Key 功能已关闭');
    }
    user_api_keys_fix_schema();

    $plain = user_api_key_generate_plain();
    $hash = user_api_key_hash($plain);
    $prefix = user_api_key_prefix_from_plain($plain);

    db()->prepare(
        'UPDATE users SET api_key_hash = ?, api_key_prefix = ?, api_key_created_at = NOW() WHERE id = ?'
    )->execute([$hash, $prefix, $userId]);

    return ['key' => $plain, 'prefix' => $prefix];
}

function user_api_key_revoke(int $userId): void
{
    user_api_keys_fix_schema();
    db()->prepare(
        'UPDATE users SET api_key_hash = NULL, api_key_prefix = NULL, api_key_created_at = NULL WHERE id = ?'
    )->execute([$userId]);
}

function user_api_key_extract_bearer(): string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = trim((string) $value);
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
    }

    return '';
}

function user_api_key_verify(string $plainKey): ?int
{
    if ($plainKey === '' || !str_starts_with($plainKey, 'cschat_')) {
        return null;
    }
    user_api_keys_fix_schema();

    $hash = user_api_key_hash($plainKey);
    $stmt = db()->prepare('SELECT id FROM users WHERE api_key_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $id = $stmt->fetchColumn();

    return $id ? (int) $id : null;
}

function user_api_key_require_user_id(): int
{
    if (!user_api_keys_enabled()) {
        throw new RuntimeException('用户 API Key 功能已关闭', 503);
    }

    $plain = user_api_key_extract_bearer();
    if ($plain === '') {
        throw new RuntimeException('缺少 Authorization: Bearer <API Key>', 401);
    }

    $userId = user_api_key_verify($plain);
    if (!$userId) {
        throw new RuntimeException('无效的 API Key', 401);
    }

    return $userId;
}
