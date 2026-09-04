<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/settings.php';
require_once dirname(__DIR__) . '/lib/user_groups.php';

require_admin();
user_groups_fix_schema();

$base = site_base_url();
$groups = user_groups_list();
$defaultGroupId = user_group_default_id();
$editId = (int) ($_GET['edit'] ?? 0);
$editGroup = $editId > 0 ? user_group_get($editId) : null;
$saved = isset($_GET['saved']);
$error = trim((string) ($_GET['error'] ?? ''));

$page_title = '用户组';
$ui_css = 'admin';
$admin_active = 'groups';
require dirname(__DIR__) . '/includes/ui_head.php';
require dirname(__DIR__) . '/includes/admin_shell.php';
?>

  <p class="admin-page__subtitle">
    不同用户组可设置独立的每日对话 / 生图 / 生视频额度（0 表示不限）。勾选「显示管理入口」的用户登录前台后，侧栏底部会出现管理链接。
  </p>

  <?php if ($saved): ?>
    <div class="alert alert-success">已保存</div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <section style="margin-bottom:1.5rem;padding:1.25rem;border:1px solid var(--border-subtle);border-radius:var(--radius-lg);background:var(--bg-elevated);">
    <h2 style="margin:0 0 1rem;font-size:1rem;">注册默认用户组</h2>
    <form method="post" action="<?= htmlspecialchars($base . '/admin/group_save.php', ENT_QUOTES) ?>">
      <input type="hidden" name="action" value="default">
      <div class="c-field">
        <label class="c-label">新用户默认归属</label>
        <select class="c-input" name="default_user_group_id">
          <?php foreach ($groups as $g): ?>
          <option value="<?= (int) $g['id'] ?>"<?= (int) $g['id'] === $defaultGroupId ? ' selected' : '' ?>>
            <?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="c-btn c-btn--primary c-btn--sm">保存默认组</button>
    </form>
  </section>

  <section style="margin-bottom:1.5rem;padding:1.25rem;border:1px solid var(--border-subtle);border-radius:var(--radius-lg);background:var(--bg-elevated);">
    <h2 style="margin:0 0 1rem;font-size:1rem;"><?= $editGroup ? '编辑用户组' : '添加用户组' ?></h2>
    <form method="post" action="<?= htmlspecialchars($base . '/admin/group_save.php', ENT_QUOTES) ?>" class="form-section">
      <input type="hidden" name="action" value="save">
      <?php if ($editGroup): ?>
      <input type="hidden" name="id" value="<?= (int) $editGroup['id'] ?>">
      <?php endif; ?>
      <div class="c-field">
        <label class="c-label">组名称</label>
        <input class="c-input" type="text" name="name" required maxlength="64" value="<?= htmlspecialchars((string) ($editGroup['name'] ?? ''), ENT_QUOTES) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">标识 slug（英文，唯一）</label>
        <input class="c-input" type="text" name="slug" required maxlength="32" pattern="[a-z0-9_\-]+" value="<?= htmlspecialchars((string) ($editGroup['slug'] ?? ''), ENT_QUOTES) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">每日对话轮数（0=不限）</label>
        <input class="c-input" type="number" name="daily_chat_limit" min="0" max="100000" value="<?= (int) ($editGroup['daily_chat_limit'] ?? 100) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">每日生图次数（0=不限）</label>
        <input class="c-input" type="number" name="daily_image_limit" min="0" max="10000" value="<?= (int) ($editGroup['daily_image_limit'] ?? 20) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">每日生视频次数（0=不限）</label>
        <input class="c-input" type="number" name="daily_video_limit" min="0" max="5000" value="<?= (int) ($editGroup['daily_video_limit'] ?? 10) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">排序</label>
        <input class="c-input" type="number" name="sort_order" value="<?= (int) ($editGroup['sort_order'] ?? 0) ?>">
      </div>
      <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1rem;">
        <input type="checkbox" name="can_access_admin" value="1" <?= !empty($editGroup['can_access_admin']) ? 'checked' : '' ?>>
        <span>前台显示「管理入口」（须同时能登录 /admin/）</span>
      </label>
      <div style="display:flex;gap:0.5rem;">
        <button type="submit" class="c-btn c-btn--primary c-btn--sm">保存</button>
        <?php if ($editGroup): ?>
        <a href="<?= htmlspecialchars($base . '/admin/groups.php', ENT_QUOTES) ?>" class="c-btn c-btn--ghost c-btn--sm">取消</a>
        <?php endif; ?>
      </div>
    </form>
  </section>

  <section style="padding:1.25rem;border:1px solid var(--border-subtle);border-radius:var(--radius-lg);background:var(--bg-elevated);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
      <h2 style="margin:0;font-size:1rem;">用户组列表</h2>
      <a href="<?= htmlspecialchars($base . '/admin/users.php', ENT_QUOTES) ?>" class="c-btn c-btn--ghost c-btn--sm">管理用户归属 →</a>
    </div>
    <table class="admin-table">
      <thead>
        <tr>
          <th>名称</th>
          <th>对话/生图/视频</th>
          <th>用户</th>
          <th>管理入口</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($groups as $g): ?>
        <tr>
          <td>
            <?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ((int) $g['id'] === $defaultGroupId): ?>
            <span style="font-size:0.75rem;color:var(--text-tertiary);">（默认注册）</span>
            <?php endif; ?>
          </td>
          <td><?= (int) $g['daily_chat_limit'] ?> / <?= (int) $g['daily_image_limit'] ?> / <?= (int) $g['daily_video_limit'] ?></td>
          <td><?= (int) $g['user_count'] ?></td>
          <td><?= !empty($g['can_access_admin']) ? '是' : '—' ?></td>
          <td style="white-space:nowrap;">
            <a href="<?= htmlspecialchars($base . '/admin/groups.php?edit=' . (int) $g['id'], ENT_QUOTES) ?>" class="c-btn c-btn--ghost c-btn--sm">编辑</a>
            <?php if ((int) $g['user_count'] === 0 && (int) $g['id'] !== $defaultGroupId): ?>
            <form method="post" action="<?= htmlspecialchars($base . '/admin/group_save.php', ENT_QUOTES) ?>" style="display:inline" onsubmit="return confirm('确定删除该用户组？');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
              <button type="submit" class="c-btn c-btn--ghost c-btn--sm" style="color:#f87171;">删除</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

<?php
require dirname(__DIR__) . '/includes/admin_shell_end.php';
require dirname(__DIR__) . '/includes/ui_foot.php';
