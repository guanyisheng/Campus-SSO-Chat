<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/models.php';
require_once __DIR__ . '/site.php';

const USER_AGENT_MAX_COUNT = 3;

function agents_avatar_storage_dirs(): array
{
    require_once __DIR__ . '/branding_storage.php';

    $dirs = [];
    if (defined('BRANDING_STORAGE_DIR')) {
        $dirs[] = dirname((string) BRANDING_STORAGE_DIR) . '/agents';
    }
    $dirs[] = dirname(__DIR__) . '/storage/agents';
    $dirs[] = branding_storage_dir();

    $out = [];
    foreach ($dirs as $dir) {
        if ($dir !== '' && is_dir($dir)) {
            $out[] = $dir;
        }
    }

    return array_values(array_unique($out));
}

function agents_storage_dir(): string
{
    require_once __DIR__ . '/branding_storage.php';

    $candidates = [];
    if (defined('BRANDING_STORAGE_DIR')) {
        $parent = dirname((string) BRANDING_STORAGE_DIR);
        $candidates[] = $parent . '/agents';
    }
    $candidates[] = dirname(__DIR__) . '/storage/agents';

    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
    }

    $fallback = branding_storage_dir();
    if (is_dir($fallback) && is_writable($fallback)) {
        return $fallback;
    }

    throw new RuntimeException(
        '智能体图片目录不可写，请在服务器执行：mkdir -p storage/agents && chown -R www:www storage/agents && chmod -R 755 storage/agents'
    );
}

function agents_fix_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = db();
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ai_agent_presets (
              id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              display_name  VARCHAR(64)  NOT NULL,
              description   VARCHAR(512) NOT NULL DEFAULT \'\',
              system_prompt MEDIUMTEXT   NOT NULL,
              avatar_file   VARCHAR(255) NOT NULL DEFAULT \'\',
              model_id      INT UNSIGNED NULL,
              is_enabled    TINYINT(1)   NOT NULL DEFAULT 1,
              sort_order    INT          NOT NULL DEFAULT 0,
              created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_enabled_sort (is_enabled, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_agents (
              id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id       INT UNSIGNED NOT NULL,
              display_name  VARCHAR(64)  NOT NULL,
              description   VARCHAR(512) NOT NULL DEFAULT \'\',
              system_prompt MEDIUMTEXT   NOT NULL,
              avatar_file   VARCHAR(255) NOT NULL DEFAULT \'\',
              model_id      INT UNSIGNED NULL,
              created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_user (user_id),
              CONSTRAINT fk_user_agent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_agent_assignments (
              user_id     INT UNSIGNED NOT NULL,
              preset_id   INT UNSIGNED NOT NULL,
              assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (user_id, preset_id),
              CONSTRAINT fk_assign_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_assign_preset FOREIGN KEY (preset_id) REFERENCES ai_agent_presets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable) {
    }
}

function agent_avatar_public_url(string $filename): string
{
    $filename = basename($filename);
    if ($filename === '') {
        return '';
    }
    return site_base_url() . '/api/agent_avatar.php?f=' . rawurlencode($filename);
}

/**
 * @return array{filename:string,url:string}
 */
function agent_save_upload(array $file, string $prefix = 'agent'): array
{
    require_once __DIR__ . '/branding_storage.php';
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
    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new InvalidArgumentException('上传临时文件无效');
    }

    $lastError = '';
    $dirs = [];
    try {
        $dirs[] = agents_storage_dir();
    } catch (RuntimeException) {
    }
    $dirs[] = branding_storage_dir();
    foreach (array_values(array_unique($dirs)) as $dir) {
        if (!is_dir($dir) || !is_writable($dir)) {
            continue;
        }
        $dest = $dir . DIRECTORY_SEPARATOR . $filename;
        if (@move_uploaded_file($tmpPath, $dest)) {
            return ['filename' => $filename, 'url' => agent_avatar_public_url($filename)];
        }
        if (@copy($tmpPath, $dest)) {
            @unlink($tmpPath);
            return ['filename' => $filename, 'url' => agent_avatar_public_url($filename)];
        }
        $lastError = $dir;
    }

    throw new InvalidArgumentException(
        '保存文件失败：storage/agents 无写入权限'
        . ($lastError !== '' ? '（' . $lastError . '）' : '')
        . '。请 chown -R www:www storage && chmod -R 755 storage，或不传头像先保存'
    );
}

function agent_delete_avatar_file(string $filename): void
{
    $filename = basename($filename);
    if ($filename === '') {
        return;
    }
    foreach (agents_avatar_storage_dirs() as $dir) {
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            @unlink($path);
            return;
        }
    }
}

