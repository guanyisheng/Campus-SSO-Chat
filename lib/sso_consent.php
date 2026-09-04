<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';

const SSO_CONSENT_APP_LABEL = '昆科大模型';

/** OIDC 登录成功后标记：需先经过授权确认页（走过场，不做业务校验） */
function sso_consent_mark_pending(array $oidcProfile): void
{
    app_session_start();
    $_SESSION['sso_needs_consent'] = true;
    $_SESSION['sso_oidc_profile'] = $oidcProfile;
}

function sso_consent_clear_pending(): void
{
    app_session_start();
    unset($_SESSION['sso_needs_consent'], $_SESSION['sso_oidc_profile']);
}

function sso_consent_pending(): bool
{
    app_session_start();
    return !empty($_SESSION['sso_needs_consent']);
}

/** 未确认时从 chat 等页重定向到授权页（仅 OIDC 会话） */
function sso_require_consent_page(): void
{
    if (!sso_consent_pending()) {
        return;
    }
    $user = current_user();
    if (!$user || ($user['auth_source'] ?? '') !== 'oidc') {
        sso_consent_clear_pending();
        return;
    }
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (str_ends_with($script, '/auth/authorize.php') || str_ends_with($script, '/auth/authorize_action.php')) {
        return;
    }
    header('Location: ' . rtrim(SITE_URL, '/') . '/auth/authorize.php');
    exit;
}

/**
 * 展示用字段（有则填，无则 —），不参与权限判定。
 *
 * @return list<array{label: string, value: string}>
 */
function sso_consent_permission_rows(?array $user = null): array
{
    app_session_start();
    $profile = is_array($_SESSION['sso_oidc_profile'] ?? null) ? $_SESSION['sso_oidc_profile'] : [];
    $user = $user ?? current_user() ?? [];

    $fromProfile = static function (array $keys) use ($profile): string {
        foreach ($keys as $key) {
            if (!empty($profile[$key])) {
                return trim((string) $profile[$key]);
            }
        }
        return '';
    };

    $show = static fn(string $v): string => $v !== '' ? $v : '—';

    $campusUid = (string) ($user['campus_uid'] ?? '');
    $displayName = (string) ($user['display_name'] ?? '');

    return [
        ['label' => '姓名', 'value' => $show($fromProfile(['name', 'displayName', 'cn']) ?: $displayName)],
        ['label' => '用户id', 'value' => $show($fromProfile(['sub', 'userId', 'user_id', 'id']) ?: (string) ($user['id'] ?? ''))],
        ['label' => '帐号身份唯一码', 'value' => $show($fromProfile(['identityCode', 'unique_code', 'sub']) ?: $campusUid)],
    ];
}
