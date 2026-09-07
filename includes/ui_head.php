<?php
declare(strict_types=1);
if (!defined('SITE_URL')) {
    require_once dirname(__DIR__) . '/config.php';
}
if (!function_exists('site_base_url')) {
    require_once dirname(__DIR__) . '/lib/site.php';
}

$ui_title = $page_title ?? APP_NAME;
$ui_base = site_base_url();
$ui_asset = static fn(string $p): string => site_asset_path($p);
$ui_css = $ui_css ?? 'client';
$ui_body_class = $ui_body_class ?? '';
$ui_chat_aurora = !empty($ui_chat_aurora);
$ui_body_classes = trim(implode(' ', array_filter([
    $ui_body_class,
    $ui_chat_aurora ? 'has-chat-aurora' : '',
])));
$ui_viewport_lock = !empty($ui_viewport_lock);
$ui_viewport_content = 'width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content';
if ($ui_viewport_lock) {
    $ui_viewport_content .= ', maximum-scale=1.0, user-scalable=no';
}
$ui_extra_css = $ui_extra_css ?? [];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <script>
    (function () {
      try {
        var t = localStorage.getItem('app-theme');
        document.documentElement.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');
      } catch (e) {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
    })();
  </script>
  <meta charset="UTF-8">
  <meta name="viewport" content="<?= htmlspecialchars($ui_viewport_content, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="theme-color" id="meta-theme-color" content="#212121">
  <meta name="color-scheme" content="dark light">
  <title><?= htmlspecialchars($ui_title, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="icon" href="<?= htmlspecialchars($ui_asset('/favicon.ico'), ENT_QUOTES) ?>" type="image/x-icon">
  <link rel="stylesheet" href="<?= htmlspecialchars($ui_asset('/assets/ui/css/tokens.css'), ENT_QUOTES) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($ui_asset('/assets/ui/css/base.css'), ENT_QUOTES) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($ui_asset('/assets/ui/css/components.css'), ENT_QUOTES) ?>">
  <?php if ($ui_css === 'admin'): ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($ui_asset('/assets/ui/css/admin.css'), ENT_QUOTES) ?>">
  <?php else: ?>
  <?php
  $_clientCssPath = dirname(__DIR__) . '/assets/ui/css/client.css';
  $_clientCssVer = (string) (int) @filemtime($_clientCssPath);
  if (!empty($ui_chat_asset_ver)) {
      $_clientCssVer = (string) $ui_chat_asset_ver;
  }
  ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($ui_asset('/assets/ui/css/client.css?v=' . $_clientCssVer), ENT_QUOTES) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($ui_asset('/assets/ui/css/bridge.css'), ENT_QUOTES) ?>">
  <?php if ($ui_chat_aurora): ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($ui_asset('/assets/ui/css/aurora.css'), ENT_QUOTES) ?>">
  <?php endif; ?>
  <?php foreach ($ui_extra_css as $css): ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($ui_asset($css), ENT_QUOTES) ?>">
  <?php endforeach; ?>
  <?php
  $ui_use_csrf = !empty($ui_csrf) || (($ui_css ?? '') === 'admin');
  require_once dirname(__DIR__) . '/lib/security.php';
  if ($ui_use_csrf) {
      require_once dirname(__DIR__) . '/lib/csrf.php';
      security_send_html_headers();
      echo csrf_meta_tag(), "\n";
  } elseif (empty($ui_skip_security_headers)) {
      security_send_html_headers();
  }
  ?>
</head>
<body<?= $ui_body_classes !== '' ? ' class="' . htmlspecialchars($ui_body_classes, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
