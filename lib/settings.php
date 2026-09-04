<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** @return array<string, string> */
function settings_defaults(): array
{
    return [
        'ollama_base_url'      => OLLAMA_BASE_URL,
        'ollama_api_key'       => OLLAMA_API_KEY,
        'ollama_model'         => OLLAMA_MODEL,
        'ollama_max_tokens'    => (string) OLLAMA_MAX_TOKENS,
        'ollama_num_ctx'       => (string) OLLAMA_NUM_CTX,
        'ollama_temperature'   => (string) OLLAMA_TEMPERATURE,
        'ollama_top_p'         => (string) OLLAMA_TOP_P,
        'ollama_history_turns' => (string) OLLAMA_HISTORY_TURNS,
        'ollama_timeout'       => (string) OLLAMA_TIMEOUT,
        'enable_local_auth'    => ENABLE_LOCAL_AUTH ? '1' : '0',
        'enable_oidc_auth'     => '1',
        'enable_chat'          => '1',
        'chat_notice_enabled'  => '1',
        'chat_notice_title'    => '',
        'chat_notice_html'     => '',
        'daily_chat_limit'     => '100',
        'daily_image_limit'    => '20',
        'daily_video_limit'    => '10',
        'enable_image_gen'     => '1',
        'enable_video_gen'     => '1',
        'enable_lesson_plan'   => '1',
        'lesson_plan_max_tokens' => '65536',
        'image_mention_aliases'=> '@图片,@image,@生图',
        'video_mention_aliases'=> '@视频,@video,@生视频',
        'content_policy_enabled'   => '0',
        'content_policy_refusal'   => '',
        'content_policy_system_extra' => '',
        'content_sensitive_words'  => '',
        'default_user_group_id'    => '',
    ];
}

/** @return array<string, string> */
function settings_all(): array
{
    if (isset($GLOBALS['_settings_cache']) && is_array($GLOBALS['_settings_cache'])) {
        return $GLOBALS['_settings_cache'];
    }

    $merged = settings_defaults();
    try {
        $rows = db()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
        foreach ($rows as $row) {
            $merged[$row['setting_key']] = (string) $row['setting_value'];
        }
    } catch (Throwable) {
    }

    $GLOBALS['_settings_cache'] = $merged;
    return $merged;
}

function settings_flush_cache(): void
{
    unset($GLOBALS['_settings_cache']);
}

function setting(string $key, ?string $default = null): string
{
    $all = settings_all();
    if (array_key_exists($key, $all)) {
        return $all[$key];
    }
    return $default ?? '';
}

function setting_bool(string $key, bool $default = true): bool
{
    $v = setting($key, $default ? '1' : '0');
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}

function setting_int(string $key, int $default): int
{
    $v = setting($key, (string) $default);
    return is_numeric($v) ? (int) $v : $default;
}

function setting_float(string $key, float $default): float
{
    $v = setting($key, (string) $default);
    return is_numeric($v) ? (float) $v : $default;
}

function setting_save_many(array $pairs): void
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($pairs as $key => $value) {
        $stmt->execute([$key, (string) $value]);
    }
    settings_flush_cache();
}
