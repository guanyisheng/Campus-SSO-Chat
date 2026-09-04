<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/image_models.php';
require_once dirname(__DIR__) . '/lib/comfyui.php';

require_admin();
image_models_ensure_ready();

$base = site_base_url();
$comfyBase = comfyui_resolve_admin_base_url();
$models = image_models_list_all();
$saved = isset($_GET['saved']);
$addedCount = isset($_GET['added']) ? (int) $_GET['added'] : null;
$skippedCount = isset($_GET['skipped']) ? (int) $_GET['skipped'] : null;
$error = (string) ($_GET['error'] ?? '');
$editId = (int) ($_GET['edit'] ?? 0);
$editModel = $editId > 0 ? image_models_get($editId) : null;

$page_title = '生图模型';
$ui_css = 'admin';
$admin_active = 'image_models';
require dirname(__DIR__) . '/includes/ui_head.php';
require dirname(__DIR__) . '/includes/admin_shell.php';
?>

  <?php if ($saved): ?>
    <div class="alert alert-success">
      已保存
      <?php if ($addedCount !== null): ?>
        · 新增 <?= (int) $addedCount ?> 个模型<?php if ($skippedCount): ?>，跳过 <?= (int) $skippedCount ?> 个已存在<?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>

  <section style="margin-bottom:1.5rem;padding:1.25rem;border:1px solid var(--border-subtle);border-radius:var(--radius-lg);background:var(--bg-elevated);">
    <h2 style="margin:0 0 0.75rem;font-size:1rem;">ComfyUI Checkpoint</h2>
    <p style="margin:0 0 1rem;font-size:0.8125rem;color:var(--text-tertiary);line-height:1.55;">
      管理前台生图模式下拉可选的 checkpoint。API 线路仍在
      <a href="<?= htmlspecialchars($base . '/admin/models.php?type=image', ENT_QUOTES) ?>">生图 API</a>
      中配置 ComfyUI 地址；此处负责具体模型文件（类似 Ollama 模型列表）。
      当前 ComfyUI：<code><?= htmlspecialchars($comfyBase, ENT_QUOTES) ?></code>
    </p>

    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem;">
      <button type="button" class="c-btn c-btn--secondary" id="btn-test-comfyui">检测 ComfyUI</button>
      <button type="button" class="c-btn c-btn--secondary" id="btn-fetch-checkpoints">获取 checkpoint 列表</button>
    </div>
    <div id="comfyui-status" class="muted" style="font-size:0.8125rem;margin-bottom:0.75rem;"></div>
    <div id="comfyui-alert" class="alert" hidden style="margin-bottom:0.75rem;"></div>

    <div id="remote-checkpoints-panel" hidden style="margin-bottom:1.25rem;padding-top:1rem;border-top:1px solid var(--border-subtle);">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.75rem;">
        <h3 style="margin:0;font-size:0.9375rem;">ComfyUI 可用 checkpoint</h3>
        <span id="remote-checkpoints-count" class="muted" style="font-size:0.8125rem;"></span>
      </div>
      <form method="post" action="<?= htmlspecialchars($base . '/admin/image_model_save.php', ENT_QUOTES) ?>" id="bulk-checkpoint-form">
        <input type="hidden" name="action" value="bulk_create">
        <div id="remote-checkpoints-list" style="max-height:280px;overflow:auto;border:1px solid var(--border-subtle);border-radius:var(--radius-md);padding:0.5rem;"></div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.75rem;">
          <button type="button" class="c-btn c-btn--ghost c-btn--sm" id="btn-select-all-checkpoints">全选未添加</button>
          <button type="submit" class="c-btn c-btn--primary c-btn--sm">快速添加选中</button>
        </div>
      </form>
    </div>

    <h3 style="margin:0 0 1rem;font-size:0.9375rem;"><?= $editModel ? '编辑模型' : '手动添加' ?></h3>
    <form method="post" action="<?= htmlspecialchars($base . '/admin/image_model_save.php', ENT_QUOTES) ?>" class="form-section" id="checkpoint-form">
      <input type="hidden" name="action" value="<?= $editModel ? 'update' : 'create' ?>">
      <?php if ($editModel): ?>
        <input type="hidden" name="id" value="<?= (int) $editModel['id'] ?>">
      <?php endif; ?>
      <?php if (!$editModel): ?>
      <div class="c-field">
        <label class="c-label">model_key（API 参数，小写+下划线）</label>
        <input class="c-input mono" type="text" name="model_key" id="field-model-key" placeholder="留空则根据 checkpoint 自动生成" pattern="[a-z0-9_]*">
      </div>
      <?php endif; ?>
      <div class="c-field">
        <label class="c-label">显示名称</label>
        <input class="c-input" type="text" name="display_name" id="field-display-name" required value="<?= htmlspecialchars((string) ($editModel['display_name'] ?? ''), ENT_QUOTES) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">checkpoint 文件名</label>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
          <input class="c-input mono" type="text" name="checkpoint" id="field-checkpoint" required placeholder="xxx.safetensors" value="<?= htmlspecialchars((string) ($editModel['checkpoint'] ?? ''), ENT_QUOTES) ?>" style="flex:1;min-width:220px;">
          <button type="button" class="c-btn c-btn--ghost c-btn--sm" id="btn-test-checkpoint">检测此模型</button>
        </div>
      </div>
      <div class="c-field">
        <label class="c-label">输出前缀（ComfyUI filename_prefix）</label>
        <input class="c-input mono" type="text" name="output_prefix" id="field-output-prefix" placeholder="如 Pony_API" value="<?= htmlspecialchars((string) ($editModel['output_prefix'] ?? ''), ENT_QUOTES) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">排序（越小越靠前）</label>
        <input class="c-input" type="number" name="sort_order" value="<?= (int) ($editModel['sort_order'] ?? 0) ?>">
      </div>
      <?php if (!$editModel): ?>
      <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1rem;font-size:0.875rem;">
        <input type="checkbox" name="is_default" value="1">
        <span>设为默认模型</span>
      </label>
      <?php endif; ?>
      <div style="display:flex;gap:0.5rem;">
        <button type="submit" class="c-btn c-btn--primary"><?= $editModel ? '保存修改' : '添加' ?></button>
        <?php if ($editModel): ?>
          <a href="<?= htmlspecialchars($base . '/admin/image_models.php', ENT_QUOTES) ?>" class="c-btn c-btn--ghost">取消</a>
        <?php endif; ?>
      </div>
    </form>
  </section>

