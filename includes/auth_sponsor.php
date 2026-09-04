<?php
declare(strict_types=1);
if (!defined('SITE_URL')) {
    require_once dirname(__DIR__) . '/config.php';
}
$base = rtrim(SITE_URL, '/');
$sponsorLogoMain = $base . '/logo.webp';
$sponsorLogoPartner = $base . '/' . rawurlencode('透明ai.png');
?>
<div class="auth-sponsor" aria-label="服务商信息">
  <div class="auth-sponsor__logos">
    <div class="auth-sponsor__item">
      <img src="<?= htmlspecialchars($sponsorLogoMain, ENT_QUOTES, 'UTF-8') ?>" alt="提供商" class="auth-sponsor__logo auth-sponsor__logo--main" width="220" height="72" loading="lazy" decoding="async">
      <p class="auth-sponsor__caption">提供商</p>
    </div>
    <div class="auth-sponsor__item">
      <img src="<?= htmlspecialchars($sponsorLogoPartner, ENT_QUOTES, 'UTF-8') ?>" alt="路南云" class="auth-sponsor__logo auth-sponsor__logo--partner" width="140" height="56" loading="lazy" decoding="async">
      <p class="auth-sponsor__caption">技术支持</p>
    </div>
  </div>
  <p class="auth-sponsor__credit">技术支持路南云(24级计应一班管乙聲)</p>
</div>
