<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/settings.php';

require_admin();

$base = site_base_url();
$s = settings_all();
$saved = isset($_GET['saved']);

$page_title = '系统设置';
$ui_css = 'admin';
$admin_active = 'dashboard';
require dirname(__DIR__) . '/includes/ui_head.php';
require dirname(__DIR__) . '/includes/admin_shell.php';
?>

  <?php if ($saved): ?>
    <div class="alert alert-success">已保存</div>
  <?php endif; ?>

  <form method="post" action="<?= htmlspecialchars($base . '/admin/save.php', ENT_QUOTES) ?>" class="form-section">
    <section style="margin-bottom:1.5rem;">
      <h2 style="margin:0 0 1rem;font-size:1rem;">推理参数（全局）</h2>
      <div class="c-field">
        <label class="c-label">最大输出 Token</label>
        <input class="c-input" type="number" name="ollama_max_tokens" min="256" max="32768" value="<?= (int) $s['ollama_max_tokens'] ?>">
      </div>
      <div class="c-field">
        <label class="c-label">上下文 Token</label>
        <input class="c-input" type="number" name="ollama_num_ctx" min="1024" max="131072" value="<?= (int) $s['ollama_num_ctx'] ?>">
      </div>
      <div class="c-field">
        <label class="c-label">温度</label>
        <input class="c-input" type="number" name="ollama_temperature" min="0" max="2" step="0.1" value="<?= htmlspecialchars($s['ollama_temperature'], ENT_QUOTES) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">Top P</label>
        <input class="c-input" type="number" name="ollama_top_p" min="0" max="1" step="0.05" value="<?= htmlspecialchars($s['ollama_top_p'], ENT_QUOTES) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">超时（秒）</label>
        <input class="c-input" type="number" name="ollama_timeout" min="30" max="600" value="<?= (int) $s['ollama_timeout'] ?>">
      </div>
      <div class="c-field">
        <label class="c-label">保留对话轮数</label>
        <input class="c-input" type="number" name="ollama_history_turns" min="1" max="50" value="<?= (int) $s['ollama_history_turns'] ?>">
      </div>
    </section>

    <section style="margin-bottom:1.5rem;">
      <h2 style="margin:0 0 1rem;font-size:1rem;">每日额度</h2>
      <p style="margin:0 0 1rem;font-size:0.875rem;color:var(--text-tertiary);line-height:1.5;">
        按自然日（服务器时区）统计，每位用户独立计数。设为 0 表示不限制。
      </p>
      <div class="c-field">
        <label class="c-label">对话轮数 / 天</label>
        <input class="c-input" type="number" name="daily_chat_limit" min="0" max="10000" value="<?= (int) ($s['daily_chat_limit'] ?? 100) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">生图次数 / 天</label>
        <input class="c-input" type="number" name="daily_image_limit" min="0" max="1000" value="<?= (int) ($s['daily_image_limit'] ?? 20) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">生视频次数 / 天</label>
        <input class="c-input" type="number" name="daily_video_limit" min="0" max="500" value="<?= (int) ($s['daily_video_limit'] ?? 10) ?>">
      </div>
      <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
        <input type="checkbox" name="enable_image_gen" value="1" <?= ($s['enable_image_gen'] ?? '1') === '1' ? 'checked' : '' ?>>
        <span>开放生图（@图片）</span>
      </label>
      <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1rem;">
        <input type="checkbox" name="enable_video_gen" value="1" <?= ($s['enable_video_gen'] ?? '1') === '1' ? 'checked' : '' ?>>
        <span>开放生视频（@视频）</span>
      </label>
      <div class="c-field">
        <label class="c-label">生图 @ 别名（逗号分隔）</label>
        <input class="c-input" type="text" name="image_mention_aliases" value="<?= htmlspecialchars($s['image_mention_aliases'] ?? '@图片,@image,@生图', ENT_QUOTES) ?>">
      </div>
      <div class="c-field">
        <label class="c-label">生视频 @ 别名（逗号分隔）</label>
        <input class="c-input" type="text" name="video_mention_aliases" value="<?= htmlspecialchars($s['video_mention_aliases'] ?? '@视频,@video,@生视频', ENT_QUOTES) ?>">
      </div>
    </section>

    <section style="margin-bottom:1.5rem;">
      <h2 style="margin:0 0 1rem;font-size:1rem;">权限与功能</h2>
      <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
        <input type="checkbox" name="enable_lesson_plan" value="1" <?= ($s['enable_lesson_plan'] ?? '1') === '1' ? 'checked' : '' ?>>
        <span>开放教案生成工具（使用上方对话 API 与今日对话额度）</span>
      </label>
      <div class="c-field" style="margin:0 0 0.75rem 1.5rem;max-width:280px;">
        <label class="c-label">教案 AI 最大输出 Token</label>
        <input class="c-input" type="number" name="lesson_plan_max_tokens" min="16384" max="131072" step="1024" value="<?= (int) ($s['lesson_plan_max_tokens'] ?? 65536) ?>">
        <p style="margin:0.35rem 0 0;font-size:0.75rem;color:var(--text-tertiary);">教案 JSON 体积大，建议 ≥65536；与上方「对话 max_tokens」独立。</p>
      </div>
      <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
        <input type="checkbox" name="enable_chat" value="1" <?= $s['enable_chat'] === '1' ? 'checked' : '' ?>>
        <span>开放对话</span>
      </label>
      <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
        <input type="checkbox" name="enable_oidc_auth" value="1" <?= $s['enable_oidc_auth'] === '1' ? 'checked' : '' ?>>
        <span>允许统一认证登录</span>
      </label>
      <label style="display:flex;align-items:center;gap:0.5rem;">
        <input type="checkbox" name="enable_local_auth" value="1" <?= $s['enable_local_auth'] === '1' ? 'checked' : '' ?>>
        <span>允许本站注册 / 登录</span>
      </label>
    </section>

    <section style="margin-bottom:1.5rem;">
      <h2 style="margin:0 0 1rem;font-size:1rem;">对话页公告</h2>
      <p style="margin:0 0 1rem;font-size:0.875rem;color:var(--text-tertiary);line-height:1.5;">
        用户进入对话页时弹出，支持 HTML。需填写公告内容后才会显示。「关闭」仅本次访问不显示；「不再提示」3 天内不显示。
      </p>
      <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1rem;">
        <input type="checkbox" name="chat_notice_enabled" value="1" <?= ($s['chat_notice_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
        <span>启用公告弹窗</span>
      </label>
      <div class="c-field">
        <label class="c-label">公告标题（可选，留空则仅显示下方 HTML）</label>
        <input class="c-input" type="text" name="chat_notice_title" maxlength="120"
               value="<?= htmlspecialchars($s['chat_notice_title'] ?? '', ENT_QUOTES) ?>"
               placeholder="留空使用正文内标题">
      </div>
      <div class="c-field">
        <label class="c-label">公告内容（HTML）</label>
        <textarea class="c-input" name="chat_notice_html" rows="10" style="font-family:ui-monospace,monospace;font-size:0.8125rem;line-height:1.5;resize:vertical;"
                  placeholder="<p>欢迎使用…</p>"><?= htmlspecialchars($s['chat_notice_html'] ?? '', ENT_QUOTES) ?></textarea>
      </div>
    </section>

    <section style="margin-bottom:1.5rem;">
      <h2 style="margin:0 0 1rem;font-size:1rem;">内容安全</h2>
      <p style="margin:0 0 1rem;font-size:0.875rem;color:var(--text-tertiary);line-height:1.5;">
        对话、生图、生视频均会注入安全规范；命中下方敏感词时将直接拒绝（不消耗生图/生视频额度；对话不调用模型）。
        敏感词每行一个，或用逗号分隔。留空则仅注入系统提示、不做关键词拦截。
        若模型经常无故回复「我无法…」，可关闭本项或精简「追加系统提示」。
      </p>
      <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1rem;">
        <input type="checkbox" name="content_policy_enabled" value="1" <?= ($s['content_policy_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
        <span>启用内容安全策略</span>
      </label>
      <div class="c-field">
        <label class="c-label">敏感词列表</label>
        <textarea class="c-input" name="content_sensitive_words" rows="8" style="font-family:ui-monospace,monospace;font-size:0.8125rem;line-height:1.5;resize:vertical;"
                  placeholder="反党&#10;反社会&#10;…"><?= htmlspecialchars($s['content_sensitive_words'] ?? '', ENT_QUOTES) ?></textarea>
      </div>
      <div class="c-field">
        <label class="c-label">拒绝回复文案（命中敏感词时）</label>
        <input class="c-input" type="text" name="content_policy_refusal" maxlength="500"
               value="<?= htmlspecialchars($s['content_policy_refusal'] ?? '', ENT_QUOTES) ?>"
               placeholder="留空使用默认：抱歉，您的请求涉及敏感或违规内容…">
      </div>
      <div class="c-field">
        <label class="c-label">追加系统提示（可选，注入对话模型）</label>
        <textarea class="c-input" name="content_policy_system_extra" rows="4" style="font-size:0.875rem;line-height:1.5;resize:vertical;"
                  placeholder="可补充本单位额外合规要求…"><?= htmlspecialchars($s['content_policy_system_extra'] ?? '', ENT_QUOTES) ?></textarea>
      </div>
    </section>

    <button type="submit" class="c-btn c-btn--primary c-btn--block">保存</button>
  </form>

<?php
require dirname(__DIR__) . '/includes/admin_shell_end.php';
require dirname(__DIR__) . '/includes/ui_foot.php';
