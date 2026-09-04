<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

function user_groups_table_exists(?PDO $pdo = null): bool
{
    try {
        $pdo = $pdo ?? db();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['user_groups']);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function user_groups_users_has_group_id(?PDO $pdo = null): bool
{
    try {
        $pdo = $pdo ?? db();
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return in_array('group_id', $cols, true);
    } catch (Throwable) {
        return false;
    }
}

function user_groups_fix_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo = db();

    if (!user_groups_table_exists($pdo)) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_groups (
              id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              name               VARCHAR(64)  NOT NULL,
              slug               VARCHAR(32)  NOT NULL,
              daily_chat_limit   INT UNSIGNED NOT NULL DEFAULT 100 COMMENT \'0=不限\',
              daily_image_limit  INT UNSIGNED NOT NULL DEFAULT 20 COMMENT \'0=不限\',
              daily_video_limit  INT UNSIGNED NOT NULL DEFAULT 10 COMMENT \'0=不限\',
              can_access_admin   TINYINT(1)   NOT NULL DEFAULT 0,
              sort_order         INT          NOT NULL DEFAULT 0,
              created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uk_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    if (!user_groups_users_has_group_id($pdo)) {
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN group_id INT UNSIGNED NULL DEFAULT NULL AFTER auth_source');
            $pdo->exec('ALTER TABLE users ADD KEY idx_users_group (group_id)');
        } catch (Throwable) {
            // 列已存在或权限不足时忽略
        }
    }

    user_groups_seed_defaults();
    $done = true;
}

