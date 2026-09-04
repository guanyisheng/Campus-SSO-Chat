<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';

$base = rtrim(SITE_URL, '/');

if (is_admin()) {
    header('Location: ' . $base . '/admin/dashboard.php');
    exit;
}

$error = $_GET['error'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    if (admin_login($user, $pass)) {
        header('Location: ' . $base . '/admin/dashboard.php');
        exit;
    }
    $error = '账号或密码错误';
}

$page_title = '管理后台';
$ui_css = 'admin';
require dirname(__DIR__) . '/includes/ui_head.php';
?>

<div class="auth-page">
  <div class="auth-card">
    <?php $brand_logo_variant = 'auth'; require dirname(__DIR__) . '/includes/brand_logo.php'; ?>
    <h1 class="auth-card__title">管理后台</h1>
    <p class="auth-card__subtitle"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></p>

    <?php if ($error): ?>
      <div class="alert alert-error" style="margin-top:1rem;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" class="auth-form admin-page" style="margin-top:1rem;">
      <div class="c-field">
        <label class="c-label">账号</label>
        <input class="c-input c-input--lg" type="text" name="username" required autocomplete="username">
      </div>
      <div class="c-field">
        <label class="c-label">密码</label>
        <input class="c-input c-input--lg" type="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="c-btn c-btn--primary c-btn--lg c-btn--block" style="margin-top:8px;">登录</button>
      <p class="auth-form__footer"><a href="<?= htmlspecialchars($base . '/', ENT_QUOTES) ?>">返回首页</a></p>
    </form>
  </div>
</div>

<?php require dirname(__DIR__) . '/includes/ui_foot.php'; ?>
