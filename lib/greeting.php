<?php
declare(strict_types=1);

require_once __DIR__ . '/oidc_profile.php';

/**
 * 用于界面展示的友好称呼（不用纯数字 UID）
 */
function user_friendly_name(array $user): string
{
    $name = oidc_sanitize_display_name(
        (string) ($user['display_name'] ?? ''),
        (string) ($user['campus_uid'] ?? '')
    );
    if ($name !== '') {
        return $name;
    }
    return '同学';
}

/**
 * 按当前时段生成问候语 + 姓名
 */
function greeting_with_name(string $displayName): string
{
    $name = oidc_sanitize_display_name($displayName);
    if ($name === '') {
        $name = '同学';
    }

    $hour = (int) date('G');
    if ($hour >= 0 && $hour < 6) {
        $period = '夜深了';
    } elseif ($hour < 11) {
        $period = '早上好';
    } elseif ($hour < 13) {
        $period = '中午好';
    } elseif ($hour < 18) {
        $period = '下午好';
    } else {
        $period = '晚上好';
    }

    return $period . '，' . $name;
}

/**
 * 根据登录用户生成问候（推荐在页面中使用）
 */
function greeting_for_user(array $user): string
{
    return greeting_with_name(user_friendly_name($user));
}
