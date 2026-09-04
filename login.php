<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/settings.php';

if (current_user()) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/chat.php');
    exit;
}

$base = rtrim(SITE_URL, '/');
$oidcOn = setting_bool('enable_oidc_auth', true);
$localOn = setting_bool('enable_local_auth', ENABLE_LOCAL_AUTH);
$error = $_GET['error'] ?? null;
$page_title = '登录';
require __DIR__ . '/includes/ui_head.php';
?>

<div class="auth-page">
  <div class="auth-card">
    <?php $brand_logo_variant = 'auth'; require __DIR__ . '/includes/brand_logo.php'; ?>
    <h1 class="auth-card__title">欢迎回来</h1>
    <p class="auth-card__subtitle">登录你的<?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?>账户</p>

    <?php if (!empty($error)): ?>
      <div class="alert alert-error" style="margin-top:1rem;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($localOn): ?>
    <form class="auth-form" method="post" action="<?= htmlspecialchars($base . '/auth/local_login.php', ENT_QUOTES) ?>" style="margin-top:1rem;">
      <div class="c-field">
        <label class="c-label">账号</label>
        <input class="c-input c-input--lg" type="text" name="campus_uid" required
               minlength="<?= LOCAL_UID_MIN_LEN ?>" maxlength="<?= LOCAL_UID_MAX_LEN ?>"
               pattern="[A-Za-z0-9_\-\.]+" autocomplete="username" placeholder="校内 UID">
      </div>
      <div class="c-field">
        <label class="c-label">密码</label>
        <input class="c-input c-input--lg" type="password" name="password" required
               minlength="<?= LOCAL_PASSWORD_MIN_LEN ?>" autocomplete="current-password" placeholder="请输入密码">
      </div>
      <button class="c-btn c-btn--primary c-btn--lg c-btn--block" type="submit" style="margin-top:8px;">登录</button>
    </form>
    <?php endif; ?>

    <?php if ($oidcOn && $localOn): ?>
    <div class="auth-form__divider">或</div>
    <?php endif; ?>

    <?php if ($oidcOn): ?>
    <a href="<?= htmlspecialchars($base . '/auth/sso_login.php', ENT_QUOTES) ?>" class="c-btn c-btn--secondary c-btn--lg c-btn--block">
      <?= htmlspecialchars(OIDC_PROVIDER_NAME, ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php endif; ?>

    <p class="auth-form__footer">
      <?php if ($localOn): ?>还没有账户?<a href="<?= htmlspecialchars($base . '/register.php', ENT_QUOTES) ?>">立即注册</a> · <?php endif; ?>
      <a href="<?= htmlspecialchars($base . '/', ENT_QUOTES) ?>">返回首页</a>
    </p>
  </div>

  <?php require __DIR__ . '/includes/auth_sponsor.php'; ?>
</div>

<?php require __DIR__ . '/includes/ui_foot.php'; ?>
