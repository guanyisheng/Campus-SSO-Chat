<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/settings.php';

$user = current_user();
$base = rtrim(SITE_URL, '/');

if ($user) {
    header('Location: ' . $base . '/chat.php');
    exit;
}

$oidcOn = setting_bool('enable_oidc_auth', true);
$localOn = setting_bool('enable_local_auth', ENABLE_LOCAL_AUTH);
$page_title = APP_NAME;
require __DIR__ . '/includes/ui_head.php';
?>

<div class="auth-page">
  <div class="auth-card">
    <?php $brand_logo_variant = 'auth'; require __DIR__ . '/includes/brand_logo.php'; ?>
    <h1 class="auth-card__title"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="auth-card__subtitle"><?= htmlspecialchars(APP_SUBTITLE, ENT_QUOTES, 'UTF-8') ?></p>

    <div style="display:flex;flex-direction:column;gap:10px;margin-top:1.5rem;">
      <a href="<?= htmlspecialchars($base . '/login.php', ENT_QUOTES) ?>" class="c-btn c-btn--primary c-btn--lg c-btn--block">登录</a>
      <?php if ($localOn): ?>
      <a href="<?= htmlspecialchars($base . '/register.php', ENT_QUOTES) ?>" class="c-btn c-btn--secondary c-btn--lg c-btn--block">免费注册</a>
      <?php endif; ?>
      <?php if ($oidcOn): ?>
      <a href="<?= htmlspecialchars($base . '/auth/sso_login.php', ENT_QUOTES) ?>" class="c-btn c-btn--secondary c-btn--lg c-btn--block">
        <?= htmlspecialchars(OIDC_PROVIDER_NAME, ENT_QUOTES, 'UTF-8') ?>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <?php require __DIR__ . '/includes/auth_sponsor.php'; ?>
</div>

<?php
$ui_extra_js = [];
require __DIR__ . '/includes/ui_foot.php';
