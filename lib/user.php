<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/oidc_profile.php';

function user_register_hooks(): void
{
    require_once __DIR__ . '/user_groups.php';
    user_groups_fix_schema();
}

function local_auth_enabled(): bool
{
    return setting_bool('enable_local_auth', ENABLE_LOCAL_AUTH);
}

function upsert_oidc_user(string $campus_uid, string $display_name = ''): array
{
    user_register_hooks();
    $campus_uid = normalize_campus_uid($campus_uid);
    if ($campus_uid === '') {
        throw new InvalidArgumentException('校内 UID 不能为空');
    }

    $existing = fetch_user_by_uid($campus_uid, false);
    if ($existing && ($existing['auth_source'] ?? '') === 'local') {
        throw new RuntimeException('该 UID 已为本站注册账号，请使用本站登录');
    }

    $incoming = oidc_sanitize_display_name(trim($display_name), $campus_uid);
    $existingName = $existing
        ? oidc_sanitize_display_name((string) ($existing['display_name'] ?? ''), $campus_uid)
        : '';

    if ($incoming !== '') {
        $finalName = $incoming;
    } elseif ($existingName !== '') {
        $finalName = $existingName;
    } else {
        $finalName = '同学';
    }

    $pdo = db();
    if ($existing) {
        $pdo->prepare(
            'UPDATE users SET display_name = ?, auth_source = \'oidc\', last_login_at = NOW() WHERE campus_uid = ?'
        )->execute([$finalName, $campus_uid]);
    } else {
        $defaultGroup = user_group_default_id();
        $pdo->prepare(
            'INSERT INTO users (campus_uid, display_name, auth_source, group_id, last_login_at)
             VALUES (?, ?, \'oidc\', ?, NOW())'
        )->execute([$campus_uid, $finalName, $defaultGroup > 0 ? $defaultGroup : null]);
    }

    return fetch_user_by_uid($campus_uid);
}

function register_local_user(string $campus_uid, string $password, string $display_name): array
{
    user_register_hooks();
    if (!local_auth_enabled()) {
        throw new RuntimeException('本站注册已关闭');
    }

    $campus_uid = normalize_campus_uid($campus_uid);
    validate_local_uid($campus_uid);
    validate_local_password($password);

    $display_name = oidc_sanitize_display_name(trim($display_name), $campus_uid);
    if ($display_name === '') {
        $display_name = '同学';
    }

    $pdo = db();
    $exists = $pdo->prepare('SELECT id, auth_source FROM users WHERE campus_uid = ? LIMIT 1');
    $exists->execute([$campus_uid]);
    if ($row = $exists->fetch()) {
        if ($row['auth_source'] === 'oidc') {
            throw new RuntimeException('该 UID 已通过统一认证绑定，请使用「' . OIDC_PROVIDER_NAME . '」登录');
        }
        throw new RuntimeException('该 UID 已注册，请直接登录');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $defaultGroup = user_group_default_id();
    $pdo->prepare(
        'INSERT INTO users (campus_uid, display_name, password_hash, auth_source, group_id, last_login_at)
         VALUES (?, ?, ?, \'local\', ?, NOW())'
    )->execute([$campus_uid, $display_name, $hash, $defaultGroup > 0 ? $defaultGroup : null]);

    return fetch_user_by_uid($campus_uid);
}

function authenticate_local_user(string $campus_uid, string $password): array
{
    if (!local_auth_enabled()) {
        throw new RuntimeException('本站登录已关闭');
    }

    $campus_uid = normalize_campus_uid($campus_uid);
    $user = fetch_user_by_uid($campus_uid, false);

    if (!$user) {
        throw new RuntimeException('UID 或密码错误');
    }
    if ($user['auth_source'] !== 'local') {
        throw new RuntimeException('该账号请使用「' . OIDC_PROVIDER_NAME . '」登录');
    }
    if (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        throw new RuntimeException('UID 或密码错误');
    }

    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    return $user;
}

function fetch_user_by_uid(string $campus_uid, bool $orFail = true): ?array
{
    user_register_hooks();
    $pdo = db();
    if (user_groups_table_exists($pdo)) {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.campus_uid, u.display_name, u.auth_source, u.password_hash, u.group_id,
                    g.name AS group_name, g.can_access_admin
             FROM users u
             LEFT JOIN user_groups g ON g.id = u.group_id
             WHERE u.campus_uid = ? LIMIT 1'
        );
    } else {
        $groupCol = user_groups_users_has_group_id($pdo) ? 'u.group_id' : 'NULL AS group_id';
        $stmt = $pdo->prepare(
            "SELECT u.id, u.campus_uid, u.display_name, u.auth_source, u.password_hash, {$groupCol},
                    NULL AS group_name, 0 AS can_access_admin
             FROM users u
             WHERE u.campus_uid = ? LIMIT 1"
        );
    }
    $stmt->execute([$campus_uid]);
    $user = $stmt->fetch();
    if (!$user && $orFail) {
        throw new RuntimeException('用户不存在');
    }
    return $user ?: null;
}

function session_from_db_user(array $user): array
{
    return [
        'id'               => (int) $user['id'],
        'campus_uid'       => $user['campus_uid'],
        'display_name'     => $user['display_name'],
        'auth_source'      => $user['auth_source'] ?? 'oidc',
        'group_id'         => (int) ($user['group_id'] ?? 0),
        'group_name'       => (string) ($user['group_name'] ?? ''),
        'can_access_admin' => !empty($user['can_access_admin']),
    ];
}

function normalize_campus_uid(string $uid): string
{
    return trim($uid);
}

function validate_local_uid(string $uid): void
{
    $len = strlen($uid);
    if ($len < LOCAL_UID_MIN_LEN || $len > LOCAL_UID_MAX_LEN) {
        throw new InvalidArgumentException(
            'UID 长度需在 ' . LOCAL_UID_MIN_LEN . '–' . LOCAL_UID_MAX_LEN . ' 个字符'
        );
    }
    if (!preg_match('/^[A-Za-z0-9_\-\.]+$/', $uid)) {
        throw new InvalidArgumentException('UID 仅允许字母、数字、下划线、横线、点');
    }
}

function validate_local_password(string $password): void
{
    if (strlen($password) < LOCAL_PASSWORD_MIN_LEN) {
        throw new InvalidArgumentException('密码至少 ' . LOCAL_PASSWORD_MIN_LEN . ' 位');
    }
}

function upsert_user_by_uid(string $campus_uid, string $display_name = ''): array
{
    return upsert_oidc_user($campus_uid, $display_name);
}
