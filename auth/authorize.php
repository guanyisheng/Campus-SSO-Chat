<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/sso_consent.php';

app_session_start();

$base = rtrim(SITE_URL, '/');
$user = current_user();

if (!$user) {
    header('Location: ' . $base . '/login.php');
    exit;
}

if (!sso_consent_pending()) {
    header('Location: ' . $base . '/chat.php');
    exit;
}

$rows = sso_consent_permission_rows($user);
$identityLabel = (string) ($user['display_name'] ?? $user['campus_uid'] ?? '当前用户');
$page_title = '授权确认';

require dirname(__DIR__) . '/includes/ui_head.php';
?>

<div class="consent-page">
  <div class="consent-card consent-card--premium">
    <div class="consent-hero">
      <div class="consent-hero__apps" aria-hidden="true">
        <span class="consent-hero__app consent-hero__app--idp">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
        </span>
        <span class="consent-hero__arrow">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M5 12h14M13 6l6 6-6 6"/>
          </svg>
        </span>
        <span class="consent-hero__app consent-hero__app--client">
          <?php $brand_logo_variant = 'auth'; require dirname(__DIR__) . '/includes/brand_logo.php'; ?>
        </span>
      </div>
      <p class="consent-hero__identity">以 <strong><?= htmlspecialchars($identityLabel, ENT_QUOTES, 'UTF-8') ?></strong> 的身份继续</p>
    </div>

    <h1 class="consent-card__title"><?= htmlspecialchars(SSO_CONSENT_APP_LABEL, ENT_QUOTES, 'UTF-8') ?>申请获得以下权限</h1>
    <p class="consent-card__desc">统一认证信息确认。同意后进入对话；拒绝将退出登录。</p>

    <ul class="consent-scopes" aria-label="申请权限列表">
      <?php foreach ($rows as $row): ?>
      <li class="consent-scopes__item">
        <span class="consent-scopes__check" aria-hidden="true">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
        </span>
        <span class="consent-scopes__body">
          <span class="consent-scopes__label"><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="consent-scopes__value"><?= htmlspecialchars($row['value'], ENT_QUOTES, 'UTF-8') ?></span>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>

    <form class="consent-actions" method="post" action="<?= htmlspecialchars($base . '/auth/authorize_action.php', ENT_QUOTES) ?>">
      <input type="hidden" name="action" value="accept">
      <button type="submit" class="c-btn c-btn--primary c-btn--lg c-btn--block">同意授权</button>
    </form>

    <form class="consent-actions consent-actions--decline" method="post" action="<?= htmlspecialchars($base . '/auth/authorize_action.php', ENT_QUOTES) ?>">
      <input type="hidden" name="action" value="decline">
      <button type="submit" class="consent-decline-btn">不同意</button>
    </form>

    <p class="consent-footnote">本步骤仅用于登录确认展示，不会单独存储或对外共享上述信息。</p>
  </div>
</div>

<?php require dirname(__DIR__) . '/includes/ui_foot.php'; ?>
