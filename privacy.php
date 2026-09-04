<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/privacy_policy.php';

$base = rtrim(SITE_URL, '/');
$backUrl = current_user() ? $base . '/chat.php' : $base . '/';
$page_title = '隐私政策';
require __DIR__ . '/includes/ui_head.php';
?>

<div class="legal-page">
  <div class="legal-page__inner">
    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="legal-page__back c-btn c-btn--ghost c-btn--sm">← 返回</a>
    <div class="legal-page__body"><?= privacy_policy_html($base) ?></div>
  </div>
</div>

<?php require __DIR__ . '/includes/ui_foot.php'; ?>
