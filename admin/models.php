<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/models.php';

require_admin();

$base = site_base_url();
$type = model_normalize_type((string) ($_GET['type'] ?? 'chat'));
$models = models_list_all($type);
$saved = isset($_GET['saved']);
$addedCount = isset($_GET['added']) ? (int) $_GET['added'] : null;
$skippedCount = isset($_GET['skipped']) ? (int) $_GET['skipped'] : null;
$error = $_GET['error'] ?? '';
$editId = (int) ($_GET['edit'] ?? 0);
$editModel = $editId > 0 ? model_get($editId) : null;

$typeLabels = [
    'chat'  => '对话',
    'image' => '生图',
    'video' => '生视频',
];
$typeDefaults = [
    'chat'  => ['url' => 'http://127.0.0.1:11434/v1', 'model' => 'qwen2.5:7b', 'name' => '本地对话'],
    'image' => ['url' => 'http://127.0.0.1:8188', 'model' => 'comfyui', 'name' => 'ComfyUI SDXL（本地）'],
    'video' => ['url' => 'https://apihub.agnes-ai.com/v1', 'model' => 'agnes-video-v2.0', 'name' => 'Agnes 生视频'],
];
$defaults = $typeDefaults[$type] ?? $typeDefaults['chat'];

$page_title = 'API 管理';
$ui_css = 'admin';
$admin_active = 'apis';
require dirname(__DIR__) . '/includes/ui_head.php';
require dirname(__DIR__) . '/includes/admin_shell.php';
?>

  <nav class="admin-tabs" style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1.25rem;">
    <?php foreach ($typeLabels as $key => $label): ?>
      <a href="<?= htmlspecialchars($base . '/admin/models.php?type=' . $key, ENT_QUOTES) ?>"
         class="c-btn c-btn--ghost c-btn--sm<?= $type === $key ? ' is-active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?> API</a>
    <?php endforeach; ?>
  </nav>

  <?php if ($saved): ?>
    <div class="alert alert-success">
      已保存
      <?php if ($addedCount !== null): ?>
        · 新增 <?= (int) $addedCount ?> 个模型<?php if ($skippedCount): ?>，跳过 <?= (int) $skippedCount ?> 个已存在<?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>

  <section style="margin-bottom:1.5rem;padding:1.25rem;border:1px solid var(--border-subtle);border-radius:var(--radius-lg);background:var(--bg-elevated);">
    <h2 style="margin:0 0 1rem;font-size:1rem;"><?= $editModel ? '编辑' : '添加' ?><?= htmlspecialchars($typeLabels[$type] ?? 'API', ENT_QUOTES) ?></h2>
  <?php if ($type === 'chat'): ?>
      <p style="margin:0 0 1rem;font-size:0.8125rem;color:var(--text-tertiary);line-height:1.55;">
        此处配置的<strong>对话模型</strong>同时用于：主对话页、智能体、以及侧栏「教案生成」工具。修改 Base URL / Key / 模型名后，教案与对话会同步生效。
        全局参数（max_tokens、timeout 等）在<a href="<?= htmlspecialchars($base . '/admin/dashboard.php', ENT_QUOTES) ?>">系统设置</a>中调整。
      </p>
    <?php endif; ?>
    <?php if ($type !== 'chat'): ?>
      <p style="margin:0 0 1rem;font-size:0.8125rem;color:var(--text-tertiary);">
        可添加多条同类型 API（不同 Key），用户 1、2 共用 1 号 API，3、4 共用 2 号 API，每条线路独立排队。
        <?php if ($type === 'image'): ?>
        Agnes 文档：
          <a href="https://agnes-ai.com/doc/agnes-image-20-flash" target="_blank" rel="noopener">agnes-image-2.0-flash</a>
          · Base URL 通常为 <code>https://apihub.agnes-ai.com/v1</code>
          · ComfyUI 本地线路 model 填 <code>comfyui</code>；具体 checkpoint 在
          <a href="<?= htmlspecialchars($base . '/admin/image_models.php', ENT_QUOTES) ?>">生图模型</a> 管理
        <?php else: ?>
          <a href="https://agnes-ai.com/doc/agnes-video-v20" target="_blank" rel="noopener">agnes-video-v2.0</a>
          · 异步接口 <code>POST /videos</code> + <code>GET /videos/{id}</code>
        <?php endif; ?>
        · <a href="<?= htmlspecialchars($base . '/admin/media_queue.php', ENT_QUOTES) ?>">查看排队情况</a>
      </p>
    <?php endif; ?>
    <form method="post" action="<?= htmlspecialchars($base . '/admin/model_save.php', ENT_QUOTES) ?>" class="form-section" id="model-form">
      <input type="hidden" name="action" value="<?= $editModel ? 'update' : 'create' ?>">
      <input type="hidden" name="model_type" value="<?= htmlspecialchars($type, ENT_QUOTES) ?>">
      <?php if ($editModel): ?>
        <input type="hidden" name="id" value="<?= (int) $editModel['id'] ?>">
      <?php endif; ?>
      <div class="c-field"><label class="c-label">显示名称</label><input class="c-input" type="text" name="name" id="model-display-name" required value="<?= htmlspecialchars((string) ($editModel['display_name'] ?? $defaults['name']), ENT_QUOTES) ?>"></div>
      <div class="c-field"><label class="c-label">API Base URL</label><input class="c-input" type="url" name="base_url" id="model-base-url" required placeholder="<?= htmlspecialchars($defaults['url'], ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) ($editModel['base_url'] ?? ''), ENT_QUOTES) ?>"></div>
      <div class="c-field">
        <label class="c-label">API Key</label>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
          <input class="c-input" type="text" name="api_key" id="model-api-key" placeholder="Bearer Token（本地 Ollama 可留空）" value="<?= htmlspecialchars((string) ($editModel['api_key'] ?? ''), ENT_QUOTES) ?>" style="flex:1;min-width:200px;">
          <button type="button" class="c-btn c-btn--secondary" id="btn-fetch-models">获取模型列表</button>
        </div>
        <p class="c-help" style="margin-top:0.35rem;">填写 Base URL 与 Key 后，请求 <code>GET /models</code> 拉取可用模型，可勾选快速添加。</p>
      </div>
      <div class="c-field"><label class="c-label">模型名称 (model)</label><input class="c-input" type="text" name="model_name" id="model-name-input" required placeholder="<?= htmlspecialchars($defaults['model'], ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) ($editModel['model_name'] ?? ''), ENT_QUOTES) ?>"></div>
      <div class="c-field"><label class="c-label">排序（越小越靠前）</label><input class="c-input" type="number" name="sort_order" value="<?= (int) ($editModel['sort_order'] ?? 0) ?>"></div>
      <div style="display:flex;gap:0.5rem;">
        <button type="submit" class="c-btn c-btn--primary"><?= $editModel ? '保存修改' : '添加' ?></button>
        <?php if ($editModel): ?>
          <a href="<?= htmlspecialchars($base . '/admin/models.php?type=' . $type, ENT_QUOTES) ?>" class="c-btn c-btn--ghost">取消</a>
        <?php endif; ?>
      </div>
    </form>

    <div id="remote-models-panel" hidden style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--border-subtle);">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.75rem;">
        <h3 style="margin:0;font-size:0.9375rem;">远程模型列表</h3>
        <span id="remote-models-status" class="muted" style="font-size:0.8125rem;"></span>
      </div>
      <div id="remote-models-alert" class="alert" hidden style="margin-bottom:0.75rem;"></div>
      <form method="post" action="<?= htmlspecialchars($base . '/admin/model_save.php', ENT_QUOTES) ?>" id="bulk-model-form">
        <input type="hidden" name="action" value="bulk_create">
        <input type="hidden" name="model_type" value="<?= htmlspecialchars($type, ENT_QUOTES) ?>">
        <input type="hidden" name="base_url" id="bulk-base-url">
        <input type="hidden" name="api_key" id="bulk-api-key">
        <div id="remote-models-list" style="max-height:280px;overflow:auto;border:1px solid var(--border-subtle);border-radius:var(--radius-md);padding:0.5rem;"></div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.75rem;">
          <button type="button" class="c-btn c-btn--ghost c-btn--sm" id="btn-select-all-models">全选未添加</button>
          <button type="submit" class="c-btn c-btn--primary c-btn--sm" id="btn-bulk-add-models">快速添加选中</button>
        </div>
      </form>
    </div>
  </section>

