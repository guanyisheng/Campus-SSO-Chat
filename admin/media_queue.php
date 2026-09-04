<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/media_queue.php';

require_admin();

$base = site_base_url();
$summary = media_queue_admin_summary();
$jobs = media_queue_list_recent(80);

foreach ($summary['lanes'] as &$lane) {
    media_queue_kick((int) $lane['id']);
}
unset($lane);
$summary = media_queue_admin_summary();

$page_title = '媒体排队';
$ui_css = 'admin';
$admin_active = 'media_queue';
$admin_heading = '媒体排队';
require dirname(__DIR__) . '/includes/ui_head.php';
require dirname(__DIR__) . '/includes/admin_shell.php';

$statusLabels = [
    'queued'     => '排队中',
    'processing' => '处理中',
    'completed'  => '已完成',
    'failed'     => '失败',
];
?>

  <p class="admin-page__subtitle">
    生图 / 生视频按 API 线路独立排队，同一线路同时只处理 1 个任务。用户 1、2→1 号 API，3、4→2 号 API（按用户 ID 配对）。
    <a href="<?= htmlspecialchars($base . '/admin/models.php?type=image', ENT_QUOTES) ?>">配置生图 API</a>
    ·
    <a href="<?= htmlspecialchars($base . '/admin/models.php?type=video', ENT_QUOTES) ?>">配置生视频 API</a>
  </p>

  <section class="form-section" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:1.25rem;">
    <div class="c-card" style="padding:14px;">
      <div style="font-size:12px;color:var(--text-tertiary);">排队中</div>
      <div style="font-size:1.5rem;font-weight:600;"><?= (int) $summary['queued'] ?></div>
    </div>
    <div class="c-card" style="padding:14px;">
      <div style="font-size:12px;color:var(--text-tertiary);">处理中</div>
      <div style="font-size:1.5rem;font-weight:600;"><?= (int) $summary['processing'] ?></div>
    </div>
    <div class="c-card" style="padding:14px;">
      <div style="font-size:12px;color:var(--text-tertiary);">今日完成</div>
      <div style="font-size:1.5rem;font-weight:600;"><?= (int) $summary['today_done'] ?></div>
    </div>
    <div class="c-card" style="padding:14px;">
      <div style="font-size:12px;color:var(--text-tertiary);">今日失败</div>
      <div style="font-size:1.5rem;font-weight:600;color:#f87171;"><?= (int) $summary['today_fail'] ?></div>
    </div>
  </section>

  <?php if ($summary['lanes'] !== []): ?>
  <section class="form-section" style="margin-bottom:1.25rem;">
    <h2 style="margin:0 0 12px;font-size:1rem;">API 线路</h2>
    <table class="admin-table">
      <thead>
        <tr>
          <th>类型</th>
          <th>名称</th>
          <th>地址</th>
          <th>排队</th>
          <th>处理中</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($summary['lanes'] as $lane): ?>
        <tr>
          <td><?= $lane['type'] === 'video' ? '生视频' : '生图' ?></td>
          <td><?= htmlspecialchars($lane['name'], ENT_QUOTES) ?></td>
          <td class="mono small"><?= htmlspecialchars($lane['base_url'], ENT_QUOTES) ?></td>
          <td><?= (int) $lane['queued'] ?></td>
          <td><?= (int) $lane['processing'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

  <section class="form-section">
    <h2 style="margin:0 0 12px;font-size:1rem;">最近任务</h2>
    <?php if ($jobs === []): ?>
      <p class="muted">暂无排队记录。</p>
    <?php else: ?>
    <table class="admin-table">
      <thead>
        <tr>
          <th>#</th>
          <th>用户</th>
          <th>类型</th>
          <th>线路</th>
          <th>状态</th>
          <th>时间</th>
          <th>说明</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($jobs as $job): ?>
        <tr>
          <td><?= (int) $job['id'] ?></td>
          <td><?= htmlspecialchars($job['user_label'], ENT_QUOTES) ?></td>
          <td><?= $job['job_type'] === 'video' ? '生视频' : '生图' ?></td>
          <td><?= htmlspecialchars($job['provider_name'], ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($statusLabels[$job['status']] ?? $job['status'], ENT_QUOTES) ?></td>
          <td class="small"><?= htmlspecialchars($job['created_at'], ENT_QUOTES) ?></td>
          <td class="small"><?= $job['status'] === 'failed' ? htmlspecialchars($job['error_message'], ENT_QUOTES) : '' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

<?php
require dirname(__DIR__) . '/includes/admin_shell_end.php';
require dirname(__DIR__) . '/includes/ui_foot.php';
