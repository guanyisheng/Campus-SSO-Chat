<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/greeting.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/quota.php';
require_once __DIR__ . '/lib/user_groups.php';
require_once __DIR__ . '/lib/media.php';
require_once __DIR__ . '/lib/image_models.php';
require_once __DIR__ . '/lib/image_prompt_optimize.php';
require_once __DIR__ . '/lib/sso_consent.php';
require_once __DIR__ . '/lib/chat_notice.php';

require_login();
sso_require_consent_page();

if (!setting_bool('enable_chat', true)) {
    header('Location: ' . site_base_url() . '/index.php');
    exit;
}

$user = refresh_current_user_from_db() ?? current_user();
$userId = (int) ($user['id'] ?? 0);
user_groups_fix_schema();
$canAccessAdmin = user_can_access_admin($userId);
$greeting = greeting_for_user($user);
$displayName = user_friendly_name($user);
$initials = mb_substr($displayName, 0, 1, 'UTF-8');
$base = site_base_url();
$chatNoticeEnabled = setting_bool('chat_notice_enabled', true);
$chatNoticeTitle = trim(setting('chat_notice_title', ''));
$chatNoticeHtml = $chatNoticeEnabled ? chat_notice_html_resolved($base) : '';
$chatNoticeShow = $chatNoticeEnabled && trim($chatNoticeHtml) !== '';
$chatNoticeKey = $chatNoticeShow ? md5($chatNoticeTitle . "\n" . $chatNoticeHtml) : '';
$quotaInfo = quota_status($userId);
$imageMentions = media_mention_aliases('image');
$videoMentions = media_mention_aliases('video');
$enableLessonPlan = setting_bool('enable_lesson_plan', true);
$enableImagePromptOptimize = image_prompt_optimize_enabled();
$initialConversationId = (int) ($_GET['id'] ?? $_GET['conv'] ?? 0);
$initialAgent = trim((string) ($_GET['agent'] ?? ''));
if ($initialAgent !== '' && !preg_match('/^(preset|user)[:\-](\d+)$/', $initialAgent)) {
    $initialAgent = '';
}
if ($initialAgent !== '') {
    $initialConversationId = 0;
}
$page_title = '对话';
$ui_chat_aurora = true;
$ui_viewport_lock = true;
$welcomeRotatingTexts = [
    '探索新想法',
    '思考复杂问题',
    '学习感兴趣的知识',
    '创造有趣的内容',
    '解决遇到的难题',
];
$chatAssetVer = (string) max(
    (int) @filemtime(__DIR__ . '/assets/js/chat.js'),
    (int) @filemtime(__DIR__ . '/assets/js/chat-agents.js'),
    (int) @filemtime(__DIR__ . '/assets/js/chat-transport.js'),
    (int) @filemtime(__DIR__ . '/assets/js/text-type.js'),
    (int) @filemtime(__DIR__ . '/assets/js/chat-composer-media.js'),
    (int) @filemtime(__DIR__ . '/assets/js/chat-media.js'),
    (int) @filemtime(__DIR__ . '/assets/js/spotlight-card.js'),
    (int) @filemtime(__DIR__ . '/assets/js/border-glow.js'),
    (int) @filemtime(__DIR__ . '/assets/ui/css/spotlight-card.css'),
    (int) @filemtime(__DIR__ . '/assets/ui/css/border-glow.css'),
    (int) @filemtime(__DIR__ . '/assets/js/rotating-text.js'),
    (int) @filemtime(__DIR__ . '/assets/ui/css/bridge.css'),
    (int) @filemtime(__DIR__ . '/assets/ui/css/chat-media.css'),
    (int) @filemtime(__DIR__ . '/assets/ui/css/client.css'),
    (int) @filemtime(__DIR__ . '/assets/ui/css/code-workspace.css'),
    (int) @filemtime(__DIR__ . '/assets/js/chat-code-workspace.js'),
    (int) @filemtime(__DIR__ . '/assets/js/chat-composer-code.js'),
    (int) @filemtime(__DIR__ . '/assets/ui/css/chat-extras.css'),
    (int) @filemtime(__DIR__ . '/assets/ui/css/profile-modal.css'),
    (int) @filemtime(__DIR__ . '/assets/js/profile.js'),
    (int) @filemtime(__DIR__ . '/assets/js/lesson-plan-workspace.js'),
    (int) @filemtime(__DIR__ . '/assets/ui/css/lesson-plan-workspace.css'),
    (int) @filemtime(__DIR__ . '/assets/ui/css/lesson-plan-embed.css'),
    (int) @filemtime(__DIR__ . '/lesson-plan.php'),
);
$ui_chat_asset_ver = $chatAssetVer;
$ui_extra_modules = [
    '/assets/js/border-glow.js?v=' . $chatAssetVer,
];
$ui_extra_css = [
    '/assets/ui/css/staggered-sidebar.css?v=' . $chatAssetVer,
    '/assets/ui/css/rotating-text.css?v=' . $chatAssetVer,
    '/assets/ui/css/spotlight-card.css?v=' . $chatAssetVer,
    '/assets/ui/css/border-glow.css?v=' . $chatAssetVer,
    '/assets/ui/css/chat-media.css?v=' . $chatAssetVer,
    '/assets/ui/css/chat-extras.css?v=' . $chatAssetVer,
    '/assets/ui/css/profile-modal.css?v=' . $chatAssetVer,
    '/assets/ui/css/code-workspace.css?v=' . $chatAssetVer,
    '/assets/ui/css/chat-theme-light.css?v=' . $chatAssetVer,
];
if ($enableLessonPlan) {
    $ui_extra_css[] = '/assets/ui/css/lesson-plan-workspace.css?v=' . $chatAssetVer;
}
$ui_extra_js = [
    '/assets/js/text-type.js?v=' . $chatAssetVer,
    '/assets/js/marked.min.js',
    '/assets/js/purify.min.js',
    '/assets/js/markdown-render.js',
    '/assets/js/spotlight-card.js?v=' . $chatAssetVer,
    '/assets/js/chat-media.js?v=' . $chatAssetVer,
    '/assets/js/chat-transport.js?v=' . $chatAssetVer,
    '/assets/js/chat-composer-media.js?v=' . $chatAssetVer,
    '/assets/js/chat-composer-code.js?v=' . $chatAssetVer,
    '/assets/js/chat-code-workspace.js?v=' . $chatAssetVer,
    '/assets/js/chat-agents.js?v=' . $chatAssetVer,
    '/assets/js/rotating-text.js?v=' . $chatAssetVer,
    '/assets/js/staggered-sidebar.js?v=' . $chatAssetVer,
    '/assets/js/profile.js?v=' . $chatAssetVer,
];
if ($enableLessonPlan) {
    $ui_extra_js[] = '/assets/js/lesson-plan-workspace.js?v=' . $chatAssetVer;
}
$ui_extra_js[] = '/assets/js/chat.js?v=' . $chatAssetVer;
require __DIR__ . '/includes/ui_head.php';
?>

