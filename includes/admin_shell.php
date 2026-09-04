<?php
declare(strict_types=1);
/** @var string $admin_active dashboard|branding|models|apis|groups|users */
require_once dirname(__DIR__) . '/lib/site.php';

$base = site_base_url();
$admin_active = $admin_active ?? 'dashboard';
$admin_heading = $admin_heading ?? ($page_title ?? '管理后台');

$admin_nav = [
    ['id' => 'dashboard', 'label' => '系统设置', 'href' => $base . '/admin/dashboard.php', 'icon' => 'settings'],
    ['id' => 'apis', 'label' => 'API 管理', 'href' => $base . '/admin/models.php', 'icon' => 'cpu'],
    ['id' => 'image_models', 'label' => '生图模型', 'href' => $base . '/admin/image_models.php', 'icon' => 'image'],
    ['id' => 'agents', 'label' => '智能体', 'href' => $base . '/admin/agents.php', 'icon' => 'sparkles'],
    ['id' => 'media_queue', 'label' => '媒体排队', 'href' => $base . '/admin/media_queue.php', 'icon' => 'list'],
    ['id' => 'groups', 'label' => '用户组', 'href' => $base . '/admin/groups.php', 'icon' => 'users'],
    ['id' => 'users', 'label' => '用户归属', 'href' => $base . '/admin/users.php', 'icon' => 'user'],
];

function admin_nav_is_active(string $id, string $active): bool
{
    if ($id === $active) {
        return true;
    }
    if ($id === 'apis' && ($active === 'models' || $active === 'apis')) {
        return true;
    }
    if ($id === 'media_queue' && $active === 'media_queue') {
        return true;
    }

    return false;
}
?>
<div class="admin" id="admin-app">
  <div class="admin-backdrop" id="admin-backdrop" hidden aria-hidden="true"></div>

  <aside class="admin-sidebar" id="admin-sidebar" aria-label="管理菜单">
    <div class="admin-brand">
      <?php $brand_logo_variant = 'sidebar'; require __DIR__ . '/brand_logo.php'; ?>
      <div class="admin-brand__text">
        <div class="admin-brand__name"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="admin-brand__sub">管理后台</div>
      </div>
    </div>

    <nav class="admin-nav">
      <div class="admin-nav__group">
        <div class="admin-nav__title">管理</div>
        <?php foreach ($admin_nav as $item): ?>
        <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES) ?>"
           class="admin-nav__item<?= admin_nav_is_active($item['id'], $admin_active) ? ' is-active' : '' ?>">
          <span class="icon" data-icon="<?= htmlspecialchars($item['icon'], ENT_QUOTES) ?>" aria-hidden="true"></span>
          <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </nav>

    <div class="admin-sidebar-footer">
      <a href="<?= htmlspecialchars($base . '/chat.php', ENT_QUOTES) ?>" class="admin-nav__item">
        <span class="icon" data-icon="home" aria-hidden="true"></span>
        <span>返回前台</span>
      </a>
      <a href="<?= htmlspecialchars($base . '/admin/logout.php', ENT_QUOTES) ?>" class="admin-nav__item admin-nav__item--danger">
        <span class="icon" data-icon="log-out" aria-hidden="true"></span>
        <span>退出登录</span>
      </a>
    </div>
  </aside>

  <div class="admin-content">
    <header class="admin-topbar">
      <button type="button" class="c-icon-btn admin-topbar__menu" id="admin-sidebar-toggle" aria-label="打开菜单" aria-controls="admin-sidebar" aria-expanded="false">
        <span data-icon="menu"></span>
      </button>
      <div class="admin-topbar__main">
        <h1 class="admin-topbar__title"><?= htmlspecialchars($admin_heading, ENT_QUOTES, 'UTF-8') ?></h1>
      </div>
      <div class="admin-topbar__actions">
        <button type="button" class="c-icon-btn theme-toggle-btn theme-toggle-btn--compact" data-theme-toggle title="切换主题" aria-label="切换主题">
          <span data-icon="sun"></span>
        </button>
        <a href="<?= htmlspecialchars($base . '/chat.php', ENT_QUOTES) ?>" class="c-btn c-btn--ghost c-btn--sm u-hide-mobile">前台</a>
      </div>
    </header>

    <main class="admin-page">