<script>
(function () {
  var fetchUrl = <?= json_encode(site_asset_path('/api/admin/fetch_models.php'), JSON_UNESCAPED_UNICODE) ?>;
  var modelType = <?= json_encode($type, JSON_UNESCAPED_UNICODE) ?>;
  var btnFetch = document.getElementById('btn-fetch-models');
  var panel = document.getElementById('remote-models-panel');
  var listEl = document.getElementById('remote-models-list');
  var statusEl = document.getElementById('remote-models-status');
  var alertEl = document.getElementById('remote-models-alert');
  var bulkForm = document.getElementById('bulk-model-form');
  var bulkBase = document.getElementById('bulk-base-url');
  var bulkKey = document.getElementById('bulk-api-key');
  var baseInput = document.getElementById('model-base-url');
  var keyInput = document.getElementById('model-api-key');
  var nameInput = document.getElementById('model-name-input');
  var displayInput = document.getElementById('model-display-name');
  var btnSelectAll = document.getElementById('btn-select-all-models');

  if (!btnFetch || !panel) return;

  function showAlert(msg, isError) {
    if (!alertEl) return;
    alertEl.hidden = false;
    alertEl.textContent = msg;
    alertEl.className = 'alert ' + (isError ? 'alert-error' : 'alert-success');
  }

  function hideAlert() {
    if (alertEl) alertEl.hidden = true;
  }

  btnFetch.addEventListener('click', function () {
    var baseUrl = (baseInput && baseInput.value || '').trim();
    var apiKey = (keyInput && keyInput.value || '').trim();
    if (!baseUrl) {
      showAlert('请先填写 API Base URL', true);
      panel.hidden = false;
      return;
    }
    hideAlert();
    btnFetch.disabled = true;
    btnFetch.textContent = '获取中…';
    if (statusEl) statusEl.textContent = '正在请求…';

    fetch(fetchUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        base_url: baseUrl,
        api_key: apiKey,
        model_type: modelType
      })
    })
      .then(function (res) {
        return res.text().then(function (text) {
          var data;
          try {
            data = JSON.parse(text);
          } catch (parseErr) {
            var snippet = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
            throw new Error('服务器返回非 JSON（多为 PHP 报错）：' + (snippet || '空响应'));
          }
          if (!res.ok) throw new Error(data.error || '获取失败');
          return data;
        });
      })
      .then(function (data) {
        panel.hidden = false;
        if (bulkBase) bulkBase.value = baseUrl;
        if (bulkKey) bulkKey.value = apiKey;
        listEl.innerHTML = '';
        var models = data.models || [];
        if (!models.length) {
          showAlert('未获取到模型，请检查 Base URL 与 Key', true);
          if (statusEl) statusEl.textContent = '0 个模型';
          return;
        }
        hideAlert();
        if (statusEl) statusEl.textContent = '共 ' + models.length + ' 个';
        models.forEach(function (item) {
          var row = document.createElement('label');
          row.style.cssText = 'display:flex;align-items:center;gap:0.5rem;padding:0.35rem 0.5rem;border-radius:var(--radius-sm);cursor:pointer;';
          row.addEventListener('mouseenter', function () { row.style.background = 'var(--bg-hover)'; });
          row.addEventListener('mouseleave', function () { row.style.background = ''; });
          var cb = document.createElement('input');
          cb.type = 'checkbox';
          cb.name = 'model_names[]';
          cb.value = item.id;
          if (item.exists) {
            cb.disabled = true;
            cb.checked = false;
          }
          var span = document.createElement('span');
          span.className = 'mono small';
          span.textContent = item.id;
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
            if (nameInput) nameInput.value = item.id;
            if (displayInput && !displayInput.value.trim()) displayInput.value = item.id;
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
        btnFetch.textContent = '获取模型列表';
      });
  });

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
        showAlert('请至少勾选一个模型', true);
      }
    });
  }
})();
</script>

  <section style="padding:1.25rem;border:1px solid var(--border-subtle);border-radius:var(--radius-lg);background:var(--bg-elevated);">
    <h2>已配置 · <?= htmlspecialchars($typeLabels[$type] ?? $type, ENT_QUOTES) ?></h2>
    <?php if (count($models) === 0): ?>
      <p class="muted">暂无 API，请添加。</p>
    <?php else: ?>
    <table class="admin-table">
      <thead>
        <tr>
          <th>名称</th>
          <th>API</th>
          <th>模型</th>
          <th>状态</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($models as $m): ?>
        <tr>
          <td><?= htmlspecialchars((string) ($m['display_name'] ?? ''), ENT_QUOTES) ?></td>
          <td class="mono small"><?= htmlspecialchars((string) ($m['base_url'] ?? ''), ENT_QUOTES) ?></td>
          <td class="mono small"><?= htmlspecialchars((string) ($m['model_name'] ?? ''), ENT_QUOTES) ?></td>
          <td><?= $m['is_enabled'] ? '启用' : '停用' ?></td>
          <td style="white-space:nowrap;">
            <a href="<?= htmlspecialchars($base . '/admin/models.php?type=' . $type . '&edit=' . (int) $m['id'], ENT_QUOTES) ?>" class="c-btn c-btn--ghost c-btn--sm">编辑</a>
            <form method="post" action="<?= htmlspecialchars($base . '/admin/model_save.php', ENT_QUOTES) ?>" style="display:inline">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <input type="hidden" name="return_type" value="<?= htmlspecialchars($type, ENT_QUOTES) ?>">
              <button type="submit" class="c-btn c-btn--ghost c-btn--sm"><?= $m['is_enabled'] ? '停用' : '启用' ?></button>
            </form>
            <form method="post" action="<?= htmlspecialchars($base . '/admin/model_delete.php', ENT_QUOTES) ?>" style="display:inline"
                  onsubmit="return confirm('确定删除该 API？');">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <input type="hidden" name="return_type" value="<?= htmlspecialchars($type, ENT_QUOTES) ?>">
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
