<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/settings.php';

if (current_user()) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/chat.php');
    exit;
}

if (!setting_bool('enable_local_auth', ENABLE_LOCAL_AUTH)) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/login.php');
    exit;
}

$base = rtrim(SITE_URL, '/');
$error = $_GET['error'] ?? null;
$page_title = '注册';
require __DIR__ . '/includes/ui_head.php';
?>

<div class="auth-page">
  <div class="auth-card">
    <?php $brand_logo_variant = 'auth'; require __DIR__ . '/includes/brand_logo.php'; ?>
    <h1 class="auth-card__title">创建账户</h1>
    <p class="auth-card__subtitle">注册后即可开始对话</p>

    <?php if (!empty($error)): ?>
      <div class="alert alert-error" style="margin-top:1rem;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form class="auth-form" method="post" action="<?= htmlspecialchars($base . '/auth/local_register.php', ENT_QUOTES) ?>" style="margin-top:1rem;">
      <div class="c-field">
        <label class="c-label">账号</label>
        <input class="c-input c-input--lg" type="text" name="campus_uid" required
               minlength="<?= LOCAL_UID_MIN_LEN ?>" maxlength="<?= LOCAL_UID_MAX_LEN ?>"
               pattern="[A-Za-z0-9_\-\.]+" autocomplete="username" placeholder="校内 UID">
      </div>
      <div class="c-field">
        <label class="c-label">昵称</label>
        <input class="c-input c-input--lg" type="text" name="display_name" maxlength="128" placeholder="如何称呼你（可选）">
      </div>
      <div class="c-field">
        <label class="c-label">密码</label>
        <input class="c-input c-input--lg" type="password" name="password" required
               minlength="<?= LOCAL_PASSWORD_MIN_LEN ?>" autocomplete="new-password" placeholder="至少 <?= LOCAL_PASSWORD_MIN_LEN ?> 位">
      </div>
      <div class="c-field">
        <label class="c-label">确认密码</label>
        <input class="c-input c-input--lg" type="password" name="password_confirm" required minlength="<?= LOCAL_PASSWORD_MIN_LEN ?>">
      </div>
      <button class="c-btn c-btn--primary c-btn--lg c-btn--block" type="submit">注册并登录</button>
      <p class="auth-form__footer">
        已有账户?<a href="<?= htmlspecialchars($base . '/login.php', ENT_QUOTES) ?>">直接登录</a>
      </p>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/ui_foot.php'; ?>
