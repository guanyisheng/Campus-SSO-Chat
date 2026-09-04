<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/agents.php';
require_once dirname(__DIR__) . '/lib/models.php';
require_once dirname(__DIR__) . '/lib/user.php';
require_once dirname(__DIR__) . '/lib/user_groups.php';

require_admin();
agents_fix_schema();

$base = site_base_url();
$presets = agent_presets_list_all(false);
$chatModels = models_list_enabled_by_type('chat');
$users = users_list_for_admin(300);
$saved = isset($_GET['saved']);
$assigned = isset($_GET['assign']);
$error = (string) ($_GET['error'] ?? '');
$warn = (string) ($_GET['warn'] ?? '');
$editId = (int) ($_GET['edit'] ?? 0);
$editPreset = $editId > 0 ? agent_preset_get($editId) : null;
$viewAssignId = (int) ($_GET['assign_preset'] ?? 0);

$page_title = '智能体管理';
$ui_css = 'admin';
$admin_active = 'agents';
require dirname(__DIR__) . '/includes/ui_head.php';
require dirname(__DIR__) . '/includes/admin_shell.php';
?>

  <p class="admin-page__subtitle">
    管理员可创建无限预设智能体并分发给用户；用户在前台最多自建 <?= (int) USER_AGENT_MAX_COUNT ?> 个智能体。
  </p>

  <?php if ($saved): ?>
    <div class="alert alert-success"><?= $assigned ? '已分发给用户' : '已保存' ?><?= $warn !== '' ? '（头像未保存：' . htmlspecialchars($warn, ENT_QUOTES, 'UTF-8') . '）' : '' ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <section style="margin-bottom:1.5rem;padding:1.25rem;border:1px solid var(--border-subtle);border-radius:var(--radius-lg);background:var(--bg-elevated);">
    <h2 style="margin:0 0 1rem;font-size:1rem;"><?= $editPreset ? '编辑预设智能体' : '添加预设智能体' ?></h2>
    <form method="post" action="<?= htmlspecialchars($base . '/admin/agent_save.php', ENT_QUOTES) ?>" enctype="multipart/form-data" class="form-section">
      <input type="hidden" name="action" value="<?= $editPreset ? 'update_preset' : 'create_preset' ?>">
      <?php if ($editPreset): ?>
        <input type="hidden" name="id" value="<?= (int) $editPreset['id'] ?>">
      <?php endif; ?>
      <div class="c-field">
        <label class="c-label">智能体名称</label>
        <input class="c-input" type="text" name="display_name" required maxlength="64" value="<?= htmlspecialchars((string) ($editPreset['display_name'] ?? ''), ENT_QUOTES) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">简介（可选）</label>
        <input class="c-input" type="text" name="description" maxlength="512" value="<?= htmlspecialchars((string) ($editPreset['description'] ?? ''), ENT_QUOTES) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">头像图片</label>
        <input class="c-input" type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
        <?php if (!empty($editPreset['avatar_file'])): ?>
          <p class="c-help" style="margin-top:0.35rem;">
            当前：<img src="<?= htmlspecialchars(agent_avatar_public_url((string) $editPreset['avatar_file']), ENT_QUOTES) ?>" alt="" style="width:40px;height:40px;border-radius:8px;object-fit:cover;vertical-align:middle;">
          </p>
        <?php endif; ?>
      </div>
      <div class="c-field">
        <label class="c-label">系统提示词</label>
        <textarea class="c-input" name="system_prompt" rows="6" required placeholder="定义智能体角色、语气与能力边界…"><?= htmlspecialchars((string) ($editPreset['system_prompt'] ?? ''), ENT_QUOTES) ?></textarea>
      </div>
      <div class="c-field">
        <label class="c-label">默认模型</label>
        <select class="c-input" name="model_id">
          <option value="0">（跟随用户选择）</option>
          <?php foreach ($chatModels as $m): ?>
            <option value="<?= (int) $m['id'] ?>"<?= (int) ($editPreset['model_id'] ?? 0) === (int) $m['id'] ? ' selected' : '' ?>>
              <?= htmlspecialchars((string) ($m['display_name'] ?? $m['model_name']), ENT_QUOTES) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="c-field">
        <label class="c-label">排序</label>
        <input class="c-input" type="number" name="sort_order" value="<?= (int) ($editPreset['sort_order'] ?? 0) ?>">
      </div>
      <div class="c-field">
        <label class="c-label"><input type="checkbox" name="is_enabled" value="1"<?= !$editPreset || (int) ($editPreset['is_enabled'] ?? 1) ? ' checked' : '' ?>> 启用</label>
      </div>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <button type="submit" class="c-btn c-btn--primary"><?= $editPreset ? '保存修改' : '添加预设' ?></button>
        <?php if ($editPreset): ?>
          <a href="<?= htmlspecialchars($base . '/admin/agents.php', ENT_QUOTES) ?>" class="c-btn c-btn--ghost">取消</a>
        <?php endif; ?>
      </div>
    </form>
  </section>

  <section style="margin-bottom:1.5rem;padding:1.25rem;border:1px solid var(--border-subtle);border-radius:var(--radius-lg);background:var(--bg-elevated);">
    <h2 style="margin:0 0 1rem;font-size:1rem;">预设列表</h2>
    <?php if ($presets === []): ?>
      <p class="muted">暂无预设，请在上方添加。</p>
    <?php else: ?>
      <table class="admin-table">
        <thead>
          <tr>
            <th>智能体</th>
            <th>模型</th>
            <th>状态</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($presets as $p): ?>
          <?php
            $modelLabel = '跟随用户';
            if (!empty($p['model_id'])) {
                foreach ($chatModels as $m) {
                    if ((int) $m['id'] === (int) $p['model_id']) {
                        $modelLabel = (string) ($m['display_name'] ?? $m['model_name']);
                        break;
                    }
                }
            }
            $assignCount = count(user_agent_assignments_for_preset((int) $p['id']));
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:0.65rem;">
                <?php if (!empty($p['avatar_file'])): ?>
                  <img src="<?= htmlspecialchars(agent_avatar_public_url((string) $p['avatar_file']), ENT_QUOTES) ?>" alt="" style="width:36px;height:36px;border-radius:8px;object-fit:cover;">
                <?php else: ?>
                  <span class="c-avatar" style="width:36px;height:36px;font-size:0.75rem;">AI</span>
                <?php endif; ?>
                <div>
                  <div><?= htmlspecialchars((string) $p['display_name'], ENT_QUOTES) ?></div>
                  <?php if (!empty($p['description'])): ?>
                    <div class="muted" style="font-size:0.75rem;"><?= htmlspecialchars((string) $p['description'], ENT_QUOTES) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td style="font-size:0.8125rem;"><?= htmlspecialchars($modelLabel, ENT_QUOTES) ?></td>
            <td>
              <?= (int) ($p['is_enabled'] ?? 0) ? '启用' : '停用' ?>
              · 已分发 <?= (int) $assignCount ?> 人
            </td>
            <td>
              <div style="display:flex;gap:0.35rem;flex-wrap:wrap;">
                <a class="c-btn c-btn--ghost c-btn--sm" href="<?= htmlspecialchars($base . '/admin/agents.php?edit=' . (int) $p['id'], ENT_QUOTES) ?>">编辑</a>
                <a class="c-btn c-btn--ghost c-btn--sm" href="<?= htmlspecialchars($base . '/admin/agents.php?assign_preset=' . (int) $p['id'], ENT_QUOTES) ?>">分发</a>
                <form method="post" action="<?= htmlspecialchars($base . '/admin/agent_save.php', ENT_QUOTES) ?>" onsubmit="return confirm('确定删除该预设？');">
                  <input type="hidden" name="action" value="delete_preset">
                  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <button type="submit" class="c-btn c-btn--ghost c-btn--sm">删除</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <?php if ($viewAssignId > 0 && ($assignPreset = agent_preset_get($viewAssignId))): ?>
  <section style="padding:1.25rem;border:1px solid var(--border-subtle);border-radius:var(--radius-lg);background:var(--bg-elevated);">
    <h2 style="margin:0 0 0.5rem;font-size:1rem;">分发「<?= htmlspecialchars((string) $assignPreset['display_name'], ENT_QUOTES) ?>」</h2>
    <p class="muted" style="margin:0 0 1rem;font-size:0.8125rem;">将预设智能体分发给指定用户，用户可在前台直接使用（不计入其 <?= (int) USER_AGENT_MAX_COUNT ?> 个自建名额）。</p>

    <form method="post" action="<?= htmlspecialchars($base . '/admin/agent_save.php', ENT_QUOTES) ?>" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;margin-bottom:1rem;">
      <input type="hidden" name="action" value="assign_preset">
      <input type="hidden" name="preset_id" value="<?= (int) $viewAssignId ?>">
      <select class="c-input" name="user_id" required style="min-width:220px;">
        <option value="">选择用户…</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>">
            <?= htmlspecialchars((string) ($u['display_name'] ?? '') . ' · ' . ($u['campus_uid'] ?? ''), ENT_QUOTES) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="c-btn c-btn--primary c-btn--sm">分发给用户</button>
    </form>

    <?php $assignedUsers = user_agent_assignments_for_preset($viewAssignId); ?>
    <?php if ($assignedUsers !== []): ?>
      <table class="admin-table">
        <thead><tr><th>用户</th><th>分发时间</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($assignedUsers as $au): ?>
          <tr>
            <td><?= htmlspecialchars((string) ($au['display_name'] ?? ''), ENT_QUOTES) ?> <span class="muted">(<?= htmlspecialchars((string) ($au['campus_uid'] ?? ''), ENT_QUOTES) ?>)</span></td>
            <td style="font-size:0.8125rem;"><?= htmlspecialchars((string) ($au['assigned_at'] ?? ''), ENT_QUOTES) ?></td>
            <td>
              <form method="post" action="<?= htmlspecialchars($base . '/admin/agent_save.php', ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="unassign_preset">
                <input type="hidden" name="preset_id" value="<?= (int) $viewAssignId ?>">
                <input type="hidden" name="user_id" value="<?= (int) $au['id'] ?>">
                <button type="submit" class="c-btn c-btn--ghost c-btn--sm">取消分发</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="muted">尚未分发给任何用户。</p>
    <?php endif; ?>
  </section>
  <?php endif; ?>

<?php
require dirname(__DIR__) . '/includes/admin_shell_end.php';
require dirname(__DIR__) . '/includes/ui_foot.php';
