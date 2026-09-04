<?php
/**
 * =============================================================================
 * 【SSO 回调入口】
 * 在 SSO 管理后台将「回调地址 / redirect_uri」配置为：
 *   {SITE_URL}/auth/callback.php
 * 与 config.php 中 SITE_URL 必须一致。
 * =============================================================================
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/sso.php';
require_once dirname(__DIR__) . '/lib/user.php';
require_once dirname(__DIR__) . '/lib/oauth_state.php';
require_once dirname(__DIR__) . '/lib/sso_consent.php';

app_session_start();

$error = null;

try {
    if (!empty($_GET['error'])) {
        throw new RuntimeException('SSO 拒绝授权: ' . ($_GET['error_description'] ?? $_GET['error']));
    }

    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';

    if ($code === '') {
        throw new RuntimeException('缺少授权码 code');
    }

    if (!oauth_verify_state($state)) {
        throw new RuntimeException(
            'state 校验失败：请从本站重新点击统一认证登录，并确保浏览器允许 Cookie；'
            . '访问地址须与 config.php 中 SITE_URL 一致（' . SITE_URL . '）'
        );
    }

    $token = sso_exchange_token($code);
    $userinfo = sso_fetch_userinfo($token['access_token']);
    $profile = sso_resolve_profile($token, $userinfo);

    $campus_uid = sso_extract_campus_uid($profile, $userinfo);
    $display_name = sso_extract_display_name($profile, $campus_uid);

    if (defined('OIDC_CLIENT_ID') && $campus_uid === (string) OIDC_CLIENT_ID) {
        throw new RuntimeException(
            'OIDC 返回的用户标识与应用 client_id 相同，无法区分不同用户。'
            . '请在 config.php 将 OIDC_UID_FIELDS 首位改为 sub，或联系信息中心配置学号字段。'
            . oidc_uid_error_hint($profile, $userinfo)
        );
    }

    $dbUser = upsert_oidc_user($campus_uid, $display_name);

    login_user(session_from_db_user($dbUser));
    sso_consent_mark_pending($profile);

    header('Location: ' . rtrim(SITE_URL, '/') . '/auth/authorize.php');
    exit;
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$page_title = '登录失败';
$base = rtrim(SITE_URL, '/');
require dirname(__DIR__) . '/includes/ui_head.php';
?>

<div class="auth-page">
  <div class="auth-card auth-card--error">
    <?php $brand_logo_variant = 'auth'; require dirname(__DIR__) . '/includes/brand_logo.php'; ?>
    <h1 class="auth-card__title">统一认证未完成</h1>
    <p class="auth-card__subtitle auth-card__subtitle--error"><?= htmlspecialchars($error ?? '未知错误', ENT_QUOTES, 'UTF-8') ?></p>
    <a href="<?= htmlspecialchars($base . '/index.php', ENT_QUOTES) ?>" class="c-btn c-btn--primary c-btn--lg c-btn--block" style="margin-top:1rem;">返回重新登录</a>
  </div>
</div>

<?php require dirname(__DIR__) . '/includes/ui_foot.php'; ?>