<div class="app">
  <div class="staggered-sidebar" id="staggered-sidebar" data-position="left">
    <div class="staggered-sidebar__prelayers" id="sidebar-prelayers" aria-hidden="true">
      <div class="sm-prelayer"></div>
      <div class="sm-prelayer"></div>
    </div>
  <aside class="app-sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-brand">
        <?php $brand_logo_variant = 'sidebar'; require __DIR__ . '/includes/brand_logo.php'; ?>
        <span class="name"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <div class="sidebar-header__actions">
        <button type="button" class="c-icon-btn" id="btn-sidebar-collapse" aria-label="关闭菜单" title="关闭">
          <span data-icon="sidebar"></span>
        </button>
      </div>
    </div>

    <div class="sidebar-content">
      <nav class="sidebar-nav" aria-label="侧栏">
        <button type="button" class="sidebar-item is-active" id="btn-new-chat">
          <span data-icon="edit"></span>新对话
        </button>
        <button type="button" class="sidebar-item" id="btn-conv-search" data-open-modal="searchModal" aria-expanded="false">
          <span data-icon="search"></span>搜索聊天
        </button>
        <?php if ($enableLessonPlan): ?>
        <button type="button" class="sidebar-item" id="btn-open-lesson-plan">
          <span data-icon="file-text"></span>教案生成
        </button>
        <?php endif; ?>
      </nav>

      <div class="sidebar-section sidebar-section--agents">
        <div class="sidebar-section__head">
          <span>智能体</span>
          <button type="button" class="sidebar-section__action" id="btn-manage-agents" title="创建或管理智能体" aria-label="创建智能体">
            <span data-icon="plus" data-size="14"></span>
          </button>
        </div>
        <ul id="agent-list" class="agent-list"></ul>
      </div>

      <div class="sidebar-section">最近</div>
      <div class="sidebar-history">
        <ul id="conv-list" class="conv-list"></ul>
      </div>
    </div>

    <div class="sidebar-footer">
      <div class="sidebar-quota" id="sidebar-quota" aria-label="今日额度"></div>
      <button type="button" class="sidebar-admin-link theme-toggle-btn" data-theme-toggle>
        <span data-icon="sun"></span>
        <span class="theme-toggle__label">白天模式</span>
      </button>
      <?php if ($canAccessAdmin): ?>
      <a href="<?= htmlspecialchars($base . '/admin/', ENT_QUOTES) ?>" class="sidebar-admin-link" target="_blank" rel="noopener">
        <span data-icon="settings"></span>
        <span>管理入口</span>
      </a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($base . '/logout.php', ENT_QUOTES) ?>" class="sidebar-logout-link">
        <span data-icon="log-out" data-size="16"></span>
        <span>退出登录</span>
      </a>
      <button type="button" class="sidebar-user" id="btn-open-profile" aria-haspopup="dialog">
        <span class="c-avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
        <div class="u-flex-1">
          <div class="sidebar-user__name"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="sidebar-user__plan"><?= htmlspecialchars($quotaInfo['group_name'] !== '' ? $quotaInfo['group_name'] : '普通用户', ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </button>
    </div>
  </aside>
  </div>
  <div class="sidebar-mask" id="sidebar-backdrop" aria-hidden="true"></div>

  <div class="app-body" id="app-body">
  <main class="app-main is-empty" id="chat-main">
    <?php require __DIR__ . '/includes/chat_aurora_bg.php'; ?>
    <header class="app-topbar">
      <button type="button" class="c-icon-btn" id="btn-sidebar-expand" hidden aria-label="展开侧栏">
        <span data-icon="sidebar"></span>
      </button>
      <div class="app-topbar__spacer"></div>
      <div id="quota-badge" class="quota-badge" title="今日使用额度"></div>
      <div class="app-topbar__actions">
        <button type="button" class="agent-clear-context-btn" id="btn-agent-clear-context" hidden title="清空上下文和聊天记录" aria-label="清空上下文和聊天记录">
          <span data-icon="refresh" data-size="14"></span>
          <span class="agent-clear-context-btn__label">清空上下文</span>
        </button>
        <button type="button" class="c-icon-btn theme-toggle-btn theme-toggle-btn--compact" data-theme-toggle title="切换主题" aria-label="切换主题">
          <span data-icon="sun"></span>
        </button>
        <button type="button" class="c-icon-btn" id="btn-model-new" title="新对话" aria-label="新对话">
          <span data-icon="edit-2"></span>
        </button>
      </div>
    </header>

    <div id="chat-empty-hero" class="chat-welcome">
      <div id="chat-welcome-default" class="chat-welcome-panel">
        <h1 class="chat-welcome__title" id="chat-empty-title">
          <span class="chat-welcome__greeting">
            <span class="chat-welcome__user-name"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>，我们一起
          </span>
          <span
            id="chat-rotating-text"
            class="text-rotate-host text-rotate-host--welcome"
            data-rotating-text
            data-interval="2000"
            data-stagger-from="last"
            data-stagger-duration="0.025"
            data-texts="<?= htmlspecialchars(json_encode($welcomeRotatingTexts, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
          ></span>
        </h1>
      </div>
      <div id="chat-welcome-agent" class="chat-welcome-panel chat-agent-welcome" hidden>
        <div class="chat-agent-welcome__avatar" id="chat-agent-welcome-avatar" aria-hidden="true"></div>
        <h2 class="chat-agent-welcome__name" id="chat-agent-welcome-name"></h2>
        <p class="chat-agent-welcome__desc" id="chat-agent-welcome-desc"></p>
      </div>
    </div>

    <div class="chat-stream" id="messages-wrap" role="log" aria-live="polite">
      <div class="chat-stream__inner" id="messages"></div>
    </div>

    <div class="composer">
      <button type="button" id="btn-scroll-bottom" class="chat-scroll-bottom" hidden aria-label="回到底部">
        <span data-icon="arrow-down"></span>
      </button>
      <div class="composer__inner">
        <form id="chat-form" class="composer__bar" autocomplete="off">
          <div id="file-attachments" class="file-attachments" hidden></div>
          <div id="composer-quote" class="composer-quote" hidden aria-label="引用内容">
            <div class="composer-quote__inner">
              <span class="composer-quote__label">引用</span>
              <div class="composer-quote__body">
                <img class="composer-quote__thumb" id="composer-quote-thumb" alt="" hidden>
                <p class="composer-quote__text" id="composer-quote-text"></p>
              </div>
              <button type="button" class="composer-quote__close" id="composer-quote-close" aria-label="取消引用">×</button>
            </div>
          </div>
          <div id="media-ref-strip" class="media-ref-strip" hidden aria-label="参考图"></div>
          <div class="composer__layout">
            <div class="composer__input-line">
              <textarea id="prompt" name="prompt" class="composer__textarea" placeholder="有问题,尽管问" rows="1" data-autogrow maxlength="32000"></textarea>
              <div class="composer__actions">
                <button type="submit" id="btn-send" class="send-btn" aria-label="发送">
                  <span data-icon="arrow-up"></span>
                </button>
              </div>
            </div>
            <div class="composer__modes" aria-label="功能">
              <div class="composer__strip">
                <div class="composer__strip-scroll">
                  <div class="composer__strip-tools" id="composer-tools">
                    <button type="button" id="btn-attach" class="c-icon-btn composer__attach" title="添加附件或图片" aria-label="添加附件或图片">
                      <span data-icon="plus"></span>
                    </button>
                    <input type="file" id="file-input" accept=".txt,.pdf,.docx,.xlsx,.xls,.csv,image/jpeg,image/png,image/webp,image/gif" hidden>
                    <button type="button" id="btn-voice" class="c-icon-btn" title="语音输入" aria-label="语音输入">
                      <span data-icon="mic"></span>
                    </button>
                  </div>
                  <div class="composer__left composer__strip-modes" id="composer-left-default">
                    <?php if (setting_bool('enable_image_gen', true)): ?>
                    <button type="button" class="composer-mode-trigger" id="btn-enter-image-mode" title="图像生成">
                      <span data-icon="image" data-size="16"></span>
                      <span>图像生成</span>
                    </button>
                    <?php endif; ?>
                    <?php if (setting_bool('enable_video_gen', true)): ?>
                    <button type="button" class="composer-mode-trigger" id="btn-enter-video-mode" title="视频生成">
                      <span data-icon="video" data-size="16"></span>
                      <span>视频生成</span>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="composer-mode-trigger" id="btn-enter-code-mode" title="编程">
                      <span data-icon="code" data-size="16"></span>
                      <span>编程</span>
                    </button>
                  </div>
                  <div class="composer__left composer__left--code" id="composer-code-tools" hidden>
                    <span class="image-mode-chip code-mode-chip" title="编程模式">
                      <span class="image-mode-chip__icon" aria-hidden="true"><span data-icon="code" data-size="14"></span></span>
                      <span>编程</span>
                      <button type="button" class="image-mode-chip__close" id="btn-code-mode-close" aria-label="退出编程">×</button>
                    </span>
                  </div>
                  <div class="composer__left composer__left--image" id="composer-image-tools" hidden>
                    <button type="button" id="btn-image-ref" class="c-icon-btn" title="上传参考图（图生图）" aria-label="上传参考图">
                      <span data-icon="plus"></span>
                    </button>
                    <input type="file" id="image-ref-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                    <span class="image-mode-chip" title="图像生成模式">
                      <span class="image-mode-chip__icon" aria-hidden="true"><span data-icon="image" data-size="14"></span></span>
                      <span>图像生成</span>
                      <button type="button" class="image-mode-chip__close" id="btn-image-mode-close" aria-label="退出图像生成">×</button>
                    </span>
                    <button type="button" class="mode-pill image-opt-pill" data-popover="imageModelPicker" aria-label="选择生成模型">
                      <span data-icon="cpu" data-size="14"></span>
                      <span data-popover-label id="image-model-label">Pony V6 XL</span>
                      <span data-icon="chevron-down" data-size="14"></span>
                    </button>
                    <button type="button" class="mode-pill image-opt-pill" data-popover="imageRatioPicker" aria-label="选择比例">
                      <span data-icon="sliders" data-size="14"></span>
                      <span data-popover-label id="image-ratio-label">比例 4:3</span>
                      <span data-icon="chevron-down" data-size="14"></span>
                    </button>
                    <button type="button" class="mode-pill image-opt-pill" data-popover="imageStylePicker" aria-label="选择风格">
                      <span data-icon="filter" data-size="14"></span>
                      <span data-popover-label id="image-style-label">风格</span>
                      <span data-icon="chevron-down" data-size="14"></span>
                    </button>
                    <button type="button" class="mode-pill image-opt-pill" data-popover="imageSizePicker" aria-label="选择尺寸">
                      <span data-icon="image" data-size="14"></span>
                      <span data-popover-label id="image-size-label">1024×768</span>
                      <span data-icon="chevron-down" data-size="14"></span>
                    </button>
                    <?php if ($enableImagePromptOptimize): ?>
                    <button type="button" class="mode-pill image-opt-pill image-prompt-optimize-btn" id="btn-image-prompt-optimize" title="翻译并优化为英文生图（输入框保持中文）">
                      <span data-icon="sparkles" data-size="14"></span>
                      <span>优化</span>
                    </button>
                    <?php endif; ?>
                  </div>
                  <div class="composer__left composer__left--video" id="composer-video-tools" hidden>
                    <button type="button" id="btn-video-ref" class="c-icon-btn" title="上传参考图" aria-label="上传参考图">
                      <span data-icon="plus"></span>
                    </button>
                    <input type="file" id="video-ref-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                    <span class="image-mode-chip video-mode-chip" title="视频生成模式">
                      <span class="image-mode-chip__icon" aria-hidden="true"><span data-icon="video" data-size="14"></span></span>
                      <span>视频生成</span>
                      <button type="button" class="image-mode-chip__close" id="btn-video-mode-close" aria-label="退出视频生成">×</button>
                    </span>
                    <button type="button" class="mode-pill image-opt-pill" data-popover="videoRatioPicker" aria-label="选择比例">
                      <span data-icon="sliders" data-size="14"></span>
                      <span data-popover-label id="video-ratio-label">比例 4:3</span>
                      <span data-icon="chevron-down" data-size="14"></span>
                    </button>
                  </div>
                </div>
                <div class="composer__strip-agent" id="agent-active-pill" hidden></div>
                <div class="composer__strip-model">
                  <button type="button" class="composer__deep-think" id="btn-deep-think" aria-pressed="false" title="开启后模型会先思考再回答，耗时更长">
                    <span class="composer__deep-think__dot" aria-hidden="true"></span>
                    <span>深度思考</span>
                  </button>
                  <select id="model-select" class="chat-model-select" aria-hidden="true" tabindex="-1"></select>
                  <button type="button" class="mode-pill composer__model-pill" data-popover="modelPicker" aria-label="选择模型">
                    <span data-popover-label id="model-picker-label">模型</span>
                    <span data-icon="chevron-down" data-size="14"></span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </form>

        <div id="voice-recorder" class="voice-recorder" hidden role="region" aria-label="语音输入">
          <button type="button" id="voice-cancel" class="voice-rec-btn voice-rec-cancel" aria-label="取消">
            <span data-icon="x" data-size="20"></span>
          </button>
          <div class="voice-rec-center">
            <div class="voice-wave" id="voice-wave" aria-hidden="true"></div>
            <span class="voice-timer" id="voice-timer">0:00</span>
          </div>
          <button type="button" id="voice-confirm" class="voice-rec-btn voice-rec-confirm" aria-label="确认">
            <span data-icon="check" data-size="20"></span>
          </button>
        </div>

        <div class="composer__hint u-md-only">AI 可能会犯错，请核实重要信息。</div>
      </div>
    </div>

    <div class="c-popover" id="modelPicker"></div>
    <div class="c-popover c-popover--image" id="imageModelPicker"></div>
    <div class="c-popover c-popover--image" id="imageRatioPicker"></div>
    <div class="c-popover c-popover--image c-popover--style" id="imageStylePicker"></div>
    <div class="c-popover c-popover--image" id="imageSizePicker"></div>
    <div class="c-popover c-popover--image" id="videoRatioPicker"></div>
  </main>

  <aside id="code-workspace" class="code-workspace" hidden aria-label="编程工作区">
    <header class="code-workspace__head">
      <div class="code-workspace__tabs" id="code-ws-tabs" role="tablist">
        <button type="button" class="code-ws-tab is-active" data-tab="code" role="tab" aria-selected="true">代码</button>
        <button type="button" class="code-ws-tab" data-tab="preview" id="code-ws-tab-preview" role="tab" aria-selected="false" hidden>预览</button>
      </div>
      <span class="code-workspace__lang" id="code-ws-lang">code</span>
      <div class="code-workspace__actions">
        <button type="button" id="code-ws-run" class="code-ws-action code-ws-action--primary" hidden>
          <span class="code-ws-action__icon" aria-hidden="true">▶</span>运行
        </button>
        <button type="button" id="code-ws-preview-btn" class="code-ws-action code-ws-action--primary" hidden>
          <span class="code-ws-action__icon" aria-hidden="true">▶</span>预览
        </button>
        <button type="button" id="code-ws-copy" class="code-ws-action" title="复制" aria-label="复制">
          <span data-icon="copy" data-size="16"></span>
        </button>
        <button type="button" id="code-ws-close" class="code-ws-action" title="关闭" aria-label="关闭工作区">
          <span data-icon="x" data-size="18"></span>
        </button>
      </div>
    </header>
    <p class="code-workspace__hint" id="code-ws-hint">本地预览，注意内容合规与信息安全</p>
    <div class="code-workspace__body" id="code-ws-body">
      <div class="code-ws-panel is-active" data-panel="code">
        <pre class="code-ws-editor"><code id="code-ws-source" class="code-ws-source"></code></pre>
      </div>
      <div class="code-ws-panel" data-panel="preview" hidden>
        <iframe id="code-ws-preview-frame" class="code-ws-preview-frame" title="HTML 预览" sandbox="allow-scripts allow-same-origin"></iframe>
      </div>
      <div class="code-ws-console" id="code-ws-console" hidden>
        <div class="code-ws-console__head">
          <span>控制台</span>
          <button type="button" id="code-ws-console-clear" class="code-ws-console__clear" title="清空">清空</button>
        </div>
        <pre id="code-ws-console-out" class="code-ws-console__out"></pre>
      </div>
    </div>
  </aside>

  <?php if ($enableLessonPlan): ?>
  <aside id="lesson-plan-workspace" class="lesson-plan-workspace" hidden aria-label="教案生成工作区">
    <header class="lesson-plan-workspace__head">
      <span class="lesson-plan-workspace__title">教案生成</span>
      <span class="lesson-plan-workspace__status" id="lesson-plan-ws-status">填写课次后可预览或 AI 生成</span>
      <div class="lesson-plan-workspace__actions">
        <button type="button" id="lesson-plan-ws-minimize" class="lesson-plan-ws-action" title="挂起（后台继续生成）" aria-label="挂起">
          <span data-icon="chevron-right" data-size="18"></span>
        </button>
        <button type="button" id="lesson-plan-ws-close" class="lesson-plan-ws-action" title="关闭面板" aria-label="关闭">
          <span data-icon="x" data-size="18"></span>
        </button>
      </div>
    </header>
    <div class="lesson-plan-workspace__frame-wrap">
      <iframe
        id="lesson-plan-iframe"
        class="lesson-plan-workspace__frame"
        title="教案生成"
        loading="lazy"
      ></iframe>
    </div>
  </aside>
  <button type="button" id="lesson-plan-float" class="lesson-plan-float" hidden aria-label="打开教案工作区">教案生成</button>
  <?php endif; ?>
  </div>
</div>

<div id="delete-conv-modal" class="upload-notice-modal" hidden role="dialog" aria-modal="true" aria-labelledby="delete-conv-title">
  <div class="upload-notice-backdrop" aria-hidden="true"></div>
  <div class="upload-notice-panel">
    <h3 id="delete-conv-title" class="upload-notice-title">删除对话</h3>
    <p class="upload-notice-text">确定删除这条对话记录？删除后无法恢复。</p>
    <div class="upload-notice-actions">
      <button type="button" id="delete-conv-cancel" class="c-btn c-btn--ghost c-btn--sm">取消</button>
      <button type="button" id="delete-conv-confirm" class="c-btn c-btn--danger c-btn--sm">删除</button>
    </div>
  </div>
</div>

<div id="upload-notice-modal" class="upload-notice-modal" hidden role="dialog" aria-modal="true" aria-labelledby="upload-notice-title">
  <div class="upload-notice-backdrop" aria-hidden="true"></div>
  <div class="upload-notice-panel">
    <h3 id="upload-notice-title" class="upload-notice-title">上传附件说明</h3>
    <p class="upload-notice-text">附件会保存在服务器，并由管理员定期清理。请勿上传敏感隐私内容；重要文件请自行备份。</p>
    <div class="upload-notice-actions">
      <button type="button" id="upload-notice-skip" class="c-btn c-btn--ghost c-btn--sm">不再提醒</button>
      <button type="button" id="upload-notice-ok" class="c-btn c-btn--primary c-btn--sm">我知道了</button>
    </div>
  </div>
</div>

<?php if ($chatNoticeShow): ?>
<div id="chat-notice-modal" class="chat-notice-modal" hidden role="dialog" aria-modal="true"<?= $chatNoticeTitle !== '' ? ' aria-labelledby="chat-notice-title"' : ' aria-label="系统公告"' ?>>
  <div class="chat-notice-backdrop" aria-hidden="true"></div>
  <div class="chat-notice-panel">
    <?php if ($chatNoticeTitle !== ''): ?>
    <h3 id="chat-notice-title" class="chat-notice-title"><?= htmlspecialchars($chatNoticeTitle, ENT_QUOTES, 'UTF-8') ?></h3>
    <?php endif; ?>
    <div class="chat-notice-body"><?= $chatNoticeHtml ?></div>
    <div class="chat-notice-actions">
      <button type="button" id="chat-notice-snooze" class="c-btn c-btn--ghost c-btn--sm">不再提示</button>
      <button type="button" id="chat-notice-close" class="c-btn c-btn--primary c-btn--sm">关闭</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div id="agentModal" class="c-modal-mask agent-modal" hidden>
  <div class="c-modal agent-modal__panel" role="dialog" aria-labelledby="agent-modal-title">
    <div class="c-modal__head">
      <h2 id="agent-modal-title" class="c-modal__title">创建智能体</h2>
      <button type="button" class="c-icon-btn" data-close-agent-modal aria-label="关闭">
        <span data-icon="x"></span>
      </button>
    </div>
    <form id="agent-form" class="c-modal__body agent-form" enctype="multipart/form-data">
      <p id="agent-form-limit-hint" class="c-help" style="margin-top:0;"></p>
      <div class="c-field">
        <label class="c-label" for="agent-form-name">名称</label>
        <input class="c-input" id="agent-form-name" name="display_name" type="text" required maxlength="64" placeholder="例如：写作助手">
      </div>
      <div class="c-field">
        <label class="c-label" for="agent-form-desc">简介（可选）</label>
        <input class="c-input" id="agent-form-desc" name="description" type="text" maxlength="512" placeholder="一句话介绍">
      </div>
      <div class="c-field agent-form-avatar-field">
        <label class="c-label">头像</label>
        <div class="agent-form-avatar-row">
          <div id="agent-form-avatar-preview" class="agent-form-avatar-preview"><span class="agent-form-avatar-fallback">AI</span></div>
          <input class="c-input" id="agent-form-avatar" name="avatar" type="file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
        </div>
      </div>
      <div class="c-field">
        <label class="c-label" for="agent-form-prompt">系统提示词</label>
        <textarea class="c-input" id="agent-form-prompt" name="system_prompt" rows="5" required placeholder="定义角色、语气、能力与回答风格…"></textarea>
      </div>
      <div class="c-field">
        <label class="c-label" for="agent-form-model">默认模型</label>
        <select class="c-input" id="agent-form-model" name="model_id">
          <option value="0">（跟随当前模型）</option>
        </select>
      </div>
      <div class="c-modal__foot">
        <button type="button" class="c-btn c-btn--ghost" data-close-agent-modal>取消</button>
        <button type="submit" class="c-btn c-btn--primary">保存</button>
      </div>
    </form>
  </div>
</div>

<div id="chat-toast" class="c-toast-container" role="status" aria-live="polite" hidden></div>

<div id="profile-modal" class="profile-modal" hidden role="dialog" aria-modal="true" aria-labelledby="profile-modal-title">
  <div class="profile-modal__backdrop" aria-hidden="true"></div>
  <div class="profile-modal__panel">
    <button type="button" class="profile-modal__close" aria-label="关闭">×</button>
    <div class="profile-modal__head">
      <div class="profile-modal__avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
      <div>
        <h2 id="profile-modal-title" class="profile-modal__name"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="profile-modal__meta"><?= htmlspecialchars($user['campus_uid'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
      </div>
    </div>
    <div class="profile-modal__section">
      <h3 class="profile-modal__section-title">身份</h3>
      <p class="profile-modal__meta" style="margin:0;color:var(--text-primary);"><?= htmlspecialchars($quotaInfo['group_name'] !== '' ? $quotaInfo['group_name'] : '普通用户', ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div class="profile-modal__section">
      <h3 class="profile-modal__section-title">今日额度</h3>
      <div class="profile-modal__quota" id="profile-quota-host"></div>
    </div>
    <div class="profile-modal__actions">
      <?php if ($canAccessAdmin): ?>
      <a href="<?= htmlspecialchars($base . '/admin/', ENT_QUOTES) ?>" class="profile-modal__link" target="_blank" rel="noopener">
        <span data-icon="settings" data-size="16"></span>
        <span>管理后台</span>
      </a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($base . '/logout.php', ENT_QUOTES) ?>" class="profile-modal__logout">
        <span data-icon="log-out" data-size="16"></span>
        <span>退出登录</span>
      </a>
    </div>
  </div>
</div>

<script>
  window.CAMPUS_CHAT = {
    apiUrl: <?= json_encode($base . '/api/chat.php', JSON_UNESCAPED_UNICODE) ?>,
    chatPageUrl: <?= json_encode($base . '/chat.php', JSON_UNESCAPED_UNICODE) ?>,
    initialConversationId: <?= $initialConversationId > 0 ? (string) $initialConversationId : '0' ?>,
    initialAgent: <?= json_encode($initialAgent, JSON_UNESCAPED_UNICODE) ?>,
    modelsUrl: <?= json_encode($base . '/api/models.php', JSON_UNESCAPED_UNICODE) ?>,
    agentsUrl: <?= json_encode($base . '/api/agents.php', JSON_UNESCAPED_UNICODE) ?>,
    convUrl: <?= json_encode($base . '/api/conversations.php', JSON_UNESCAPED_UNICODE) ?>,
    followupsUrl: <?= json_encode($base . '/api/followups.php', JSON_UNESCAPED_UNICODE) ?>,
    uploadUrl: <?= json_encode($base . '/api/upload.php', JSON_UNESCAPED_UNICODE) ?>,
    mediaRefUrl: <?= json_encode($base . '/api/media_ref.php', JSON_UNESCAPED_UNICODE) ?>,
    runCodeUrl: <?= json_encode($base . '/api/run_code.php', JSON_UNESCAPED_UNICODE) ?>,
    imageUrl: <?= json_encode($base . '/api/image.php', JSON_UNESCAPED_UNICODE) ?>,
    imagePromptOptimizeUrl: <?= $enableImagePromptOptimize ? json_encode($base . '/api/image_prompt_optimize.php', JSON_UNESCAPED_UNICODE) : 'null' ?>,
    mediaQueueUrl: <?= json_encode($base . '/api/media_queue.php', JSON_UNESCAPED_UNICODE) ?>,
    videoUrl: <?= json_encode($base . '/api/video.php', JSON_UNESCAPED_UNICODE) ?>,
    quotaUrl: <?= json_encode($base . '/api/quota.php', JSON_UNESCAPED_UNICODE) ?>,
    userName: <?= json_encode($displayName, JSON_UNESCAPED_UNICODE) ?>,
    campusUid: <?= json_encode((string) ($user['campus_uid'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    groupName: <?= json_encode((string) ($quotaInfo['group_name'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    canAccessAdmin: <?= $canAccessAdmin ? 'true' : 'false' ?>,
    logoutUrl: <?= json_encode($base . '/logout.php', JSON_UNESCAPED_UNICODE) ?>,
    noticeKey: <?= json_encode($chatNoticeKey, JSON_UNESCAPED_UNICODE) ?>,
    quota: <?= json_encode($quotaInfo, JSON_UNESCAPED_UNICODE) ?>,
    imageMentions: <?= json_encode($imageMentions, JSON_UNESCAPED_UNICODE) ?>,
    videoMentions: <?= json_encode($videoMentions, JSON_UNESCAPED_UNICODE) ?>,
    enableImage: <?= setting_bool('enable_image_gen', true) ? 'true' : 'false' ?>,
    enableVideo: <?= setting_bool('enable_video_gen', true) ? 'true' : 'false' ?>,
    imageModels: <?= json_encode(media_image_model_public_options(), JSON_UNESCAPED_UNICODE) ?>,
    imageModelDefault: <?= json_encode(media_image_model_default_key(), JSON_UNESCAPED_UNICODE) ?>,
    welcomeRotatingTexts: <?= json_encode($welcomeRotatingTexts, JSON_UNESCAPED_UNICODE) ?>,
    welcomeRotateInterval: 2000,
    enableLessonPlan: <?= $enableLessonPlan ? 'true' : 'false' ?>,
    lessonPlanEmbedUrl: <?= json_encode($base . '/lesson-plan.php?embed=1', JSON_UNESCAPED_UNICODE) ?>
  };
</script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (window.mountSearchModal) window.mountSearchModal();
    if (window.renderIcons) window.renderIcons();
  });
</script>

<?php require __DIR__ . '/includes/ui_foot.php'; ?>
