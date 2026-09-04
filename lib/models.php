<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/** @return list<string> */
function models_table_columns(): array
{
    if (isset($GLOBALS['_models_columns_cache']) && is_array($GLOBALS['_models_columns_cache'])) {
        return $GLOBALS['_models_columns_cache'];
    }
    try {
        $rows = db()->query('SHOW COLUMNS FROM llm_models')->fetchAll(PDO::FETCH_ASSOC);
        $GLOBALS['_models_columns_cache'] = array_column($rows, 'Field');
    } catch (Throwable) {
        $GLOBALS['_models_columns_cache'] = [];
    }
    return $GLOBALS['_models_columns_cache'];
}

/** @return 'chat'|'image'|'video' */
function model_normalize_type(string $type): string
{
    return in_array($type, ['chat', 'image', 'video'], true) ? $type : 'chat';
}

function models_legacy_name_column(): ?string
{
    foreach (models_table_columns() as $field) {
        if (strcasecmp($field, 'name') === 0) {
            return $field;
        }
    }
    return null;
}

function model_normalize_row(array $row): array
{
    $label = $row['display_name'] ?? $row['name'] ?? $row['NAME'] ?? '';
    return [
        'id'           => (int) ($row['id'] ?? $row['ID'] ?? 0),
        'display_name' => (string) $label,
        'name'         => (string) $label,
        'base_url'     => (string) ($row['base_url'] ?? $row['BASE_URL'] ?? ''),
        'api_key'      => (string) ($row['api_key'] ?? $row['API_KEY'] ?? ''),
        'model_name'   => (string) ($row['model_name'] ?? $row['MODEL_NAME'] ?? ''),
        'model_type'   => model_normalize_type((string) ($row['model_type'] ?? 'chat')),
        'is_enabled'   => (int) ($row['is_enabled'] ?? $row['IS_ENABLED'] ?? 1),
        'sort_order'   => (int) ($row['sort_order'] ?? $row['SORT_ORDER'] ?? 0),
    ];
}

function models_list_enabled_by_type(string $type = 'chat'): array
{
    models_ensure_default();
    $type = model_normalize_type($type);
    $stmt = db()->prepare(
        'SELECT id, display_name, base_url, api_key, model_name, model_type, is_enabled, sort_order
         FROM llm_models
         WHERE is_enabled = 1 AND model_type = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$type]);
    $rows = $stmt->fetchAll() ?: [];
    return array_map('model_normalize_row', $rows);
}

function models_list_enabled(): array
{
    return models_list_enabled_by_type('chat');
}

function models_list_all(?string $type = null): array
{
    models_ensure_default();
    if ($type !== null) {
        $type = model_normalize_type($type);
        $stmt = db()->prepare(
            'SELECT id, display_name, base_url, api_key, model_name, model_type, is_enabled, sort_order
             FROM llm_models WHERE model_type = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$type]);
    } else {
        $stmt = db()->query(
            'SELECT id, display_name, base_url, api_key, model_name, model_type, is_enabled, sort_order
             FROM llm_models ORDER BY sort_order ASC, id ASC'
        );
    }
    $rows = $stmt->fetchAll() ?: [];
    return array_map('model_normalize_row', $rows);
}

