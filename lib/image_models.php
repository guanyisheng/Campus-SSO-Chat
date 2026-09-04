<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * 内置 catalog（数据库为空时用于种子数据与兜底）
 *
 * @return array<string, array{label:string,checkpoint:string,output_prefix?:string}>
 */
function media_image_model_builtin_catalog(): array
{
    return [
        'pony_v6' => [
            'label'         => 'Pony V6 XL',
            'checkpoint'    => 'ponyDiffusionV6XL_v6StartWithThisOne.safetensors',
            'output_prefix' => 'Pony_API',
        ],
        'juggernaut_xl_v8' => [
            'label'         => 'Juggernaut XL v8',
            'checkpoint'    => 'juggernautXL_v8Rundiffusion.safetensors',
            'output_prefix' => 'Juggernaut_API',
        ],
    ];
}

function image_models_table_exists(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        db()->query('SELECT 1 FROM image_checkpoints LIMIT 1');
        $exists = true;
    } catch (Throwable) {
        $exists = false;
    }

    return $exists;
}

function image_models_fix_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS image_checkpoints (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                model_key VARCHAR(64) NOT NULL,
                display_name VARCHAR(128) NOT NULL,
                checkpoint VARCHAR(255) NOT NULL,
                output_prefix VARCHAR(64) NOT NULL DEFAULT \'CampusChat\',
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_model_key (model_key),
                UNIQUE KEY uk_checkpoint (checkpoint),
                KEY idx_enabled_sort (is_enabled, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable) {
    }
}

function image_models_normalize_row(array $row): array
{
    return [
        'id'            => (int) ($row['id'] ?? 0),
        'model_key'     => (string) ($row['model_key'] ?? ''),
        'display_name'  => (string) ($row['display_name'] ?? ''),
        'checkpoint'    => (string) ($row['checkpoint'] ?? ''),
        'output_prefix' => (string) ($row['output_prefix'] ?? 'CampusChat'),
        'is_enabled'    => (int) ($row['is_enabled'] ?? 1),
        'is_default'    => (int) ($row['is_default'] ?? 0),
        'sort_order'    => (int) ($row['sort_order'] ?? 0),
    ];
}

function image_models_seed_from_builtin(): void
{
    image_models_fix_schema();
    try {
        $count = (int) db()->query('SELECT COUNT(*) FROM image_checkpoints')->fetchColumn();
    } catch (Throwable) {
        return;
    }
    if ($count > 0) {
        return;
    }

    $sort = 0;
    foreach (media_image_model_builtin_catalog() as $key => $def) {
        image_models_create(
            (string) $key,
            (string) ($def['label'] ?? $key),
            (string) ($def['checkpoint'] ?? ''),
            (string) ($def['output_prefix'] ?? 'CampusChat'),
            $sort,
            $key === 'pony_v6'
        );
        $sort++;
    }
}

function image_models_ensure_ready(): void
{
    image_models_fix_schema();
    image_models_seed_from_builtin();
}