/** @return list<array<string, mixed>> */
function agent_presets_list_all(bool $enabledOnly = false): array
{
    agents_fix_schema();
    $sql = 'SELECT * FROM ai_agent_presets';
    if ($enabledOnly) {
        $sql .= ' WHERE is_enabled = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function agent_preset_get(int $id): ?array
{
    agents_fix_schema();
    $stmt = db()->prepare('SELECT * FROM ai_agent_presets WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function agent_preset_create(
    string $displayName,
    string $systemPrompt,
    int $modelId = 0,
    string $description = '',
    string $avatarFile = '',
    int $sortOrder = 0,
    bool $enabled = true
): int {
    agents_fix_schema();
    $stmt = db()->prepare(
        'INSERT INTO ai_agent_presets (display_name, description, system_prompt, avatar_file, model_id, is_enabled, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        trim($displayName),
        trim($description),
        trim($systemPrompt),
        basename($avatarFile),
        $modelId > 0 ? $modelId : null,
        $enabled ? 1 : 0,
        $sortOrder,
    ]);
    return (int) db()->lastInsertId();
}

function agent_preset_update(
    int $id,
    string $displayName,
    string $systemPrompt,
    int $modelId = 0,
    string $description = '',
    string $avatarFile = '',
    int $sortOrder = 0,
    bool $enabled = true
): bool {
    agents_fix_schema();
    $existing = agent_preset_get($id);
    if (!$existing) {
        return false;
    }
    $avatar = $avatarFile !== '' ? basename($avatarFile) : (string) ($existing['avatar_file'] ?? '');
    $stmt = db()->prepare(
        'UPDATE ai_agent_presets SET display_name = ?, description = ?, system_prompt = ?, avatar_file = ?,
         model_id = ?, is_enabled = ?, sort_order = ? WHERE id = ?'
    );
    $stmt->execute([
        trim($displayName),
        trim($description),
        trim($systemPrompt),
        $avatar,
        $modelId > 0 ? $modelId : null,
        $enabled ? 1 : 0,
        $sortOrder,
        $id,
    ]);
    return true;
}

function agent_preset_delete(int $id): bool
{
    agents_fix_schema();
    $row = agent_preset_get($id);
    if (!$row) {
        return false;
    }
    db()->prepare('DELETE FROM user_agent_assignments WHERE preset_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM ai_agent_presets WHERE id = ?')->execute([$id]);
    if (!empty($row['avatar_file'])) {
        agent_delete_avatar_file((string) $row['avatar_file']);
    }
    return true;
}

function user_agent_count(int $userId): int
{
    agents_fix_schema();
    $stmt = db()->prepare('SELECT COUNT(*) FROM user_agents WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/** @return list<array<string, mixed>> */
function user_agents_list(int $userId): array
{
    agents_fix_schema();
    $stmt = db()->prepare('SELECT * FROM user_agents WHERE user_id = ? ORDER BY id ASC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function user_agent_get(int $id, int $userId): ?array
{
    agents_fix_schema();
    $stmt = db()->prepare('SELECT * FROM user_agents WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function user_agent_create(
    int $userId,
    string $displayName,
    string $systemPrompt,
    int $modelId = 0,
    string $description = '',
    string $avatarFile = ''
): int {
    agents_fix_schema();
    if (user_agent_count($userId) >= USER_AGENT_MAX_COUNT) {
        throw new InvalidArgumentException('每个用户最多创建 ' . USER_AGENT_MAX_COUNT . ' 个智能体');
    }
    $displayName = trim($displayName);
    $systemPrompt = trim($systemPrompt);
    if ($displayName === '') {
        throw new InvalidArgumentException('请填写智能体名称');
    }
    if ($systemPrompt === '') {
        throw new InvalidArgumentException('请填写提示词');
    }
    $stmt = db()->prepare(
        'INSERT INTO user_agents (user_id, display_name, description, system_prompt, avatar_file, model_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $displayName,
        trim($description),
        $systemPrompt,
        basename($avatarFile),
        $modelId > 0 ? $modelId : null,
    ]);
    return (int) db()->lastInsertId();
}

function user_agent_update(
    int $id,
    int $userId,
    string $displayName,
    string $systemPrompt,
    int $modelId = 0,
    string $description = '',
    string $avatarFile = ''
): bool {
    agents_fix_schema();
    $existing = user_agent_get($id, $userId);
    if (!$existing) {
        return false;
    }
    $displayName = trim($displayName);
    $systemPrompt = trim($systemPrompt);
    if ($displayName === '' || $systemPrompt === '') {
        throw new InvalidArgumentException('名称与提示词不能为空');
    }
    $avatar = $avatarFile !== '' ? basename($avatarFile) : (string) ($existing['avatar_file'] ?? '');
    $stmt = db()->prepare(
        'UPDATE user_agents SET display_name = ?, description = ?, system_prompt = ?, avatar_file = ?, model_id = ?
         WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([
        $displayName,
        trim($description),
        $systemPrompt,
        $avatar,
        $modelId > 0 ? $modelId : null,
        $id,
        $userId,
    ]);
    return true;
}

function user_agent_delete(int $id, int $userId): bool
{
    agents_fix_schema();
    $row = user_agent_get($id, $userId);
    if (!$row) {
        return false;
    }
    db()->prepare('DELETE FROM user_agents WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    if (!empty($row['avatar_file'])) {
        agent_delete_avatar_file((string) $row['avatar_file']);
    }
    return true;
}

/** @return list<int> */
function user_agent_assigned_preset_ids(int $userId): array
{
    agents_fix_schema();
    $stmt = db()->prepare('SELECT preset_id FROM user_agent_assignments WHERE user_id = ? ORDER BY assigned_at ASC');
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function user_agent_assign_preset(int $userId, int $presetId): bool
{
    agents_fix_schema();
    if (!agent_preset_get($presetId)) {
        return false;
    }
    $stmt = db()->prepare(
        'INSERT IGNORE INTO user_agent_assignments (user_id, preset_id) VALUES (?, ?)'
    );
    $stmt->execute([$userId, $presetId]);
    return true;
}

function user_agent_unassign_preset(int $userId, int $presetId): bool
{
    agents_fix_schema();
    db()->prepare('DELETE FROM user_agent_assignments WHERE user_id = ? AND preset_id = ?')
        ->execute([$userId, $presetId]);
    return true;
}

/** @return list<array<string, mixed>> */
function user_agent_assignments_for_preset(int $presetId): array
{
    agents_fix_schema();
    $stmt = db()->prepare(
        'SELECT u.id, u.display_name, u.campus_uid, uaa.assigned_at
         FROM user_agent_assignments uaa
         JOIN users u ON u.id = uaa.user_id
         WHERE uaa.preset_id = ?
         ORDER BY uaa.assigned_at DESC'
    );
    $stmt->execute([$presetId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{type:string,id:int,display_name:string,description:string,system_prompt:string,avatar_url:string,model_id:?int,is_preset:bool}|null
 */
function agent_row_to_public(array $row, string $type): array
{
    $avatarFile = (string) ($row['avatar_file'] ?? '');
    return [
        'type'          => $type,
        'id'            => (int) ($row['id'] ?? 0),
        'display_name'  => (string) ($row['display_name'] ?? ''),
        'description'   => (string) ($row['description'] ?? ''),
        'system_prompt' => (string) ($row['system_prompt'] ?? ''),
        'avatar_url'    => $avatarFile !== '' ? agent_avatar_public_url($avatarFile) : '',
        'model_id'      => isset($row['model_id']) && $row['model_id'] !== null ? (int) $row['model_id'] : null,
        'is_preset'     => $type === 'preset',
    ];
}

/** @return list<array<string, mixed>> */
function agents_list_for_user(int $userId): array
{
    require_once __DIR__ . '/conversations.php';
    agents_fix_schema();
    $out = [];
    foreach (user_agent_assigned_preset_ids($userId) as $presetId) {
        $row = agent_preset_get($presetId);
        if (!$row || !(int) ($row['is_enabled'] ?? 0)) {
            continue;
        }
        $pub = agent_row_to_public($row, 'preset');
        $conv = conv_find_for_agent($userId, ['type' => 'preset', 'id' => (int) $pub['id']]);
        $pub['conversation_id'] = $conv ? (int) $conv['id'] : null;
        $out[] = $pub;
    }
    foreach (user_agents_list($userId) as $row) {
        $pub = agent_row_to_public($row, 'user');
        $conv = conv_find_for_agent($userId, ['type' => 'user', 'id' => (int) $pub['id']]);
        $pub['conversation_id'] = $conv ? (int) $conv['id'] : null;
        $out[] = $pub;
    }
    return $out;
}

/**
 * @param array{type?:string,id?:int}|string|null $ref
 * @return array{type:string,id:int,display_name:string,description:string,system_prompt:string,avatar_url:string,model_id:?int,is_preset:bool}|null
 */
function agent_resolve_for_user(int $userId, $ref): ?array
{
    if ($ref === null || $ref === '' || $ref === []) {
        return null;
    }

    $type = '';
    $id = 0;
    if (is_string($ref)) {
        if (preg_match('/^(preset|user)[:\-](\d+)$/', $ref, $m)) {
            $type = $m[1];
            $id = (int) $m[2];
        }
    } elseif (is_array($ref)) {
        $type = (string) ($ref['type'] ?? '');
        $id = (int) ($ref['id'] ?? 0);
    }

    if ($id <= 0 || !in_array($type, ['preset', 'user'], true)) {
        return null;
    }

    if ($type === 'preset') {
        $row = agent_preset_get($id);
        if (!$row || !(int) ($row['is_enabled'] ?? 0)) {
            return null;
        }
        $assigned = user_agent_assigned_preset_ids($userId);
        if (!in_array($id, $assigned, true)) {
            return null;
        }
        return agent_row_to_public($row, 'preset');
    }

    $row = user_agent_get($id, $userId);
    return $row ? agent_row_to_public($row, 'user') : null;
}

function agent_ref_key(string $type, int $id): string
{
    return $type . ':' . $id;
}

/** @param array{type?:string,id?:int}|null $ref */
function agent_parse_ref($ref): ?array
{
    if ($ref === null || $ref === '') {
        return null;
    }
    if (is_string($ref) && preg_match('/^(preset|user)[:\-](\d+)$/', $ref, $m)) {
        return ['type' => $m[1], 'id' => (int) $m[2]];
    }
    if (is_array($ref)) {
        $type = (string) ($ref['type'] ?? '');
        $id = (int) ($ref['id'] ?? 0);
        if ($id > 0 && in_array($type, ['preset', 'user'], true)) {
            return ['type' => $type, 'id' => $id];
        }
    }
    return null;
}

/** @param list<array{role:string,content:string}> $messages */
function agent_inject_system(array $messages, string $agentPrompt): array
{
    $agentPrompt = trim($agentPrompt);
    if ($agentPrompt === '') {
        return $messages;
    }

    $hasSystem = false;
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'system') {
            $hasSystem = true;
            break;
        }
    }

    if ($hasSystem) {
        $out = [];
        $injected = false;
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system' && !$injected) {
                $content = trim((string) ($m['content'] ?? ''));
                $out[] = [
                    'role'    => 'system',
                    'content' => $content !== '' ? ($content . "\n\n" . $agentPrompt) : $agentPrompt,
                ];
                $injected = true;
            } else {
                $out[] = $m;
            }
        }
        if (!$injected) {
            array_unshift($out, ['role' => 'system', 'content' => $agentPrompt]);
        }
        return $out;
    }

    return array_merge(
        [['role' => 'system', 'content' => $agentPrompt]],
        $messages
    );
}

function agents_status_for_user(int $userId): array
{
    return [
        'max_user_agents'  => USER_AGENT_MAX_COUNT,
        'user_agent_count' => user_agent_count($userId),
        'can_create'       => user_agent_count($userId) < USER_AGENT_MAX_COUNT,
    ];
}