<script>
(function () {
  var fetchUrl = <?= json_encode(site_asset_path('/api/admin/fetch_comfyui_checkpoints.php'), JSON_UNESCAPED_UNICODE) ?>;
  var testUrl = <?= json_encode(site_asset_path('/api/admin/test_comfyui_checkpoint.php'), JSON_UNESCAPED_UNICODE) ?>;
  var comfyBase = <?= json_encode($comfyBase, JSON_UNESCAPED_UNICODE) ?>;

  var btnFetch = document.getElementById('btn-fetch-checkpoints');
  var btnTestComfy = document.getElementById('btn-test-comfyui');
  var btnTestCheckpoint = document.getElementById('btn-test-checkpoint');
  var panel = document.getElementById('remote-checkpoints-panel');
  var listEl = document.getElementById('remote-checkpoints-list');
  var countEl = document.getElementById('remote-checkpoints-count');
  var statusEl = document.getElementById('comfyui-status');
  var alertEl = document.getElementById('comfyui-alert');
  var bulkForm = document.getElementById('bulk-checkpoint-form');
  var btnSelectAll = document.getElementById('btn-select-all-checkpoints');
  var fieldCheckpoint = document.getElementById('field-checkpoint');
  var fieldDisplayName = document.getElementById('field-display-name');
  var fieldModelKey = document.getElementById('field-model-key');

  function showAlert(msg, isError) {
    if (!alertEl) return;
    alertEl.hidden = false;
    alertEl.textContent = msg;
    alertEl.className = 'alert ' + (isError ? 'alert-error' : 'alert-success');
  }

  function hideAlert() {
    if (!alertEl) return;
    alertEl.hidden = true;
    alertEl.textContent = '';
  }

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body || {})
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) throw new Error(data.error || '请求失败');
        return data;
      });
    });
  }

  if (btnTestComfy) {
    btnTestComfy.addEventListener('click', function () {
      btnTestComfy.disabled = true;
      if (statusEl) statusEl.textContent = '检测中…';
      hideAlert();
      postJson(testUrl, { base_url: comfyBase })
        .then(function (data) {
          showAlert(data.message || 'ComfyUI 连接正常', false);
          if (statusEl) statusEl.textContent = 'ComfyUI 正常 · ' + (data.result && data.result.checkpoint_count != null ? data.result.checkpoint_count + ' 个 checkpoint' : '');
        })
        .catch(function (err) {
          showAlert(err.message || '检测失败', true);
          if (statusEl) statusEl.textContent = '';
        })
        .finally(function () {
          btnTestComfy.disabled = false;
        });
    });
  }

  if (btnTestCheckpoint) {
    btnTestCheckpoint.addEventListener('click', function () {
      var ckpt = fieldCheckpoint ? fieldCheckpoint.value.trim() : '';
      if (!ckpt) {
        showAlert('请先填写 checkpoint 文件名', true);
        return;
      }
      btnTestCheckpoint.disabled = true;
      postJson(testUrl, { base_url: comfyBase, checkpoint: ckpt })
        .then(function (data) {
          showAlert(data.message || (data.available ? '已找到' : '未找到'), !data.available);
        })
        .catch(function (err) {
          showAlert(err.message || '检测失败', true);
        })
        .finally(function () {
          btnTestCheckpoint.disabled = false;
        });
    });
  }

  if (btnFetch) {
    btnFetch.addEventListener('click', function () {
      btnFetch.disabled = true;
      btnFetch.textContent = '获取中…';
      hideAlert();
      if (statusEl) statusEl.textContent = '正在连接 ComfyUI…';
      postJson(fetchUrl, { base_url: comfyBase })
        .then(function (data) {
          var items = data.checkpoints || [];
          panel.hidden = false;
          listEl.innerHTML = '';
          if (countEl) countEl.textContent = '共 ' + items.length + ' 个';
          if (statusEl) statusEl.textContent = 'ComfyUI: ' + (data.base_url || comfyBase);
          if (!items.length) {
            showAlert('未获取到 checkpoint', true);
            return;
          }
          hideAlert();
          items.forEach(function (item) {
            var row = document.createElement('label');
            row.style.cssText = 'display:flex;align-items:center;gap:0.5rem;padding:0.35rem 0.5rem;border-radius:var(--radius-sm);cursor:pointer;';
            row.addEventListener('mouseenter', function () { row.style.background = 'var(--bg-hover)'; });
            row.addEventListener('mouseleave', function () { row.style.background = ''; });
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'checkpoints[]';
            cb.value = item.checkpoint;
            if (item.exists) {
              cb.disabled = true;
            }
            var span = document.createElement('span');
            span.className = 'mono small';
            span.textContent = item.checkpoint;
            row.appendChild(cb);
            row.appendChild(span);
            if (item.exists) {
              var tag = document.createElement('span');
              tag.style.cssText = 'font-size:0.75rem;color:var(--text-tertiary);';
              tag.textContent = '已添加';
              row.appendChild(tag);
            }
            row.addEventListener('dblclick', function (e) {
              if (e.target === cb) return;
              if (fieldCheckpoint) fieldCheckpoint.value = item.checkpoint;
              if (fieldDisplayName && !fieldDisplayName.value.trim()) {
                fieldDisplayName.value = item.checkpoint.replace(/\.safetensors$/i, '');
              }
              if (fieldModelKey && !fieldModelKey.value.trim()) {
                fieldModelKey.value = item.model_key || '';
              }
            });
            listEl.appendChild(row);
          });
        })
        .catch(function (err) {
          panel.hidden = false;
          showAlert(err.message || '获取失败', true);
          if (statusEl) statusEl.textContent = '';
          listEl.innerHTML = '';
        })
        .finally(function () {
          btnFetch.disabled = false;
          btnFetch.textContent = '获取 checkpoint 列表';
        });
    });
  }

  if (btnSelectAll) {
    btnSelectAll.addEventListener('click', function () {
      listEl.querySelectorAll('input[type=checkbox]:not(:disabled)').forEach(function (cb) {
        cb.checked = true;
      });
    });
  }

  if (bulkForm) {
    bulkForm.addEventListener('submit', function (e) {
      var checked = listEl.querySelectorAll('input[type=checkbox]:checked');
      if (!checked.length) {
        e.preventDefault();
        showAlert('请至少勾选一个 checkpoint', true);
      }
    });
  }
})();
</script>

  <section style="padding:1.25rem;border:1px solid var(--border-subtle);border-radius:var(--radius-lg);background:var(--bg-elevated);">
    <h2 style="margin:0 0 1rem;font-size:1rem;">已配置 · 生图模型</h2>
    <?php if (count($models) === 0): ?>
      <p class="muted">暂无模型，请从 ComfyUI 获取或手动添加。</p>
    <?php else: ?>
    <table class="admin-table">
      <thead>
        <tr>
          <th>名称</th>
          <th>model_key</th>
          <th>checkpoint</th>
          <th>默认</th>
          <th>状态</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($models as $m): ?>
        <tr>
          <td><?= htmlspecialchars((string) $m['display_name'], ENT_QUOTES) ?></td>
          <td class="mono small"><?= htmlspecialchars((string) $m['model_key'], ENT_QUOTES) ?></td>
          <td class="mono small"><?= htmlspecialchars((string) $m['checkpoint'], ENT_QUOTES) ?></td>
          <td><?= (int) $m['is_default'] ? '★' : '—' ?></td>
          <td><?= (int) $m['is_enabled'] ? '启用' : '停用' ?></td>
          <td style="white-space:nowrap;">
            <a href="<?= htmlspecialchars($base . '/admin/image_models.php?edit=' . (int) $m['id'], ENT_QUOTES) ?>" class="c-btn c-btn--ghost c-btn--sm">编辑</a>
            <?php if (!(int) $m['is_default'] && (int) $m['is_enabled']): ?>
            <form method="post" action="<?= htmlspecialchars($base . '/admin/image_model_save.php', ENT_QUOTES) ?>" style="display:inline">
              <input type="hidden" name="action" value="set_default">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button type="submit" class="c-btn c-btn--ghost c-btn--sm">设默认</button>
            </form>
            <?php endif; ?>
            <form method="post" action="<?= htmlspecialchars($base . '/admin/image_model_save.php', ENT_QUOTES) ?>" style="display:inline">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button type="submit" class="c-btn c-btn--ghost c-btn--sm"><?= (int) $m['is_enabled'] ? '停用' : '启用' ?></button>
            </form>
            <form method="post" action="<?= htmlspecialchars($base . '/admin/image_model_save.php', ENT_QUOTES) ?>" style="display:inline"
                  onsubmit="return confirm('确定删除该生图模型？');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button type="submit" class="c-btn c-btn--ghost c-btn--sm" style="color:#f87171;">删除</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

<?php
require dirname(__DIR__) . '/includes/admin_shell_end.php';
require dirname(__DIR__) . '/includes/ui_foot.php';