function model_get(int $id, bool $enabledOnly = false): ?array
{
    $sql = 'SELECT id, display_name, base_url, api_key, model_name, model_type, is_enabled, sort_order
            FROM llm_models WHERE id = ?';
    if ($enabledOnly) {
        $sql .= ' AND is_enabled = 1';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? model_normalize_row($row) : null;
}

function model_quote_column(string $field): string
{
    return '`' . str_replace('`', '``', $field) . '`';
}

function model_create(
    string $displayName,
    string $baseUrl,
    string $modelName,
    string $apiKey = '',
    int $sort = 0,
    string $type = 'chat'
): int {
    models_fix_schema();

    $displayName = trim($displayName);
    $baseUrl = rtrim(trim($baseUrl), '/');
    $baseUrl = rtrim(preg_replace('#/chat/completions/?$#', '', $baseUrl) ?? $baseUrl, '/');
    $modelName = trim($modelName);
    $apiKey = trim($apiKey);
    $type = model_normalize_type($type);

    $cols = models_table_columns();
    $legacy = models_legacy_name_column();

    if ($legacy !== null && in_array('display_name', $cols, true)) {
        $lq = model_quote_column($legacy);
        $stmt = db()->prepare(
            "INSERT INTO llm_models (display_name, {$lq}, base_url, api_key, model_name, model_type, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$displayName, $displayName, $baseUrl, $apiKey, $modelName, $type, $sort]);
    } elseif ($legacy !== null) {
        $lq = model_quote_column($legacy);
        $stmt = db()->prepare(
            "INSERT INTO llm_models ({$lq}, base_url, api_key, model_name, model_type, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$displayName, $baseUrl, $apiKey, $modelName, $type, $sort]);
    } else {
        $stmt = db()->prepare(
            'INSERT INTO llm_models (display_name, base_url, api_key, model_name, model_type, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$displayName, $baseUrl, $apiKey, $modelName, $type, $sort]);
    }

    return (int) db()->lastInsertId();
}

function model_update(
    int $id,
    string $displayName,
    string $baseUrl,
    string $modelName,
    string $apiKey,
    int $sort,
    string $type
): void {
    models_fix_schema();
    db()->prepare(
        'UPDATE llm_models
         SET display_name = ?, base_url = ?, api_key = ?, model_name = ?, model_type = ?, sort_order = ?
         WHERE id = ?'
    )->execute([
        trim($displayName),
        rtrim(trim($baseUrl), '/'),
        trim($apiKey),
        trim($modelName),
        model_normalize_type($type),
        $sort,
        $id,
    ]);
}

function model_delete(int $id): void
{
    db()->prepare('DELETE FROM llm_models WHERE id = ?')->execute([$id]);
}

function model_update_enabled(int $id, bool $enabled): void
{
    db()->prepare('UPDATE llm_models SET is_enabled = ? WHERE id = ?')
        ->execute([$enabled ? 1 : 0, $id]);
}

function models_ensure_default(): void
{
    models_fix_schema();
    models_ensure_builtin();
}

/** @return list<array{display_name:string,base_url:string,api_key:string,model_name:string,model_type:string,sort_order:int}> */
function models_builtin_definitions(): array
{
    $base = defined('AGNES_BASE_URL') ? AGNES_BASE_URL : 'https://apihub.agnes-ai.com/v1';
    $key = defined('AGNES_API_KEY') ? AGNES_API_KEY : (defined('OLLAMA_API_KEY') ? OLLAMA_API_KEY : '');
    $chat = defined('AGNES_CHAT_MODEL') ? AGNES_CHAT_MODEL : (defined('OLLAMA_MODEL') ? OLLAMA_MODEL : 'agnes-2.0-flash');
    $image = defined('AGNES_IMAGE_MODEL') ? AGNES_IMAGE_MODEL : 'agnes-image-2.0-flash';
    $video = defined('AGNES_VIDEO_MODEL') ? AGNES_VIDEO_MODEL : 'agnes-video-v2.0';

    $base = rtrim(preg_replace('#/chat/completions/?$#', '', rtrim($base, '/')) ?? $base, '/');

    $comfyBase = defined('COMFYUI_BASE_URL') ? rtrim((string) COMFYUI_BASE_URL, '/') : 'http://127.0.0.1:8188';

    $defs = [
        [
            'display_name' => 'Agnes 对话',
            'base_url'     => $base,
            'api_key'      => $key,
            'model_name'   => $chat,
            'model_type'   => 'chat',
            'sort_order'   => 0,
        ],
        [
            'display_name' => 'Agnes 生图',
            'base_url'     => $base,
            'api_key'      => $key,
            'model_name'   => $image,
            'model_type'   => 'image',
            'sort_order'   => 0,
        ],
        [
            'display_name' => 'Agnes 生视频',
            'base_url'     => $base,
            'api_key'      => $key,
            'model_name'   => $video,
            'model_type'   => 'video',
            'sort_order'   => 0,
        ],
        [
            'display_name' => 'ComfyUI SDXL',
            'base_url'     => $comfyBase,
            'api_key'      => '',
            'model_name'   => 'comfyui',
            'model_type'   => 'image',
            'sort_order'   => -10,
        ],
    ];

    return $defs;
}

function models_ensure_builtin(): void
{
    static $running = false;
    if ($running) {
        return;
    }
    $running = true;
    try {
        foreach (models_builtin_definitions() as $def) {
            $stmt = db()->prepare(
                'SELECT id FROM llm_models WHERE model_type = ? AND model_name = ? LIMIT 1'
            );
            $stmt->execute([$def['model_type'], $def['model_name']]);
            if ($stmt->fetch()) {
                continue;
            }
            model_create(
                $def['display_name'],
                $def['base_url'],
                $def['model_name'],
                $def['api_key'],
                $def['sort_order'],
                $def['model_type']
            );
        }

        foreach (models_builtin_definitions() as $def) {
            if ($def['api_key'] === '') {
                continue;
            }
            db()->prepare(
                'UPDATE llm_models SET api_key = ?, base_url = ?, display_name = ?
                 WHERE model_type = ? AND model_name = ?'
            )->execute([
                $def['api_key'],
                $def['base_url'],
                $def['display_name'],
                $def['model_type'],
                $def['model_name'],
            ]);
        }
    } catch (Throwable) {
    } finally {
        $running = false;
    }
}

function models_fix_schema(): void
{
    unset($GLOBALS['_models_columns_cache']);

    $pdo = db();
    $fields = models_table_columns();
    if ($fields === []) {
        return;
    }

    $hasDisplay = in_array('display_name', $fields, true);
    $legacy = models_legacy_name_column();

    try {
        if ($legacy !== null && $hasDisplay) {
            $lq = model_quote_column($legacy);
            $pdo->exec(
                "UPDATE llm_models SET display_name = COALESCE(NULLIF(display_name, ''), {$lq})
                 WHERE display_name = '' OR display_name IS NULL"
            );
            $pdo->exec("ALTER TABLE llm_models DROP COLUMN {$lq}");
        } elseif ($legacy !== null && !$hasDisplay) {
            $lq = model_quote_column($legacy);
            $pdo->exec(
                "ALTER TABLE llm_models CHANGE COLUMN {$lq} display_name VARCHAR(64) NOT NULL COMMENT '显示名称'"
            );
        } elseif (!$hasDisplay) {
            $pdo->exec(
                "ALTER TABLE llm_models ADD COLUMN display_name VARCHAR(64) NOT NULL DEFAULT '未命名' AFTER id"
            );
            $pdo->exec(
                "UPDATE llm_models SET display_name = COALESCE(NULLIF(model_name, ''), '未命名')
                 WHERE display_name = '' OR display_name = '未命名'"
            );
        }
    } catch (Throwable) {
    }

    unset($GLOBALS['_models_columns_cache']);
    $fields = models_table_columns();

    if (!in_array('model_type', $fields, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE llm_models ADD COLUMN model_type ENUM('chat','image','video') NOT NULL DEFAULT 'chat' AFTER model_name"
            );
        } catch (Throwable) {
        }
    }

    unset($GLOBALS['_models_columns_cache']);

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_daily_usage (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id INT UNSIGNED NOT NULL,
              usage_date DATE NOT NULL,
              chat_rounds INT UNSIGNED NOT NULL DEFAULT 0,
              image_count INT UNSIGNED NOT NULL DEFAULT 0,
              video_count INT UNSIGNED NOT NULL DEFAULT 0,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uk_user_date (user_id, usage_date),
              KEY idx_usage_date (usage_date),
              CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable) {
    }
}

/** 对话/教案：按 ID 解析 chat 模型，无效时回退到首个启用的对话模型 */
function model_resolve_for_chat(int $modelId = 0): ?array
{
    models_ensure_default();
    $llm = null;
    if ($modelId > 0) {
        $candidate = model_get($modelId, true);
        if ($candidate && model_normalize_type((string) ($candidate['model_type'] ?? 'chat')) === 'chat') {
            $llm = $candidate;
        }
    }
    if (!$llm) {
        $enabled = models_list_enabled_by_type('chat');
        $llm = $enabled[0] ?? null;
    }
    return $llm;
}

/** @return array{api_key:string,base_url:string,model_name:string} */
function model_chat_runtime(array $llm): array
{
    require_once __DIR__ . '/model_remote.php';

    $apiKey = trim((string) ($llm['api_key'] ?? ''));
    if ($apiKey === '' && defined('OLLAMA_API_KEY')) {
        $apiKey = trim((string) OLLAMA_API_KEY);
    }

    $baseUrl = trim((string) ($llm['base_url'] ?? ''));
    if ($baseUrl === '' && defined('OLLAMA_BASE_URL')) {
        $baseUrl = trim((string) OLLAMA_BASE_URL);
    }

    return [
        'api_key'    => $apiKey,
        'base_url'   => model_normalize_base_url($baseUrl),
        'model_name' => trim((string) ($llm['model_name'] ?? '')),
    ];
}