/** @return list<array<string, mixed>> */
function image_models_list_all(): array
{
    image_models_ensure_ready();
    try {
        $rows = db()->query(
            'SELECT id, model_key, display_name, checkpoint, output_prefix, is_enabled, is_default, sort_order
             FROM image_checkpoints
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return image_models_fallback_rows(false);
    }

    return array_map('image_models_normalize_row', $rows);
}

/** @return list<array<string, mixed>> */
function image_models_list_enabled(): array
{
    image_models_ensure_ready();
    try {
        $rows = db()->query(
            'SELECT id, model_key, display_name, checkpoint, output_prefix, is_enabled, is_default, sort_order
             FROM image_checkpoints
             WHERE is_enabled = 1
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return image_models_fallback_rows(true);
    }

    return array_map('image_models_normalize_row', $rows);
}

/** @return list<array<string, mixed>> */
function image_models_fallback_rows(bool $enabledOnly): array
{
    $rows = [];
    $sort = 0;
    foreach (media_image_model_builtin_catalog() as $key => $def) {
        $rows[] = image_models_normalize_row([
            'id'            => 0,
            'model_key'     => $key,
            'display_name'  => $def['label'] ?? $key,
            'checkpoint'    => $def['checkpoint'] ?? '',
            'output_prefix' => $def['output_prefix'] ?? 'CampusChat',
            'is_enabled'    => 1,
            'is_default'    => $key === 'pony_v6' ? 1 : 0,
            'sort_order'    => $sort++,
        ]);
    }
    if ($enabledOnly) {
        return $rows;
    }

    return $rows;
}

function image_models_get(int $id): ?array
{
    image_models_ensure_ready();
    $stmt = db()->prepare(
        'SELECT id, model_key, display_name, checkpoint, output_prefix, is_enabled, is_default, sort_order
         FROM image_checkpoints WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? image_models_normalize_row($row) : null;
}

function image_models_get_by_key(string $key, bool $enabledOnly = false): ?array
{
    image_models_ensure_ready();
    $sql = 'SELECT id, model_key, display_name, checkpoint, output_prefix, is_enabled, is_default, sort_order
            FROM image_checkpoints WHERE model_key = ?';
    if ($enabledOnly) {
        $sql .= ' AND is_enabled = 1';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([trim($key)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? image_models_normalize_row($row) : null;
}

function image_models_slug_from_checkpoint(string $checkpoint): string
{
    $base = preg_replace('/\.safetensors$/i', '', basename($checkpoint));
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '_', $base) ?? $base);
    $slug = trim((string) $slug, '_');
    if ($slug === '') {
        $slug = 'ckpt_' . substr(md5($checkpoint), 0, 8);
    }
    if (strlen($slug) > 48) {
        $slug = substr($slug, 0, 48);
    }

    return $slug;
}

function image_models_unique_key(string $baseKey): string
{
    $baseKey = trim($baseKey);
    if ($baseKey === '' || !preg_match('/^[a-z0-9_]+$/', $baseKey)) {
        throw new InvalidArgumentException('无效的 model_key');
    }
    if (!image_models_get_by_key($baseKey)) {
        return $baseKey;
    }
    for ($i = 2; $i <= 99; $i++) {
        $candidate = $baseKey . '_' . $i;
        if (!image_models_get_by_key($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('无法生成唯一 model_key');
}

function image_models_default_output_prefix(string $displayName, string $modelKey): string
{
    $name = trim($displayName);
    if ($name !== '') {
        $parts = preg_split('/\s+/u', $name) ?: [];
        $first = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($parts[0] ?? ''));
        if ($first !== '') {
            return ucfirst(strtolower($first)) . '_API';
        }
    }

    return strtoupper(str_replace('_', '', substr($modelKey, 0, 12))) . '_API';
}

function image_models_validate_key(string $key): string
{
    $key = trim($key);
    if ($key === '' || !preg_match('/^[a-z0-9_]{1,64}$/', $key)) {
        throw new InvalidArgumentException('model_key 仅允许小写字母、数字、下划线');
    }

    return $key;
}

function image_models_validate_checkpoint(string $checkpoint): string
{
    $checkpoint = trim($checkpoint);
    if ($checkpoint === '' || strlen($checkpoint) > 255) {
        throw new InvalidArgumentException('请填写有效的 checkpoint 文件名');
    }
    if (str_contains($checkpoint, '..') || str_contains($checkpoint, '/') || str_contains($checkpoint, '\\')) {
        throw new InvalidArgumentException('checkpoint 文件名不合法');
    }

    return $checkpoint;
}

function image_models_set_default(int $id): void
{
    image_models_ensure_ready();
    db()->exec('UPDATE image_checkpoints SET is_default = 0');
    db()->prepare('UPDATE image_checkpoints SET is_default = 1 WHERE id = ?')->execute([$id]);
}

function image_models_create(
    string $modelKey,
    string $displayName,
    string $checkpoint,
    string $outputPrefix = '',
    int $sortOrder = 0,
    bool $isDefault = false
): int {
    image_models_ensure_ready();
    $modelKey = image_models_validate_key($modelKey);
    $displayName = trim($displayName);
    $checkpoint = image_models_validate_checkpoint($checkpoint);
    if ($displayName === '') {
        throw new InvalidArgumentException('请填写显示名称');
    }
    if (image_models_get_by_key($modelKey)) {
        throw new InvalidArgumentException('model_key 已存在：' . $modelKey);
    }

    $stmtExists = db()->prepare('SELECT id FROM image_checkpoints WHERE checkpoint = ? LIMIT 1');
    $stmtExists->execute([$checkpoint]);
    if ($stmtExists->fetchColumn()) {
        throw new InvalidArgumentException('该 checkpoint 已添加');
    }

    $outputPrefix = trim($outputPrefix);
    if ($outputPrefix === '') {
        $outputPrefix = image_models_default_output_prefix($displayName, $modelKey);
    }

    $stmt = db()->prepare(
        'INSERT INTO image_checkpoints (model_key, display_name, checkpoint, output_prefix, is_enabled, is_default, sort_order)
         VALUES (?, ?, ?, ?, 1, ?, ?)'
    );
    $stmt->execute([
        $modelKey,
        $displayName,
        $checkpoint,
        $outputPrefix,
        $isDefault ? 1 : 0,
        $sortOrder,
    ]);
    $id = (int) db()->lastInsertId();

    if ($isDefault) {
        image_models_set_default($id);
    } elseif ((int) db()->query('SELECT COUNT(*) FROM image_checkpoints WHERE is_default = 1')->fetchColumn() === 0) {
        image_models_set_default($id);
    }

    return $id;
}

function image_models_update(
    int $id,
    string $displayName,
    string $checkpoint,
    string $outputPrefix,
    int $sortOrder
): void {
    image_models_ensure_ready();
    $row = image_models_get($id);
    if (!$row) {
        throw new InvalidArgumentException('模型不存在');
    }

    $displayName = trim($displayName);
    $checkpoint = image_models_validate_checkpoint($checkpoint);
    $outputPrefix = trim($outputPrefix);
    if ($displayName === '') {
        throw new InvalidArgumentException('请填写显示名称');
    }
    if ($outputPrefix === '') {
        $outputPrefix = image_models_default_output_prefix($displayName, (string) $row['model_key']);
    }

    $stmtExists = db()->prepare('SELECT id FROM image_checkpoints WHERE checkpoint = ? AND id <> ? LIMIT 1');
    $stmtExists->execute([$checkpoint, $id]);
    if ($stmtExists->fetchColumn()) {
        throw new InvalidArgumentException('该 checkpoint 已被其他模型使用');
    }

    db()->prepare(
        'UPDATE image_checkpoints
         SET display_name = ?, checkpoint = ?, output_prefix = ?, sort_order = ?
         WHERE id = ?'
    )->execute([$displayName, $checkpoint, $outputPrefix, $sortOrder, $id]);
}

function image_models_update_enabled(int $id, bool $enabled): void
{
    image_models_ensure_ready();
    db()->prepare('UPDATE image_checkpoints SET is_enabled = ? WHERE id = ?')->execute([$enabled ? 1 : 0, $id]);
}

function image_models_delete(int $id): void
{
    image_models_ensure_ready();
    $row = image_models_get($id);
    if (!$row) {
        return;
    }

    db()->prepare('DELETE FROM image_checkpoints WHERE id = ?')->execute([$id]);

    if ((int) $row['is_default'] === 1) {
        $next = db()->query(
            'SELECT id FROM image_checkpoints WHERE is_enabled = 1 ORDER BY sort_order ASC, id ASC LIMIT 1'
        )->fetchColumn();
        if ($next) {
            image_models_set_default((int) $next);
        }
    }
}

/**
 * @param list<string> $checkpoints safetensors 文件名
 * @return array{added:int, skipped:int}
 */
function image_models_bulk_create_from_checkpoints(array $checkpoints, int $sortStart = 0): array
{
    $added = 0;
    $skipped = 0;
    $sort = $sortStart;

    foreach ($checkpoints as $checkpoint) {
        $checkpoint = trim((string) $checkpoint);
        if ($checkpoint === '') {
            continue;
        }
        try {
            $checkpoint = image_models_validate_checkpoint($checkpoint);
        } catch (InvalidArgumentException) {
            $skipped++;
            continue;
        }

        $stmtExists = db()->prepare('SELECT id FROM image_checkpoints WHERE checkpoint = ? LIMIT 1');
        $stmtExists->execute([$checkpoint]);
        if ($stmtExists->fetchColumn()) {
            $skipped++;
            continue;
        }

        $baseKey = image_models_slug_from_checkpoint($checkpoint);
        try {
            $modelKey = image_models_unique_key($baseKey);
        } catch (Throwable) {
            $skipped++;
            continue;
        }

        $displayName = preg_replace('/\.safetensors$/i', '', basename($checkpoint)) ?? $checkpoint;
        image_models_create(
            $modelKey,
            $displayName,
            $checkpoint,
            image_models_default_output_prefix($displayName, $modelKey),
            $sort,
            false
        );
        $added++;
        $sort++;
    }

    return ['added' => $added, 'skipped' => $skipped];
}

/**
 * @return array<string, array{label:string,checkpoint:string,output_prefix?:string}>
 */
function media_image_model_catalog(): array
{
    $catalog = [];
    foreach (image_models_list_enabled() as $row) {
        $key = (string) $row['model_key'];
        $catalog[$key] = [
            'label'         => (string) $row['display_name'],
            'checkpoint'    => (string) $row['checkpoint'],
            'output_prefix' => (string) $row['output_prefix'],
        ];
    }
    if ($catalog !== []) {
        return $catalog;
    }

    return media_image_model_builtin_catalog();
}

function media_image_model_default_key(): string
{
    image_models_ensure_ready();
    try {
        $key = db()->query(
            'SELECT model_key FROM image_checkpoints WHERE is_enabled = 1 AND is_default = 1 LIMIT 1'
        )->fetchColumn();
        if (is_string($key) && $key !== '') {
            return $key;
        }
        $key = db()->query(
            'SELECT model_key FROM image_checkpoints WHERE is_enabled = 1 ORDER BY sort_order ASC, id ASC LIMIT 1'
        )->fetchColumn();
        if (is_string($key) && $key !== '') {
            return $key;
        }
    } catch (Throwable) {
    }

    return 'pony_v6';
}

/** @return list<string> */
function media_image_model_keys(): array
{
    return array_keys(media_image_model_catalog());
}

/** @return list<array{key:string,label:string}> */
function media_image_model_public_options(): array
{
    $out = [];
    foreach (media_image_model_catalog() as $key => $def) {
        $out[] = [
            'key'   => (string) $key,
            'label' => (string) ($def['label'] ?? $key),
        ];
    }

    return $out;
}

/**
 * @return array{key:string,label:string,checkpoint:string,output_prefix:string}
 */
function media_image_model_resolve(string $modelKey = ''): array
{
    $modelKey = trim($modelKey);
    if ($modelKey === '') {
        $modelKey = media_image_model_default_key();
    }
    if (!preg_match('/^[a-z0-9_]+$/', $modelKey)) {
        throw new InvalidArgumentException('无效的 model 参数');
    }

    $row = image_models_get_by_key($modelKey, true);
    if ($row) {
        return [
            'key'           => (string) $row['model_key'],
            'label'         => (string) $row['display_name'],
            'checkpoint'    => (string) $row['checkpoint'],
            'output_prefix' => (string) $row['output_prefix'],
        ];
    }

    $catalog = media_image_model_catalog();
    if (!isset($catalog[$modelKey])) {
        throw new InvalidArgumentException('不支持的生成模型：' . $modelKey);
    }

    $def = $catalog[$modelKey];

    return [
        'key'           => $modelKey,
        'label'         => (string) ($def['label'] ?? $modelKey),
        'checkpoint'    => (string) ($def['checkpoint'] ?? ''),
        'output_prefix' => (string) ($def['output_prefix'] ?? 'CampusChat'),
    ];
}
