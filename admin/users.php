<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/user_groups.php';

require_admin();
user_groups_fix_schema();

$base = site_base_url();
$groups = user_groups_list();
$users = users_list_for_admin(300);
$saved = isset($_GET['saved']);

$page_title = '用户归属';
$ui_css = 'admin';
$admin_active = 'users';
require dirname(__DIR__) . '/includes/ui_head.php';
require dirname(__DIR__) . '/includes/admin_shell.php';
?>

  <p class="admin-page__subtitle">
    将用户分配到不同用户组以应用对应额度。未分配的用户按<a href="<?= htmlspecialchars($base . '/admin/groups.php', ENT_QUOTES) ?>">默认注册组</a>额度计费。
  </p>

  <?php if ($saved): ?>
    <div class="alert alert-success">已更新</div>
  <?php endif; ?>

  <section style="padding:1.25rem;border:1px solid var(--border-subtle);border-radius:var(--radius-lg);background:var(--bg-elevated);">
    <table class="admin-table">
      <thead>
        <tr>
          <th>用户</th>
          <th>邮箱/UID</th>
          <th>来源</th>
          <th>用户组</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><?= htmlspecialchars((string) ($u['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td style="font-size:0.8125rem;"><?= htmlspecialchars((string) ($u['campus_uid'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string) ($u['auth_source'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <form method="post" action="<?= htmlspecialchars($base . '/admin/user_save.php', ENT_QUOTES) ?>" style="display:flex;gap:0.5rem;align-items:center;">
              <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
              <select class="c-input" name="group_id" style="min-width:140px;">
                <option value="0"<?= empty($u['group_id']) ? ' selected' : '' ?>>（跟随默认组）</option>
                <?php foreach ($groups as $g): ?>
                <option value="<?= (int) $g['id'] ?>"<?= (int) ($u['group_id'] ?? 0) === (int) $g['id'] ? ' selected' : '' ?>>
                  <?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="c-btn c-btn--ghost c-btn--sm">保存</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

<?php
require dirname(__DIR__) . '/includes/admin_shell_end.php';
require dirname(__DIR__) . '/includes/ui_foot.php';