function user_groups_seed_defaults(): void
{
    try {
        $count = (int) db()->query('SELECT COUNT(*) FROM user_groups')->fetchColumn();
        if ($count > 0) {
            return;
        }
        $chat = max(0, setting_int('daily_chat_limit', 100));
        $image = max(0, setting_int('daily_image_limit', 20));
        $video = max(0, setting_int('daily_video_limit', 10));

        db()->prepare(
            'INSERT INTO user_groups (name, slug, daily_chat_limit, daily_image_limit, daily_video_limit, can_access_admin, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(['普通用户', 'member', $chat, $image, $video, 0, 0]);

        db()->prepare(
            'INSERT INTO user_groups (name, slug, daily_chat_limit, daily_image_limit, daily_video_limit, can_access_admin, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(['管理员', 'admin', 0, 0, 0, 1, 1]);

        $defaultId = (int) db()->query("SELECT id FROM user_groups WHERE slug = 'member' LIMIT 1")->fetchColumn();
        if ($defaultId > 0) {
            setting_save_many(['default_user_group_id' => (string) $defaultId]);
        }
    } catch (Throwable) {
    }
}

/** @return list<array<string, mixed>> */
function user_groups_list(): array
{
    user_groups_fix_schema();
    try {
        $rows = db()->query(
            'SELECT g.*, (SELECT COUNT(*) FROM users u WHERE u.group_id = g.id) AS user_count
             FROM user_groups g ORDER BY g.sort_order ASC, g.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map('user_group_normalize_row', $rows);
    } catch (Throwable) {
        return [];
    }
}

/** @param array<string, mixed> $row */
function user_group_normalize_row(array $row): array
{
    return [
        'id'                => (int) ($row['id'] ?? 0),
        'name'              => (string) ($row['name'] ?? ''),
        'slug'              => (string) ($row['slug'] ?? ''),
        'daily_chat_limit'  => (int) ($row['daily_chat_limit'] ?? 0),
        'daily_image_limit' => (int) ($row['daily_image_limit'] ?? 0),
        'daily_video_limit' => (int) ($row['daily_video_limit'] ?? 0),
        'can_access_admin'  => !empty($row['can_access_admin']),
        'sort_order'        => (int) ($row['sort_order'] ?? 0),
        'user_count'        => (int) ($row['user_count'] ?? 0),
    ];
}

function user_group_get(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    user_groups_fix_schema();
    $stmt = db()->prepare('SELECT * FROM user_groups WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? user_group_normalize_row($row) : null;
}

function user_group_get_by_slug(string $slug): ?array
{
    user_groups_fix_schema();
    $stmt = db()->prepare('SELECT * FROM user_groups WHERE slug = ? LIMIT 1');
    $stmt->execute([trim($slug)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? user_group_normalize_row($row) : null;
}

function user_group_default_id(): int
{
    user_groups_fix_schema();
    $id = setting_int('default_user_group_id', 0);
    if ($id > 0 && user_group_get($id)) {
        return $id;
    }
    $row = user_group_get_by_slug('member');
    return $row ? (int) $row['id'] : 0;
}

function user_group_assign(int $userId, int $groupId): bool
{
    if ($userId <= 0 || $groupId <= 0 || !user_group_get($groupId)) {
        return false;
    }
    user_groups_fix_schema();
    db()->prepare('UPDATE users SET group_id = ? WHERE id = ?')->execute([$groupId, $userId]);
    return true;
}

function user_group_assign_default(int $userId): void
{
    $gid = user_group_default_id();
    if ($gid > 0) {
        user_group_assign($userId, $gid);
    }
}

function user_group_assigned(int $userId): ?array
{
    user_groups_fix_schema();
    if ($userId <= 0) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT g.* FROM user_groups g
         INNER JOIN users u ON u.group_id = g.id
         WHERE u.id = ? AND u.group_id IS NOT NULL LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? user_group_normalize_row($row) : null;
}

function user_group_for_user(int $userId): ?array
{
    $assigned = user_group_assigned($userId);
    if ($assigned) {
        return $assigned;
    }
    $defaultId = user_group_default_id();
    return $defaultId > 0 ? user_group_get($defaultId) : null;
}

function user_can_access_admin(int $userId): bool
{
    $group = user_group_assigned($userId);
    return $group !== null && !empty($group['can_access_admin']);
}

function user_group_daily_limit(int $userId, string $type): int
{
    $group = user_group_for_user($userId);
    if (!$group) {
        return quota_global_daily_limit($type);
    }
    return match ($type) {
        'image' => max(0, (int) $group['daily_image_limit']),
        'video' => max(0, (int) $group['daily_video_limit']),
        default => max(0, (int) $group['daily_chat_limit']),
    };
}

function user_group_save(
    ?int $id,
    string $name,
    string $slug,
    int $chatLimit,
    int $imageLimit,
    int $videoLimit,
    bool $canAdmin,
    int $sortOrder
): int {
    user_groups_fix_schema();
    $name = trim($name);
    $slug = preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($slug))) ?? '';
    if ($name === '' || $slug === '') {
        throw new InvalidArgumentException('名称与标识不能为空');
    }
    $chatLimit = max(0, $chatLimit);
    $imageLimit = max(0, $imageLimit);
    $videoLimit = max(0, $videoLimit);

    if ($id !== null && $id > 0) {
        db()->prepare(
            'UPDATE user_groups SET name=?, slug=?, daily_chat_limit=?, daily_image_limit=?, daily_video_limit=?,
             can_access_admin=?, sort_order=? WHERE id=?'
        )->execute([$name, $slug, $chatLimit, $imageLimit, $videoLimit, $canAdmin ? 1 : 0, $sortOrder, $id]);
        return $id;
    }

    db()->prepare(
        'INSERT INTO user_groups (name, slug, daily_chat_limit, daily_image_limit, daily_video_limit, can_access_admin, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$name, $slug, $chatLimit, $imageLimit, $videoLimit, $canAdmin ? 1 : 0, $sortOrder]);
    return (int) db()->lastInsertId();
}

function user_group_delete(int $id): void
{
    if ($id <= 0) {
        return;
    }
    $group = user_group_get($id);
    if (!$group) {
        return;
    }
    if ($group['user_count'] > 0) {
        throw new RuntimeException('该组仍有用户，请先调整用户归属后再删除');
    }
    if ((int) setting_int('default_user_group_id', 0) === $id) {
        throw new RuntimeException('不能删除当前默认注册组，请先在用户组页面修改默认组');
    }
    db()->prepare('DELETE FROM user_groups WHERE id = ?')->execute([$id]);
}

/** @return list<array<string, mixed>> */
function users_list_for_admin(int $limit = 200): array
{
    user_groups_fix_schema();
    $stmt = db()->prepare(
        'SELECT u.id, u.campus_uid, u.display_name, u.auth_source, u.group_id, u.last_login_at, u.created_at,
                g.name AS group_name
         FROM users u
         LEFT JOIN user_groups g ON g.id = u.group_id
         ORDER BY u.id DESC LIMIT ?'
    );
    $stmt->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
