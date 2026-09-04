<?php
declare(strict_types=1);
if (!defined('SITE_URL')) {
    require_once dirname(__DIR__) . '/config.php';
}
/** @var string $brand_logo_variant sidebar|auth */
$variant = $brand_logo_variant ?? 'sidebar';
$base = rtrim(SITE_URL, '/');
$faviconUrl = $base . '/favicon.ico';
$alt = APP_NAME;
$px = $variant === 'auth' ? 48 : 24;
if ($variant === 'auth'): ?>
<div class="auth-card__logo brand-logo">
  <img src="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?>" class="brand-logo__img" width="<?= $px ?>" height="<?= $px ?>" decoding="async">
</div>
<?php else: ?>
<span class="logo brand-logo">
  <img src="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?>" class="brand-logo__img" width="<?= $px ?>" height="<?= $px ?>" decoding="async">
</span>
<?php endif; ?>
