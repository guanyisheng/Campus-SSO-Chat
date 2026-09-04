<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

function quota_global_daily_limit(string $type): int
{
    $type = quota_normalize_type($type);
    $key = match ($type) {
        'image' => 'daily_image_limit',
        'video' => 'daily_video_limit',
        default => 'daily_chat_limit',
    };
    $default = match ($type) {
        'image' => 20,
        'video' => 10,
        default => 100,
    };
    return max(0, setting_int($key, $default));
}

function quota_daily_limit(string $type, ?int $userId = null): int
{
    if ($userId !== null && $userId > 0) {
        require_once __DIR__ . '/user_groups.php';
        user_groups_fix_schema();
        return user_group_daily_limit($userId, quota_normalize_type($type));
    }
    return quota_global_daily_limit($type);
}

function quota_today_date(): string
{
    return date('Y-m-d');
}

/** @return 'chat'|'image'|'video' */
function quota_normalize_type(string $type): string
{
    return match ($type) {
        'image', 'video' => $type,
        default => 'chat',
    };
}

function quota_feature_enabled(string $type): bool
{
    return match (quota_normalize_type($type)) {
        'image' => setting_bool('enable_image_gen', true),
        'video' => setting_bool('enable_video_gen', true),
        default => setting_bool('enable_chat', true),
    };
}

function quota_ensure_row(int $userId, string $date): void
{
    $stmt = db()->prepare(
        'INSERT IGNORE INTO user_daily_usage (user_id, usage_date, chat_rounds, image_count, video_count)
         VALUES (?, ?, 0, 0, 0)'
    );
    $stmt->execute([$userId, $date]);
}

/** @return array{chat_rounds:int,image_count:int,video_count:int} */
function quota_get_today(int $userId): array
{
    $date = quota_today_date();
    try {
        quota_ensure_row($userId, $date);
        $stmt = db()->prepare(
            'SELECT chat_rounds, image_count, video_count FROM user_daily_usage
             WHERE user_id = ? AND usage_date = ? LIMIT 1'
        );
        $stmt->execute([$userId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['chat_rounds' => 0, 'image_count' => 0, 'video_count' => 0];
        }
        return [
            'chat_rounds' => (int) $row['chat_rounds'],
            'image_count' => (int) $row['image_count'],
            'video_count' => (int) $row['video_count'],
        ];
    } catch (Throwable) {
        return ['chat_rounds' => 0, 'image_count' => 0, 'video_count' => 0];
    }
}

function quota_used(int $userId, string $type): int
{
    $usage = quota_get_today($userId);
    return match (quota_normalize_type($type)) {
        'image' => $usage['image_count'],
        'video' => $usage['video_count'],
        default => $usage['chat_rounds'],
    };
}

function quota_remaining(int $userId, string $type): int
{
    $limit = quota_daily_limit($type, $userId);
    if ($limit <= 0) {
        return PHP_INT_MAX;
    }
    return max(0, $limit - quota_used($userId, $type));
}

function quota_check(int $userId, string $type): bool
{
    if (!quota_feature_enabled($type)) {
        return false;
    }
    $limit = quota_daily_limit($type, $userId);
    if ($limit <= 0) {
        return true;
    }
    return quota_used($userId, $type) < $limit;
}

function quota_consume(int $userId, string $type, int $amount = 1): void
{
    $type = quota_normalize_type($type);
    $amount = max(1, $amount);
    $date = quota_today_date();
    quota_ensure_row($userId, $date);

    $column = match ($type) {
        'image' => 'image_count',
        'video' => 'video_count',
        default => 'chat_rounds',
    };

    $sql = "UPDATE user_daily_usage SET {$column} = {$column} + ? WHERE user_id = ? AND usage_date = ?";
    db()->prepare($sql)->execute([$amount, $userId, $date]);
}

function quota_refund(int $userId, string $type, int $amount = 1): void
{
    $type = quota_normalize_type($type);
    $amount = max(1, $amount);
    $date = quota_today_date();
    quota_ensure_row($userId, $date);

    $column = match ($type) {
        'image' => 'image_count',
        'video' => 'video_count',
        default => 'chat_rounds',
    };

    $sql = "UPDATE user_daily_usage SET {$column} = GREATEST(0, {$column} - ?) WHERE user_id = ? AND usage_date = ?";
    db()->prepare($sql)->execute([$amount, $userId, $date]);
}

/** @return array<string, mixed> */
function quota_status(int $userId): array
{
    require_once __DIR__ . '/user_groups.php';
    user_groups_fix_schema();
    $usage = quota_get_today($userId);
    $group = user_group_for_user($userId);
    $build = static function (string $type, int $used) use ($userId): array {
        $limit = quota_daily_limit($type, $userId);
        return [
            'enabled'   => quota_feature_enabled($type),
            'used'      => $used,
            'limit'     => $limit,
            'remaining' => $limit <= 0 ? null : max(0, $limit - $used),
        ];
    };

    return [
        'date'       => quota_today_date(),
        'group_id'   => $group ? (int) $group['id'] : null,
        'group_name' => $group ? (string) $group['name'] : '',
        'chat'       => $build('chat', $usage['chat_rounds']),
        'image'      => $build('image', $usage['image_count']),
        'video'      => $build('video', $usage['video_count']),
    ];
}

function quota_error_message(string $type, ?int $userId = null): string
{
    $type = quota_normalize_type($type);
    $limit = quota_daily_limit($type, $userId);
    $used = $userId ? quota_used($userId, $type) : 0;
    $limitText = $limit > 0 ? ('（今日 ' . $used . '/' . $limit . '）') : '';
    return match ($type) {
        'image' => '今日生图次数已用完' . $limitText . '，请明天再试或在个人中心查看额度',
        'video' => '今日生视频次数已用完' . $limitText . '，请明天再试或在个人中心查看额度',
        default => '今日对话额度已用完' . $limitText . '，请明天再试',
    };
}
