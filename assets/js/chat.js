(function () {
  const cfg = window.CAMPUS_CHAT || {};
  const form = document.getElementById('chat-form');
  const promptEl = document.getElementById('prompt');
  const messagesEl = document.getElementById('messages');
  const messagesWrap = document.getElementById('messages-wrap');
  const btnSend = document.getElementById('btn-send');
  const btnDeepThink = document.getElementById('btn-deep-think');
  const btnNew = document.getElementById('btn-new-chat');
  const btnModelNew = document.getElementById('btn-model-new');
  const btnAgentClearContext = document.getElementById('btn-agent-clear-context');
  const modelSelect = document.getElementById('model-select');
  const convList = document.getElementById('conv-list');
  const fileInput = document.getElementById('file-input');
  const btnAttach = document.getElementById('btn-attach');
  const uploadNoticeModal = document.getElementById('upload-notice-modal');
  const uploadNoticeOk = document.getElementById('upload-notice-ok');
  const uploadNoticeSkip = document.getElementById('upload-notice-skip');
  const deleteConvModal = document.getElementById('delete-conv-modal');
  const deleteConvCancel = document.getElementById('delete-conv-cancel');
  const deleteConvConfirm = document.getElementById('delete-conv-confirm');
  const chatNoticeModal = document.getElementById('chat-notice-modal');
  const chatNoticeClose = document.getElementById('chat-notice-close');
  const chatNoticeSnooze = document.getElementById('chat-notice-snooze');
  const CHAT_NOTICE_SNOOZE_KEY = 'campus_chat_notice_snooze';
  const CHAT_NOTICE_SESSION_KEY = 'campus_chat_notice_closed_session';
  const CHAT_NOTICE_SNOOZE_MS = 3 * 24 * 60 * 60 * 1000;
  const UPLOAD_NOTICE_KEY = 'campus_chat_upload_notice_skip';
  const fileAttachmentsEl = document.getElementById('file-attachments');
  const composerQuoteEl = document.getElementById('composer-quote');
  const composerQuoteText = document.getElementById('composer-quote-text');
  const composerQuoteThumb = document.getElementById('composer-quote-thumb');
  const composerQuoteClose = document.getElementById('composer-quote-close');
  const chatMain = document.getElementById('chat-main');
  const emptyHero = document.getElementById('chat-empty-hero');
  const syncIndicator = document.getElementById('sync-indicator');
  const btnScrollBottom = document.getElementById('btn-scroll-bottom');

  let models = [];
  let conversations = [];
  let history = [];
  let conversationId = 0;
  let modelId = 0;
  let activeAgentRef = null;
  /** @type {{type:string,id:number,display_name?:string,description?:string,avatar_url?:string}|null} */
  let activeAgentProfile = null;
  /** @type {{filename:string,text:string}[]} */
  let attachments = [];
  /** @type {{url:string,name:string}[]} */
  let imageAttachments = [];
  /** @type {Record<string, {file: File, el: HTMLElement, xhr?: XMLHttpRequest}>} */
  let pendingUploads = {};
  let currentConvUpdatedAt = '';
  let isStreaming = false;
  let chatStreamAbort = null;
  let activeStreamBubble = null;
  let activeStreamReasoning = '';
  let activeStreamContent = '';
  const DEEP_THINK_KEY = 'campus_chat_deep_think';
  let deepThinkEnabled = false;
  let syncTimer = null;
  let stickToBottom = true;
  let convNavSeq = 0;
  let convNavInFlight = 0;
  const SCROLL_BOTTOM_THRESHOLD = 72;

  function isGenerating() {
    return isStreaming || hasPendingGeneration();
  }

  function isNearBottom(scroller) {
    const el = scroller || messagesWrap;
    if (!el) return true;
    return el.scrollHeight - el.scrollTop - el.clientHeight <= SCROLL_BOTTOM_THRESHOLD;
  }

  function updateScrollBottomButton() {
    if (!btnScrollBottom) return;
    const show = isGenerating() && !isNearBottom(messagesWrap);
    btnScrollBottom.hidden = !show;
    btnScrollBottom.classList.toggle('is-visible', show);
  }

  messagesWrap?.addEventListener(
    'scroll',
    function () {
      stickToBottom = isNearBottom(messagesWrap);
      updateScrollBottomButton();
    },
    { passive: true }
  );

  btnScrollBottom?.addEventListener('click', function () {
    stickToBottom = true;
    scrollMessagesToBottom(true);
  });

  const btnSidebarExpand = document.getElementById('btn-sidebar-expand');
  const btnSidebarCollapse = document.getElementById('btn-sidebar-collapse');
  const btnConvSearch = document.getElementById('btn-conv-search');
  const sidebarSearchBox = document.getElementById('sidebar-search-box');
  const convSearchInput = document.getElementById('conv-search-input');
  const sidebarBackdrop = document.getElementById('sidebar-backdrop');
  const chatEmptyTitle = document.getElementById('chat-empty-title');
  const mqSidebar = window.matchMedia('(max-width: 1023px)');
  const SIDEBAR_COLLAPSED_KEY = 'campus_chat_sidebar_collapsed';

  let convSearchQuery = '';
  let pendingDeleteConvId = null;
  /** @type {{type:'text'|'image', text:string, imageUrl?:string, role?:string}|null} */
  let pendingQuote = null;

  function isMobileLayout() {
    return mqSidebar.matches;
  }

  function syncSidebarChrome() {
    const mobile = isMobileLayout();
    const drawerOpen = document.body.classList.contains('chat-sidebar-open');

    if (btnSidebarExpand) {
      // 桌面分栏：顶栏单一开关；手机全屏：打开时隐藏顶栏按钮
      btnSidebarExpand.hidden = mobile && drawerOpen;
      btnSidebarExpand.setAttribute(
        'aria-label',
        drawerOpen ? (mobile ? '打开菜单' : '关闭侧栏') : mobile ? '打开菜单' : '打开侧栏'
      );
      btnSidebarExpand.setAttribute('title', drawerOpen ? '关闭侧栏' : '打开侧栏');
    }

    if (btnSidebarCollapse) {
      // 桌面分栏不在侧栏内再放一个开关，避免与顶栏重复
      btnSidebarCollapse.hidden = !mobile || !drawerOpen;
      btnSidebarCollapse.setAttribute(
        'aria-label',
        mobile ? '关闭菜单' : '关闭侧栏'
      );
    }

    if (chatMain) {
      chatMain.classList.remove('sidebar-rail-visible');
    }
  }

  function setSidebarOpen(open) {
    const stagger = window.CampusStaggeredSidebar;

    if (stagger) {
      if (open) {
        stagger.open();
      } else {
        stagger.close();
      }
    } else {
      document.body.classList.toggle('chat-sidebar-open', !!open);
      const sidebar = document.getElementById('sidebar');
      const wrap = document.getElementById('staggered-sidebar');
      if (sidebar) sidebar.classList.toggle('is-open', !!open);
      if (wrap) wrap.classList.toggle('is-open', !!open);
    }

    if (sidebarBackdrop) {
      sidebarBackdrop.classList.remove('is-open');
      sidebarBackdrop.setAttribute('aria-hidden', 'true');
    }
    syncSidebarChrome();
  }

  function readSidebarCollapsedPref() {
    try {
      return localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1';
    } catch (_) {
      return false;
    }
  }

  function saveSidebarCollapsedPref(collapsed) {
    if (isMobileLayout()) return;
    try {
      if (collapsed) localStorage.setItem(SIDEBAR_COLLAPSED_KEY, '1');
      else localStorage.removeItem(SIDEBAR_COLLAPSED_KEY);
    } catch (_) {}
  }

  function applySidebarInitialState() {
    if (isMobileLayout()) {
      setSidebarOpen(false);
    } else {
      setSidebarOpen(!readSidebarCollapsedPref());
    }
    document.body.classList.remove('sidebar-collapsed');
    syncSidebarChrome();
  }

  function toggleSidebar() {
    const open = document.body.classList.contains('chat-sidebar-open');
    const nextOpen = !open;
    setSidebarOpen(nextOpen);
    saveSidebarCollapsedPref(!nextOpen);
  }

  function closeSidebar() {
    setSidebarOpen(false);
  }

  function closeSidebarIfMobile() {
    if (isMobileLayout()) {
      closeSidebar();
    }
  }

  sidebarBackdrop?.addEventListener('click', closeSidebar);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (deleteConvModal && !deleteConvModal.hidden) {
        closeDeleteConvModal();
        return;
      }
      closeSidebar();
    }
  });
  mqSidebar.addEventListener('change', function () {
    applySidebarInitialState();
  });

  btnSidebarCollapse?.addEventListener('click', function () {
    closeSidebar();
  });

  btnSidebarExpand?.addEventListener('click', function () {
    toggleSidebar();
  });

  function layoutComposerTools() {
    const tools = document.getElementById('composer-tools');
    const actions = document.querySelector('.composer__actions');
    const stripScroll = document.querySelector('.composer__strip-scroll');
    const stripModes = document.getElementById('composer-left-default');
    const sendBtn = document.getElementById('btn-send');
    if (!tools || !actions || !stripScroll || !sendBtn) return;

    if (isMobileLayout()) {
      const anchor = stripModes || stripScroll.firstChild;
      if (tools.parentElement !== stripScroll || tools.nextElementSibling !== anchor) {
        stripScroll.insertBefore(tools, anchor);
      }
    } else if (tools.parentElement !== actions || tools.nextElementSibling !== sendBtn) {
      actions.insertBefore(tools, sendBtn);
    }
  }

  layoutComposerTools();
  mqSidebar.addEventListener('change', onViewportLayoutChange);
  window.addEventListener('resize', onViewportLayoutChange, { passive: true });

  /* 搜索：由 common.js mountSearchModal + data-open-modal 处理 */

  placeWelcomeInMain();

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applySidebarInitialState);
  } else {
    applySidebarInitialState();
  }

  if (convList) {
    convList.addEventListener('click', function (e) {
      const del = e.target.closest('.conv-del');
      if (del) {
        e.preventDefault();
        e.stopPropagation();
        const btn = del.closest('.conv-item');
        if (btn && btn.dataset.id) {
          openDeleteConvModal(btn.dataset.id);
        }
        return;
      }
      const btn = e.target.closest('.conv-item');
      if (!btn || !btn.dataset.id) return;
      e.preventDefault();
      openConversation(parseInt(btn.dataset.id, 10), true);
    });
  }

  const CONV_SYNC_INTERVAL_MS = 45000;

  function startConvSyncTimer() {
    if (syncTimer) return;
    syncTimer = setInterval(function () {
      if (document.visibilityState !== 'visible') return;
      syncFromServer(true);
    }, CONV_SYNC_INTERVAL_MS);
  }

  function stopConvSyncTimer() {
    if (!syncTimer) return;
    clearInterval(syncTimer);
    syncTimer = null;
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      syncFromServer(true);
      startConvSyncTimer();
    } else {
      stopConvSyncTimer();
    }
  });

  if (document.visibilityState === 'visible') {
    startConvSyncTimer();
  }

  init();
  initDeepThinkToggle();
  initChatNotice();

  function shouldShowChatNotice() {
    if (!cfg.noticeKey || !chatNoticeModal) return false;
    try {
      if (sessionStorage.getItem(CHAT_NOTICE_SESSION_KEY) === cfg.noticeKey) {
        return false;
      }
      const raw = localStorage.getItem(CHAT_NOTICE_SNOOZE_KEY);
      if (raw) {
        const snooze = JSON.parse(raw);
        if (snooze && snooze.key === cfg.noticeKey && Number(snooze.until) > Date.now()) {
          return false;
        }
      }
      return true;
    } catch (_) {
      return true;
    }
  }

  function closeChatNoticeForSession() {
    if (!cfg.noticeKey) return;
    try {
      sessionStorage.setItem(CHAT_NOTICE_SESSION_KEY, cfg.noticeKey);
    } catch (_) {}
  }

  function snoozeChatNotice() {
    if (!cfg.noticeKey) return;
    try {
      localStorage.setItem(
        CHAT_NOTICE_SNOOZE_KEY,
        JSON.stringify({ key: cfg.noticeKey, until: Date.now() + CHAT_NOTICE_SNOOZE_MS })
      );
    } catch (_) {}
  }

  function openChatNoticeModal() {
    if (!chatNoticeModal) return;
    chatNoticeModal.hidden = false;
    document.body.classList.add('chat-notice-open');
  }

  function closeChatNoticeModal() {
    if (!chatNoticeModal) return;
    chatNoticeModal.hidden = true;
    document.body.classList.remove('chat-notice-open');
  }

  function initChatNotice() {
    if (!shouldShowChatNotice()) return;
    openChatNoticeModal();
  }

  chatNoticeClose?.addEventListener('click', function () {
    closeChatNoticeForSession();
    closeChatNoticeModal();
  });

  chatNoticeSnooze?.addEventListener('click', function () {
    snoozeChatNotice();
    closeChatNoticeModal();
  });

  chatNoticeModal?.querySelector('.chat-notice-backdrop')?.addEventListener('click', function () {
    closeChatNoticeForSession();
    closeChatNoticeModal();
  });

  function conversationPageUrl(id) {
    const base = String(cfg.chatPageUrl || '/chat.php').replace(/\?.*$/, '');
    const convId = parseInt(id, 10);
    if (!convId) return base;
    return base + '?id=' + encodeURIComponent(String(convId));
  }

  function syncAgentTopbarClear() {
    if (!btnAgentClearContext) return;
    const show = !!(activeAgentRef && activeAgentRef.type && activeAgentRef.id);
    btnAgentClearContext.hidden = !show;
  }

  function getActiveAgentDisplayName() {
    if (!activeAgentRef || !window.CampusChatAgents || !window.CampusChatAgents.getAgents) return '';
    const key = agentKeyFromRef(activeAgentRef);
    const list = window.CampusChatAgents.getAgents() || [];
    const found = list.find(function (a) {
      return agentKeyFromRef({ type: a.type, id: a.id }) === key;
    });
    return found ? String(found.display_name || '').trim() : '';
  }

  async function clearAgentContext() {
    if (!activeAgentRef || !activeAgentRef.type || !activeAgentRef.id) return;
    if (!conversationId) {
      history = [];
      attachments = [];
      imageAttachments = [];
      renderAttachments();
      clearComposerQuote();
      clearFollowUps();
      if (messagesEl) messagesEl.innerHTML = '';
      showWelcome({ force: true });
      showToast('已清空上下文');
      return;
    }
    if (isStreaming) {
      await abortActiveGeneration();
    }
    if (!confirm('确定清空与该智能体的上下文和聊天记录？此操作不可恢复。')) return;

    const agentName = getActiveAgentDisplayName();
    const res = await fetch(cfg.convUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'clear_messages',
        id: conversationId,
        title: agentName || undefined,
      }),
    });
    const parsed = await parseJsonResponse(res);
    if (!parsed.res.ok) {
      throw new Error((parsed.data && parsed.data.error) || '清空失败');
    }

    history = [];
    attachments = [];
    imageAttachments = [];
    renderAttachments();
    clearComposerQuote();
    clearFollowUps();
    if (messagesEl) messagesEl.innerHTML = '';
    showWelcome({ force: true });
    currentConvUpdatedAt = new Date().toISOString();
    await loadConversations();
    renderConvList();
    showToast('已清空上下文和聊天记录');
    promptEl.focus();
  }

  btnAgentClearContext?.addEventListener('click', function () {
    clearAgentContext().catch(function (err) {
      showToast(err.message || '清空失败');
    });
  });

  function agentPageUrl(agentRef) {
    const base = String(cfg.chatPageUrl || '/chat.php').replace(/\?.*$/, '');
    if (!agentRef || !agentRef.type || !agentRef.id) return base;
    return base + '?agent=' + encodeURIComponent(String(agentRef.type) + ':' + String(agentRef.id));
  }

  function syncPageUrl(nextPath) {
    if (!window.history || !window.history.replaceState) return;
    const current = window.location.pathname + window.location.search;
    if (current !== nextPath) {
      window.history.replaceState(null, '', nextPath);
    }
  }

  function syncConversationUrl(id) {
    syncPageUrl(conversationPageUrl(id).replace(/^https?:\/\/[^/]+/i, ''));
  }

  function syncAgentUrl(agentRef) {
    syncPageUrl(agentPageUrl(agentRef).replace(/^https?:\/\/[^/]+/i, ''));
  }

  function isAgentConversation(c) {
    return !!(c && c.agent_type && c.agent_id);
  }

  function isAgentModeActive() {
    return !!(activeAgentRef && activeAgentRef.type && activeAgentRef.id);
  }

  function userHasActiveChat() {
    return isAgentModeActive() || conversationId > 0 || isStreaming || history.length > 0;
  }

  async function fetchConversationDetail(convId) {
    const res = await fetch(cfg.convUrl + '?id=' + encodeURIComponent(convId), {
      credentials: 'same-origin',
    });
    const parsed = await parseJsonResponse(res);
    return { status: parsed.res.status, data: parsed.data, ok: parsed.res.ok };
  }

  async function syncCurrentConvTimestamp() {
    if (conversationId <= 0) return;
    const cur = conversations.find(function (c) {
      return String(c.id) === String(conversationId);
    });
    if (cur && cur.updated_at) {
      currentConvUpdatedAt = cur.updated_at;
      return;
    }
    const detail = await fetchConversationDetail(conversationId);
    if (detail && detail.ok && detail.data && detail.data.conversation && detail.data.conversation.updated_at) {
      currentConvUpdatedAt = detail.data.conversation.updated_at;
    }
  }

  async function recoverMissingActiveConversation(prevId) {
    if (isAgentModeActive()) {
      const detail = await fetchConversationDetail(prevId);
      if (detail && detail.ok && detail.data && detail.data.conversation) {
        const conv = detail.data.conversation;
        if (
          String(conv.agent_type) === String(activeAgentRef.type) &&
          String(conv.agent_id) === String(activeAgentRef.id)
        ) {
          const serverUpdated = conv.updated_at || '';
          if (serverUpdated && serverUpdated !== currentConvUpdatedAt) {
            await openConversation(prevId, true);
          } else {
            currentConvUpdatedAt = serverUpdated;
          }
          return;
        }
      }
      if (detail && detail.status === 404) {
        conversationId = 0;
        history = [];
        currentConvUpdatedAt = '';
        showWelcome({ force: true });
        syncAgentUrl(activeAgentRef);
        renderConvList();
      }
      return;
    }

    if (conversations.length) {
      await openConversation(conversations[0].id, true);
    } else {
      await createConversation(true, { silentExisting: true });
    }
  }

  async function init() {
    try {
      await loadModels();
      if (userHasActiveChat()) return;

      await loadConversations();
      if (userHasActiveChat()) return;

      if (cfg.initialAgent) {
        if (window.renderQuotaBars && cfg.quota) {
          window.renderQuotaBars(document.getElementById('sidebar-quota'), cfg.quota);
        }
        return;
      }
      const urlConvId = parseInt(cfg.initialConversationId, 10) || 0;
      if (urlConvId > 0) {
        if (userHasActiveChat()) return;
        const opened = await openConversation(urlConvId, true);
        if (opened !== false) {
          if (history.length === 0) showWelcome();
          if (window.renderQuotaBars && cfg.quota) {
            window.renderQuotaBars(document.getElementById('sidebar-quota'), cfg.quota);
          }
          return;
        }
      }
      if (userHasActiveChat()) return;
      const empty = findEmptyConversation(modelId);
      if (empty) {
        await openConversation(empty.id, true);
      } else if (conversations.length) {
        await openConversation(conversations[0].id, true);
      } else {
        await createConversation(true, { silentExisting: true });
      }
      if (history.length === 0) showWelcome();
      if (window.renderQuotaBars && cfg.quota) {
        window.renderQuotaBars(document.getElementById('sidebar-quota'), cfg.quota);
      }
    } catch (err) {
      showToast('对话加载失败：' + (err.message || String(err)));
    }
  }

  function syncModelPickerUi() {
    const picker = document.getElementById('modelPicker');
    const labelEl = document.getElementById('model-picker-label');
    if (!picker || !modelSelect) return;
    picker.innerHTML = '';
    models.forEach(function (m) {
      const item = document.createElement('div');
      item.className = 'c-popover__item' + (String(m.id) === String(modelId) ? ' is-active' : '');
      item.dataset.modelId = String(m.id);
      item.innerHTML =
        '<div class="c-popover__item__main"><div class="c-popover__item__title">' +
        escapeHtml(m.name) +
        '</div></div><span class="c-popover__item__check" data-icon="check"></span>';
      item.addEventListener('click', function () {
        modelId = parseInt(m.id, 10);
        modelSelect.value = String(modelId);
        syncModelPickerUi();
        if (labelEl) labelEl.textContent = m.name;
        picker.classList.remove('is-open');
      });
      picker.appendChild(item);
    });
    if (window.renderIcons) window.renderIcons(picker);
    const current = models.find(function (m) {
      return String(m.id) === String(modelId);
    });
    if (labelEl && current) labelEl.textContent = current.name;
  }

  function parseJsonResponse(res) {
    return res.text().then(function (text) {
      var data = {};
      try {
        data = text ? JSON.parse(text) : {};
      } catch (e) {
        var preview = String(text || '').replace(/\s+/g, ' ').trim().slice(0, 120);
        throw new Error(
          preview.indexOf('<') === 0
            ? '服务器返回异常页面，请刷新后重试或联系管理员'
            : '服务器响应无效，请稍后重试'
        );
      }
      return { res: res, data: data };
    });
  }

  async function loadModels() {
    const res = await fetch(cfg.modelsUrl, { credentials: 'same-origin' });
    const parsed = await parseJsonResponse(res);
    const data = parsed.data;
    if (!parsed.res.ok) {
      throw new Error(data.error || '加载模型失败');
    }
    models = data.models || [];
    if (modelSelect) modelSelect.innerHTML = '';
    models.forEach((m) => {
      const opt = document.createElement('option');
      opt.value = m.id;
      opt.textContent = m.name;
      modelSelect?.appendChild(opt);
    });
    if (models.length) {
      modelId = parseInt(models[0].id, 10);
      if (modelSelect) modelSelect.value = String(modelId);
    }
    syncModelPickerUi();
    modelSelect?.addEventListener('change', function () {
      modelId = parseInt(modelSelect.value, 10) || modelId;
      syncModelPickerUi();
    });
  }

  async function loadConversations() {
    const res = await fetch(cfg.convUrl, { credentials: 'same-origin' });
    const parsed = await parseJsonResponse(res);
    const data = parsed.data;
    if (!parsed.res.ok) return false;
    conversations = data.conversations || [];
    renderConvList();
    return true;
  }

  function setSyncIndicator(active) {
    if (!syncIndicator) return;
    syncIndicator.classList.toggle('is-active', !!active);
  }

  function hasVisibleChat() {
    if (isStreaming) return true;
    if (history.length > 0) return true;
    return !!(messagesEl && messagesEl.querySelector('.msg'));
  }

  function syncChatEmptyState() {
    if (!chatMain) return;
    const empty = !hasVisibleChat();
    chatMain.classList.toggle('is-empty', empty);
    if (empty) {
      placeWelcomeInMain();
    }
  }

  function restoreChatFromHistoryIfNeeded() {
    if (!messagesEl || !history.length) return;
    if (messagesEl.querySelector('.msg')) return;
    renderAllMessages();
  }

  function onViewportLayoutChange() {
    layoutComposerTools();
    syncChatEmptyState();
    restoreChatFromHistoryIfNeeded();
  }

  function placeWelcomeInMain() {
    if (!emptyHero || !chatMain) return;
    const streamWrap = document.getElementById('messages-wrap');
    if (emptyHero.parentElement === chatMain) return;
    if (streamWrap && streamWrap.parentElement === chatMain) {
      chatMain.insertBefore(emptyHero, streamWrap);
    } else {
      chatMain.insertBefore(emptyHero, chatMain.querySelector('.composer'));
    }
  }

  function setEmptyState(on) {
    if (on && hasVisibleChat()) {
      on = false;
    }
    if (chatMain) chatMain.classList.toggle('is-empty', !!on);
    if (on) placeWelcomeInMain();
  }

  function isEmptyConversation(c) {
    if (!c) return false;
    return c.message_count != null && Number(c.message_count) === 0;
  }

  function findEmptyConversation(preferModelId) {
    const mid = preferModelId || modelId;
    let found = conversations.find(function (c) {
      return isEmptyConversation(c) && !isAgentConversation(c) && String(c.model_id) === String(mid);
    });
    if (found) return found;
    return conversations.find(function (c) {
      return isEmptyConversation(c) && !isAgentConversation(c);
    }) || null;
  }

  async function syncFromServer(silent) {
    if (isStreaming || convNavInFlight > 0) return;
    const prevId = conversationId;
    const prevUpdated = currentConvUpdatedAt;
    const prevListLen = conversations.length;

    setSyncIndicator(true);

    try {
      const ok = await loadConversations();
      if (!ok) return;

      if (prevId <= 0) {
        if (conversations.length > prevListLen) renderConvList();
        return;
      }

      const meta = conversations.find(function (c) {
        return String(c.id) === String(prevId);
      });
      if (!meta) {
        await recoverMissingActiveConversation(prevId);
        return;
      }
      if (meta.updated_at && meta.updated_at !== prevUpdated) {
        const prevHistory = history.slice();
        await openConversation(prevId, true);
        if (history.length === 0 && prevHistory.length > 0) {
          history = prevHistory;
          renderAllMessages();
        }
        return;
      }

      if (conversations.length > prevListLen) renderConvList();
    } catch (e) {
      /* silent auto-sync */
    } finally {
      setSyncIndicator(false);
    }
  }

  function updateConversationTitle(id, title) {
    if (!title) return;
    conversations.forEach(function (c) {
      if (String(c.id) === String(id)) {
        c.title = title;
      }
    });
    renderConvList();
  }

  function renderConvList() {
    if (!convList) return;
    convList.innerHTML = '';
    const q = convSearchQuery.trim().toLowerCase();
    const regularConversations = conversations.filter(function (c) {
      return !isAgentConversation(c);
    });
    const list = q
      ? regularConversations.filter(function (c) {
          return String(c.title || '新对话')
            .toLowerCase()
            .includes(q);
        })
      : regularConversations;
    if (list.length === 0) {
      const li = document.createElement('li');
      li.className = 'conv-empty-hint';
      li.textContent = q ? '无匹配对话' : '暂无对话';
      convList.appendChild(li);
      return;
    }
    list.forEach((c) => {
      const li = document.createElement('li');
      const btn = document.createElement('button');
      btn.type = 'button';
      const isActive =
        !isAgentModeActive() && String(c.id) === String(conversationId);
      btn.className = 'conv-item' + (isActive ? ' active' : '');
      btn.dataset.id = c.id;
      btn.innerHTML =
        '<span class="conv-title">' +
        escapeHtml(c.title || '新对话') +
        '</span><span class="conv-del" title="删除">×</span>';
      li.appendChild(btn);
      convList.appendChild(li);
    });
    if (window.CampusStaggeredSidebar && window.CampusStaggeredSidebar.isOpen()) {
      window.CampusStaggeredSidebar.refreshItems();
    }
  }

  /** 新建对话（可选是否清空界面） */
  async function createConversation(clearUi, options) {
    options = options || {};
    const res = await fetch(cfg.convUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'create',
        model_id: modelId,
        agent: activeAgentRef,
      }),
    });
    const parsed = await parseJsonResponse(res);
    const data = parsed.data;
    if (!parsed.res.ok) throw new Error(data.error || '创建失败');

    if (data.existing) {
      await openConversation(parseInt(data.id, 10), clearUi);
      if (!options.silentExisting) {
        showToast('你已经创建了一个新对话');
      }
      return { existing: true };
    }

    conversationId = parseInt(data.id, 10);
    modelId = data.model_id || modelId;
    modelSelect.value = String(modelId);
    history = [];
    attachments = [];
    imageAttachments = [];
    renderAttachments();
    if (clearUi) {
      showWelcome();
    }
    if (window.CampusChatMedia) window.CampusChatMedia.exitMediaMode();
    if (window.CampusComposerCode) window.CampusComposerCode.exitCodeMode();
    if (window.CampusCodeWorkspace) window.CampusCodeWorkspace.close();
    syncConversationUrl(conversationId);
    await loadConversations();
    const cur = conversations.find(function (c) {
      return String(c.id) === String(conversationId);
    });
    if (cur && cur.updated_at) {
      currentConvUpdatedAt = cur.updated_at;
    }
    renderConvList();
    return { existing: false };
  }

  async function ensureConversation() {
    if (conversationId > 0) return;
    await createConversation(history.length === 0);
  }

  async function openConversation(id, clearUi) {
    const navId = ++convNavSeq;
    convNavInFlight++;
    try {
      if (isStreaming) {
        await abortActiveGeneration();
      }
      if (navId !== convNavSeq) return false;

      const res = await fetch(cfg.convUrl + '?id=' + encodeURIComponent(id), {
        credentials: 'same-origin',
      });
      const parsed = await parseJsonResponse(res);
      const data = parsed.data;
      if (navId !== convNavSeq) return false;

      if (parsed.res.status === 404) {
        conversations = conversations.filter(function (c) {
          return String(c.id) !== String(id);
        });
        renderConvList();
        if (String(conversationId) === String(id)) {
          conversationId = 0;
          currentConvUpdatedAt = '';
        }
        if (clearUi !== false) {
          if (isAgentModeActive()) {
            history = [];
            showWelcome({ force: true });
            syncAgentUrl(activeAgentRef);
            return false;
          }
          showToast('该对话已不存在，正在打开其他对话');
          if (conversations.length) {
            await openConversation(conversations[0].id, true);
          } else {
            await createConversation(true, { silentExisting: true });
          }
        }
        return false;
      }
      if (!parsed.res.ok) return false;

      conversationId = parseInt(data.conversation.id, 10);
      currentConvUpdatedAt = data.conversation.updated_at || '';
      clearComposerQuote();
      if (window.CampusChatMedia) window.CampusChatMedia.exitMediaMode();
      if (window.CampusComposerCode) window.CampusComposerCode.exitCodeMode();
      if (window.CampusCodeWorkspace) window.CampusCodeWorkspace.close();
      if (data.conversation.model_id) {
        modelId = parseInt(data.conversation.model_id, 10);
        modelSelect.value = String(modelId);
      }
      if (navId !== convNavSeq) return false;

      if (window.CampusChatAgents && window.CampusChatAgents.syncFromConversation) {
        window.CampusChatAgents.syncFromConversation(data);
        if (data.agent && isAgentModeActive()) {
          activeAgentProfile = data.agent;
        }
      } else if (data.agent && data.agent.type && data.agent.id) {
        activeAgentRef = { type: data.agent.type, id: data.agent.id };
        activeAgentProfile = data.agent;
      } else if (data.conversation.agent_type && data.conversation.agent_id) {
        activeAgentRef = {
          type: data.conversation.agent_type,
          id: parseInt(data.conversation.agent_id, 10),
        };
        if (data.agent) activeAgentProfile = data.agent;
      } else {
        activeAgentRef = null;
        activeAgentProfile = null;
        if (window.CampusChatAgents && window.CampusChatAgents.clearActiveAgent) {
          window.CampusChatAgents.clearActiveAgent();
        }
      }
      syncAgentTopbarClear();
      syncModelPickerUi();

      history = (data.messages || []).map((m) => ({
        role: m.role,
        content: m.content,
      }));

      if (navId !== convNavSeq) return false;

      if (clearUi !== false) {
        renderAllMessages();
      } else {
        syncChatEmptyState();
      }
      renderConvList();
      scrollMessagesToBottom();
      closeSidebarIfMobile();
      if (activeAgentRef && activeAgentRef.type && activeAgentRef.id) {
        syncAgentUrl(activeAgentRef);
      } else {
        syncConversationUrl(conversationId);
      }
      return true;
    } finally {
      convNavInFlight--;
    }
  }

  function openDeleteConvModal(id) {
    if (!deleteConvModal) return;
    pendingDeleteConvId = id;
    deleteConvModal.hidden = false;
    document.body.classList.add('delete-conv-open');
    deleteConvConfirm?.focus();
  }

  function closeDeleteConvModal() {
    if (!deleteConvModal) return;
    deleteConvModal.hidden = true;
    document.body.classList.remove('delete-conv-open');
    pendingDeleteConvId = null;
  }

  async function performDeleteConversation(id) {
    await fetch(cfg.convUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', id }),
    });
    if (String(conversationId) === String(id)) {
      conversationId = 0;
      await loadConversations();
      if (conversations.length) await openConversation(conversations[0].id, true);
      else await createConversation(true, { silentExisting: true });
    } else {
      await loadConversations();
    }
  }

  async function deleteConversation(id) {
    openDeleteConvModal(id);
  }

  deleteConvCancel?.addEventListener('click', closeDeleteConvModal);
  deleteConvModal?.querySelector('.upload-notice-backdrop')?.addEventListener('click', closeDeleteConvModal);
  deleteConvConfirm?.addEventListener('click', async function () {
    const id = pendingDeleteConvId;
    if (!id) return;
    closeDeleteConvModal();
    await performDeleteConversation(id);
  });

  function getModelLabel() {
    const m = models.find(function (x) {
      return parseInt(x.id, 10) === modelId;
    });
    return m ? m.name : 'AI';
  }

  function getUserInitials() {
    const name = String(cfg.userName || '').trim();
    if (!name) return '我';
    return name.slice(0, 1);
  }

  function getActiveAgentPublic() {
    if (!activeAgentRef || !window.CampusChatAgents || !window.CampusChatAgents.getAgents) {
      return null;
    }
    const key = agentKeyFromRef(activeAgentRef);
    return (window.CampusChatAgents.getAgents() || []).find(function (a) {
      return agentKeyFromRef({ type: a.type, id: a.id }) === key;
    }) || null;
  }

  function buildMsgAvatarHtml(role) {
    if (role === 'user') {
      return (
        '<div class="msg__avatar msg-avatar msg__avatar--user" aria-hidden="true">' +
        escapeHtml(getUserInitials()) +
        '</div>'
      );
    }
    const agent = getActiveAgentPublic();
    if (agent && agent.avatar_url) {
      return (
        '<div class="msg__avatar msg-avatar msg__avatar--ai msg-avatar--img" aria-hidden="true">' +
        '<img src="' + escapeHtml(agent.avatar_url) + '" alt="">' +
        '</div>'
      );
    }
    const label =
      agent && agent.display_name
        ? escapeHtml(String(agent.display_name).trim().slice(0, 1))
        : svgIcon('sparkles', 14);
    return (
      '<div class="msg__avatar msg-avatar msg__avatar--ai" aria-hidden="true">' + label + '</div>'
    );
  }

  function buildMsgHeadHtml(role) {
    return buildMsgAvatarHtml(role);
  }

  function ensureMsgAvatarOnWrap(wrap, role) {
    if (!wrap || wrap.querySelector('.msg__avatar, .msg-avatar')) return;
    wrap.insertAdjacentHTML('afterbegin', buildMsgAvatarHtml(role));
  }

  function buildAssistantStreamingInner(showReasoning) {
    const thinking =
      showReasoning !== false
        ? buildThinkingBlockHtml({ active: true, reasoning: '', collapsed: true })
        : '';
    return (
      thinking +
      '<div class="ai-response-host is-empty">' +
      '<span class="ai-stream-wait" aria-hidden="true"><span></span><span></span><span></span></span>' +
      '</div>'
    );
  }

  async function openAgentChat(agent) {
    if (!agent || !agent.type || !agent.id) return;
    ++convNavSeq;
    closeSidebarIfMobile();

    const sameAgent =
      activeAgentRef &&
      activeAgentRef.type === agent.type &&
      activeAgentRef.id === agent.id;

    activeAgentRef = { type: agent.type, id: agent.id };
    activeAgentProfile = agent;
    if (agent.model_id) {
      modelId = parseInt(agent.model_id, 10);
      if (modelSelect) modelSelect.value = String(modelId);
      syncModelPickerUi();
    }
    if (window.CampusChatAgents && window.CampusChatAgents.setActiveAgentUI) {
      window.CampusChatAgents.setActiveAgentUI(agent);
    }
    syncAgentTopbarClear();

    const res = await fetch(cfg.convUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'create',
        model_id: modelId,
        agent: { type: agent.type, id: agent.id },
      }),
    });
    const parsed = await parseJsonResponse(res);
    const data = parsed.data;
    if (!parsed.res.ok) throw new Error(data.error || '打开智能体对话失败');

    const convId = parseInt(data.id, 10);
    agent.conversation_id = convId;
    if (window.CampusChatAgents && window.CampusChatAgents.setAgentConversationId) {
      window.CampusChatAgents.setAgentConversationId(agentKeyFromRef(agent), convId);
    }

    if (
      sameAgent &&
      String(conversationId) === String(convId) &&
      history.length > 0
    ) {
      syncAgentUrl(activeAgentRef);
      removeWelcome();
      promptEl.focus();
      return;
    }

    await openConversation(convId, true);
    if (history.length === 0) showWelcome();
    else removeWelcome();
    promptEl.focus();
  }

  function agentKeyFromRef(ref) {
    if (!ref || !ref.type || !ref.id) return '';
    return String(ref.type) + ':' + String(ref.id);
  }

  async function startNewChatWithAgent(agent) {
    await openAgentChat(agent);
  }

  async function startNewChatWithCurrentModel() {
    if (isStreaming) {
      await abortActiveGeneration();
    }
    ++convNavSeq;
    closeSidebarIfMobile();
    activeAgentRef = null;
    activeAgentProfile = null;
    if (window.CampusChatAgents && window.CampusChatAgents.clearActiveAgent) {
      window.CampusChatAgents.clearActiveAgent();
    }
    syncAgentTopbarClear();

    const empty = findEmptyConversation(modelId);
    if (empty) {
      if (String(empty.id) !== String(conversationId)) {
        await openConversation(empty.id, true);
      } else {
        showWelcome();
        syncConversationUrl(conversationId);
      }
      showToast('你已经创建了一个新对话');
      promptEl.focus();
      return;
    }

    await createConversation(true);
    showWelcome();
    syncConversationUrl(conversationId);
    promptEl.focus();
  }

  modelSelect.addEventListener('change', async () => {
    const nextId = parseInt(modelSelect.value, 10);
    if (!nextId || nextId === modelId) return;
    modelId = nextId;
    closeSidebarIfMobile();
    await startNewChatWithCurrentModel();
  });

  btnNew.addEventListener('click', startNewChatWithCurrentModel);
  btnModelNew?.addEventListener('click', startNewChatWithCurrentModel);

  function shouldShowUploadNotice() {
    try {
      return localStorage.getItem(UPLOAD_NOTICE_KEY) !== '1';
    } catch (_) {
      return true;
    }
  }

  function setUploadNoticeSkip() {
    try {
      localStorage.setItem(UPLOAD_NOTICE_KEY, '1');
    } catch (_) {}
  }

  function openUploadNoticeModal() {
    if (!uploadNoticeModal) return;
    uploadNoticeModal.hidden = false;
    document.body.classList.add('upload-notice-open');
  }

  function closeUploadNoticeModal() {
    if (!uploadNoticeModal) return;
    uploadNoticeModal.hidden = true;
    document.body.classList.remove('upload-notice-open');
  }

  function beginFilePick() {
    if (!fileInput) return;
    fileInput.click();
  }

  btnAttach?.addEventListener('click', () => {
    if (shouldShowUploadNotice()) {
      openUploadNoticeModal();
    } else {
      beginFilePick();
    }
  });

  uploadNoticeOk?.addEventListener('click', () => {
    closeUploadNoticeModal();
    beginFilePick();
  });

  uploadNoticeSkip?.addEventListener('click', () => {
    setUploadNoticeSkip();
    closeUploadNoticeModal();
    beginFilePick();
  });

  fileInput?.addEventListener('change', () => {
    const file = fileInput.files?.[0];
    fileInput.value = '';
    if (!file) return;
    if (file.type && file.type.startsWith('image/')) {
      uploadImageAttachment(file).catch(function (err) {
        showToast((err && err.message) || '图片上传失败');
      });
    } else {
      uploadFileWithProgress(file).catch(() => {});
    }
  });

  promptEl?.addEventListener('paste', function (e) {
    const items = e.clipboardData && e.clipboardData.items;
    if (!items || !items.length) return;
    for (let i = 0; i < items.length; i++) {
      const item = items[i];
      if (item.kind === 'file' && item.type && item.type.startsWith('image/')) {
        const file = item.getAsFile();
        if (!file) continue;
        e.preventDefault();
        uploadImageAttachment(file).catch(function (err) {
          showToast((err && err.message) || '图片粘贴失败');
        });
        return;
      }
    }
  });

  /* ─── 语音转文字 ───────────────────────────────────────────────────────── */
  const btnVoice = document.getElementById('btn-voice');
  const voiceRecorder = document.getElementById('voice-recorder');
  const voiceCancel = document.getElementById('voice-cancel');
  const voiceConfirm = document.getElementById('voice-confirm');
  const voiceWave = document.getElementById('voice-wave');
  const voiceTimer = document.getElementById('voice-timer');
  const composerEl = document.getElementById('chat-form');
  const SpeechRecognition =
    window.SpeechRecognition || window.webkitSpeechRecognition || null;

  let voiceActive = false;
  let voiceRecognition = null;
  let voiceFinalText = '';
  let voiceInterimText = '';
  let voiceStartedAt = 0;
  let voiceTimerId = null;
  let voiceWaveRaf = null;
  let voiceMicStream = null;
  let voiceAudioCtx = null;
  let voiceAnalyser = null;
  let voiceWaveBars = [];

  function initVoiceWaveBars() {
    if (!voiceWave || voiceWaveBars.length) return;
    const count = 28;
    for (let i = 0; i < count; i++) {
      const bar = document.createElement('span');
      bar.className = 'voice-wave-bar';
      voiceWave.appendChild(bar);
      voiceWaveBars.push(bar);
    }
  }

  function formatVoiceTime(sec) {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return m + ':' + String(s).padStart(2, '0');
  }

  function updateVoiceTimer() {
    if (!voiceTimer || !voiceStartedAt) return;
    const sec = Math.floor((Date.now() - voiceStartedAt) / 1000);
    voiceTimer.textContent = formatVoiceTime(sec);
  }

  function animateVoiceWave() {
    if (!voiceActive || !voiceWaveBars.length) return;
    let levels = [];
    if (voiceAnalyser) {
      const data = new Uint8Array(voiceAnalyser.frequencyBinCount);
      voiceAnalyser.getByteFrequencyData(data);
      const step = Math.max(1, Math.floor(data.length / voiceWaveBars.length));
      for (let i = 0; i < voiceWaveBars.length; i++) {
        levels.push(data[i * step] || 0);
      }
    } else {
      levels = voiceWaveBars.map(function (_, i) {
        return 40 + Math.sin(Date.now() / 120 + i * 0.45) * 35 + Math.random() * 25;
      });
    }
    voiceWaveBars.forEach(function (bar, i) {
      const v = levels[i] || 0;
      const h = Math.max(6, Math.min(32, 6 + (v / 255) * 26));
      bar.style.height = h + 'px';
      bar.classList.toggle('is-active', v > 30);
    });
    voiceWaveRaf = requestAnimationFrame(animateVoiceWave);
  }

  async function startVoiceMicLevel() {
    if (!navigator.mediaDevices?.getUserMedia) return;
    try {
      voiceMicStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      voiceAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
      const src = voiceAudioCtx.createMediaStreamSource(voiceMicStream);
      voiceAnalyser = voiceAudioCtx.createAnalyser();
      voiceAnalyser.fftSize = 128;
      src.connect(voiceAnalyser);
    } catch (_) {
      /* 仅无波形，识别仍可用 */
    }
  }

  function stopVoiceMicLevel() {
    if (voiceWaveRaf) {
      cancelAnimationFrame(voiceWaveRaf);
      voiceWaveRaf = null;
    }
    if (voiceMicStream) {
      voiceMicStream.getTracks().forEach(function (t) {
        t.stop();
      });
      voiceMicStream = null;
    }
    if (voiceAudioCtx) {
      voiceAudioCtx.close().catch(function () {});
      voiceAudioCtx = null;
    }
    voiceAnalyser = null;
    voiceWaveBars.forEach(function (bar) {
      bar.style.height = '6px';
      bar.classList.remove('is-active');
    });
  }

  function showVoiceRecorder() {
    initVoiceWaveBars();
    if (composerEl) composerEl.classList.add('is-voice-active');
    if (voiceRecorder) voiceRecorder.hidden = false;
    if (btnVoice) btnVoice.classList.add('is-recording');
    voiceStartedAt = Date.now();
    updateVoiceTimer();
    voiceTimerId = window.setInterval(updateVoiceTimer, 500);
    animateVoiceWave();
  }

  function hideVoiceRecorder() {
    voiceActive = false;
    if (composerEl) composerEl.classList.remove('is-voice-active');
    if (voiceRecorder) voiceRecorder.hidden = true;
    if (btnVoice) btnVoice.classList.remove('is-recording');
    if (voiceTimerId) {
      clearInterval(voiceTimerId);
      voiceTimerId = null;
    }
    if (voiceTimer) voiceTimer.textContent = '0:00';
    stopVoiceMicLevel();
  }

  function getVoiceTranscript() {
    return (voiceFinalText + voiceInterimText).trim();
  }

  function rebuildVoiceTranscriptFromEvent(e) {
    let fin = '';
    let interim = '';
    const results = e && e.results ? e.results : [];
    for (let i = 0; i < results.length; i++) {
      const t = (results[i][0] && results[i][0].transcript) || '';
      if (results[i].isFinal) {
        fin += t;
      } else {
        interim += t;
      }
    }
    voiceFinalText = fin;
    voiceInterimText = interim;
  }

  function stopVoiceRecognition(abort) {
    const rec = voiceRecognition;
    voiceRecognition = null;
    if (!rec) return;
    try {
      rec.onend = null;
      rec.onerror = null;
      rec.onresult = null;
      if (abort) rec.abort();
      else rec.stop();
    } catch (_) {
      try {
        rec.abort();
      } catch (__) {}
    }
  }

  function applyVoiceTranscript() {
    stopVoiceMicLevel();
    hideVoiceRecorder();
    const text = getVoiceTranscript();
    voiceFinalText = '';
    voiceInterimText = '';
    if (text) {
      const cur = promptEl.value.trim();
      promptEl.value = cur ? cur + (cur.endsWith('\n') ? '' : '\n') + text : text;
      promptEl.dispatchEvent(new Event('input'));
      promptEl.focus();
    } else {
      showToast('未识别到语音，请对着麦克风清晰说话后点确认');
    }
  }

  let voiceConfirmTimer = null;

  async function startVoiceInput() {
    if (isStreaming) {
      showToast('请等待 AI 回复完成');
      return;
    }
    if (!SpeechRecognition) {
      showToast('当前浏览器不支持语音输入，请用 Chrome / Safari 打开');
      return;
    }
    if (voiceActive) return;

    voiceFinalText = '';
    voiceInterimText = '';
    voiceActive = true;
    showVoiceRecorder();

    const isIos = /iPhone|iPad|iPod/i.test(navigator.userAgent);
    if (!isMobileLayout() && !isIos) {
      await startVoiceMicLevel();
    }

    voiceRecognition = new SpeechRecognition();
    voiceRecognition.lang = 'zh-CN';
    voiceRecognition.continuous = !isIos;
    voiceRecognition.interimResults = true;
    voiceRecognition.maxAlternatives = 1;

    voiceRecognition.onresult = function (e) {
      rebuildVoiceTranscriptFromEvent(e);
    };

    voiceRecognition.onerror = function (e) {
      if (!voiceActive) return;
      if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
        showToast('请允许使用麦克风');
        cancelVoiceInput();
      } else if (e.error === 'no-speech') {
        /* 静音时不打断，用户可继续说 */
      } else if (e.error !== 'aborted') {
        showToast('语音识别：' + (e.error || '未知'));
      }
    };

    voiceRecognition.onend = function () {
      if (voiceActive && voiceRecognition) {
        try {
          voiceRecognition.start();
        } catch (_) {}
      }
    };

    try {
      voiceRecognition.start();
    } catch (err) {
      showToast('无法启动语音识别，请检查麦克风权限');
      cancelVoiceInput();
    }
  }

  function cancelVoiceInput() {
    voiceActive = false;
    if (voiceConfirmTimer) {
      clearTimeout(voiceConfirmTimer);
      voiceConfirmTimer = null;
    }
    stopVoiceRecognition(true);
    voiceFinalText = '';
    voiceInterimText = '';
    hideVoiceRecorder();
  }

  function confirmVoiceInput() {
    if (!voiceActive && !getVoiceTranscript()) {
      hideVoiceRecorder();
      return;
    }
    voiceActive = false;
    if (voiceConfirmTimer) {
      clearTimeout(voiceConfirmTimer);
      voiceConfirmTimer = null;
    }

    const rec = voiceRecognition;
    if (!rec) {
      applyVoiceTranscript();
      return;
    }

    rec.onend = function () {
      voiceRecognition = null;
      applyVoiceTranscript();
    };
    rec.onerror = function () {};

    try {
      rec.stop();
    } catch (_) {
      voiceRecognition = null;
      applyVoiceTranscript();
      return;
    }

    voiceConfirmTimer = window.setTimeout(function () {
      voiceConfirmTimer = null;
      if (voiceRecognition === rec) {
        voiceRecognition = null;
        applyVoiceTranscript();
      }
    }, 600);
  }

  btnVoice?.addEventListener('click', function () {
    if (voiceActive) return;
    startVoiceInput();
  });

  voiceCancel?.addEventListener('click', cancelVoiceInput);
  voiceConfirm?.addEventListener('click', confirmVoiceInput);

  promptEl.addEventListener('input', () => {
    promptEl.style.height = 'auto';
    promptEl.style.height = Math.min(promptEl.scrollHeight, 220) + 'px';
  });

  promptEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      form.requestSubmit();
    }
  });

  function clearComposerQuote() {
    pendingQuote = null;
    if (composerQuoteEl) composerQuoteEl.hidden = true;
    if (composerQuoteText) composerQuoteText.textContent = '';
    if (composerQuoteThumb) {
      composerQuoteThumb.hidden = true;
      composerQuoteThumb.removeAttribute('src');
    }
  }

  function renderComposerQuote() {
    if (!composerQuoteEl || !pendingQuote) {
      clearComposerQuote();
      return;
    }
    composerQuoteEl.hidden = false;
    const preview =
      pendingQuote.type === 'image' && pendingQuote.text
        ? pendingQuote.text
        : pendingQuote.text || (pendingQuote.type === 'image' ? '引用图片进行图生图修改' : '');
    if (composerQuoteText) {
      composerQuoteText.textContent = preview.slice(0, 280);
    }
    if (composerQuoteThumb) {
      if (pendingQuote.imageUrl) {
        composerQuoteThumb.hidden = false;
        composerQuoteThumb.src = pendingQuote.imageUrl;
      } else {
        composerQuoteThumb.hidden = true;
        composerQuoteThumb.removeAttribute('src');
      }
    }
  }

  function extractImageUrlFromBubble(bubble, rawContent) {
    const img = bubble.querySelector('.msg-media-card--image img, .msg-media-thumb img, .md-body img');
    if (img && img.src) return img.src;
    const m = String(rawContent || '').match(/!\[[^\]]*\]\(([^)\s]+)\)/);
    return m ? m[1] : '';
  }

  function plainTextFromContent(rawContent, role) {
    let t = String(rawContent || '');
    if (role === 'user') t = formatUserStoredContent(t);
    t = t.replace(/!\[[^\]]*\]\([^)]+\)/g, '[图片]');
    t = t.replace(/\[(?:🎬\s*)?生成视频\]\([^)]+\)/g, '[视频]');
    t = t.replace(/^>\s?/gm, '');
    t = t.replace(/\n{3,}/g, '\n\n').trim();
    return t;
  }

  function applyQuoteFromMessage(bubble, rawContent, role) {
    const imageUrl = extractImageUrlFromBubble(bubble, rawContent);
    const text = plainTextFromContent(rawContent, role);

    if (imageUrl && cfg.enableImage !== false && window.CampusChatMedia) {
      window.CampusChatMedia.addMediaRefFromUrl(imageUrl, '引用图片');
      window.CampusChatMedia.enterMediaMode('image');
      pendingQuote = { type: 'image', text: text, imageUrl: imageUrl, role: role };
      renderComposerQuote();
      showToast('已引用图片，输入修改需求即可图生图');
    } else if (text) {
      pendingQuote = { type: 'text', text: text, role: role };
      renderComposerQuote();
      showToast('已引用，可在下方继续提问或续写');
    } else {
      showToast('无法引用该内容');
      return;
    }
    promptEl.focus();
  }

  function buildMessageWithQuote(text) {
    if (!pendingQuote || pendingQuote.type !== 'text' || !pendingQuote.text) {
      return text;
    }
    const q = pendingQuote.text.trim();
    if (!q) return text;
    const body = String(text || '').trim();
    if (!body) {
      return '> ' + q.replace(/\n/g, '\n> ');
    }
    return '> ' + q.replace(/\n/g, '\n> ') + '\n\n' + body;
  }

  composerQuoteClose?.addEventListener('click', clearComposerQuote);

  window.CampusChatQuote = {
    fromImage: function (src, alt) {
      if (!src) return;
      if (cfg.enableImage !== false && window.CampusChatMedia) {
        window.CampusChatMedia.addMediaRefFromUrl(src, alt || '引用图片');
        window.CampusChatMedia.enterMediaMode('image');
      }
      pendingQuote = { type: 'image', text: alt || '', imageUrl: src, role: 'assistant' };
      renderComposerQuote();
      showToast('已引用图片，输入修改需求即可图生图');
      promptEl.focus();
    },
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (isStreaming) {
      await abortActiveGeneration();
      return;
    }
    const text = promptEl.value.trim();
    const quotedText = buildMessageWithQuote(text);
    const fullText = buildMessageWithFiles(quotedText);
    if (!fullText || !modelId) return;
    if (hasPendingGeneration()) {
      await abortActiveGeneration({ partial: '' });
    }

    await ensureConversation();
    removeWelcome();
    clearFollowUps();
    scrollMessagesToBottom();

    const displayText = formatUserDisplay(quotedText, attachments, imageAttachments);
    appendMessage('user', displayText);
    scrollMessagesToBottom();
    history.push({ role: 'user', content: fullText });
    promptEl.value = '';
    promptEl.style.height = 'auto';
    clearComposerQuote();
    attachments = [];
    imageAttachments = [];
    renderAttachments();

    const assistantWrap = appendMessage('assistant', '', true);
    scrollMessagesToBottom();
    const assistantBubble = assistantWrap.querySelector('.msg-body');
    activeStreamBubble = assistantBubble;
    activeStreamReasoning = '';
    activeStreamContent = '';
    chatStreamAbort = new AbortController();
    setLoading(true);
    isStreaming = true;
    stickToBottom = true;

    try {
      await streamChat(assistantBubble, { signal: chatStreamAbort.signal });
      await loadConversations();
      await syncCurrentConvTimestamp();
      showFollowUpsForAssistantWrap(assistantWrap);
    } catch (err) {
      if (err && err.name === 'AbortError') {
        showToast('已停止生成');
      } else {
        setBubbleText(assistantBubble, '请求失败：' + (err.message || String(err)));
      }
    } finally {
      chatStreamAbort = null;
      activeStreamBubble = null;
      activeStreamReasoning = '';
      activeStreamContent = '';
      isStreaming = false;
      setLoading(false);
      if (stickToBottom) {
        scrollMessagesToBottom(true);
      } else {
        updateScrollBottomButton();
      }
    }
  });

  btnSend?.addEventListener('click', async function (e) {
    if (!isStreaming) return;
    e.preventDefault();
    e.stopPropagation();
    await abortActiveGeneration();
    showToast('已停止生成');
  });

  function clearFollowUps() {
    document.querySelectorAll('.chat-followups').forEach(function (el) {
      el.remove();
    });
  }

  function renderFollowUpsForMessage(wrap, questions) {
    if (!wrap || !questions || !questions.length) return;
    wrap.querySelector('.chat-followups')?.remove();
    const block = document.createElement('div');
    block.className = 'chat-followups';
    block.innerHTML =
      '<div class="chat-followups__title">追问</div><ul class="chat-followups__list"></ul>';
    const list = block.querySelector('.chat-followups__list');
    questions.forEach(function (q) {
      const li = document.createElement('li');
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'chat-followups__item';
      btn.textContent = q;
      btn.addEventListener('click', function () {
        if (isStreaming) return;
        promptEl.value = q;
        clearFollowUps();
        form.requestSubmit();
      });
      li.appendChild(btn);
      list.appendChild(li);
    });
    wrap.appendChild(block);
    scrollMessagesToBottom();
  }

  async function fetchFollowUps(userText, assistantText) {
    const url = cfg.followupsUrl;
    if (!url || !userText || !assistantText) return [];
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        model_id: modelId,
        user_message: userText,
        assistant_message: assistantText,
      }),
    });
    const parsed = await parseJsonResponse(res);
    if (!parsed.res.ok) return [];
    return parsed.data.followups || [];
  }

  function lastUserAssistantPair() {
    let userText = '';
    let assistantText = '';
    for (let i = history.length - 1; i >= 0; i--) {
      const m = history[i];
      if (!assistantText && m.role === 'assistant') {
        assistantText = String(m.content || '').trim();
        continue;
      }
      if (assistantText && m.role === 'user') {
        userText = String(m.content || '').trim();
        break;
      }
    }
    if (!userText || !assistantText || isPendingMarkerContent(assistantText)) {
      return null;
    }
    return { userText: userText, assistantText: assistantText };
  }

  async function showFollowUpsForAssistantWrap(wrap) {
    const pair = lastUserAssistantPair();
    if (!pair || !wrap) return;
    try {
      const questions = await fetchFollowUps(pair.userText, pair.assistantText);
      if (questions.length) {
        renderFollowUpsForMessage(wrap, questions);
      }
    } catch (_) {
      /* ignore follow-up failures */
    }
  }

  function resolveActiveAgentProfile() {
    if (!isAgentModeActive()) return null;
    if (
      activeAgentProfile &&
      String(activeAgentProfile.type) === String(activeAgentRef.type) &&
      String(activeAgentProfile.id) === String(activeAgentRef.id)
    ) {
      return activeAgentProfile;
    }
    if (window.CampusChatAgents && window.CampusChatAgents.findAgentByRef) {
      return window.CampusChatAgents.findAgentByRef(activeAgentRef);
    }
    return null;
  }

  function syncWelcomeHero() {
    const defaultPanel = document.getElementById('chat-welcome-default');
    const agentPanel = document.getElementById('chat-welcome-agent');
    if (!defaultPanel || !agentPanel) return;

    const agent = resolveActiveAgentProfile();
    if (agent) {
      const avatarEl = document.getElementById('chat-agent-welcome-avatar');
      const nameEl = document.getElementById('chat-agent-welcome-name');
      const descEl = document.getElementById('chat-agent-welcome-desc');
      const name = String(agent.display_name || '智能体').trim() || '智能体';
      const desc = String(agent.description || '').trim();

      if (avatarEl) {
        avatarEl.classList.remove('chat-agent-welcome__avatar--fallback');
        if (agent.avatar_url) {
          avatarEl.innerHTML =
            '<img src="' +
            escapeHtml(agent.avatar_url) +
            '" alt="' +
            escapeHtml(name) +
            '">';
        } else {
          avatarEl.innerHTML = '';
          avatarEl.textContent = name.slice(0, 1);
          avatarEl.classList.add('chat-agent-welcome__avatar--fallback');
        }
      }
      if (nameEl) nameEl.textContent = name;
      if (descEl) descEl.textContent = desc;
      defaultPanel.hidden = true;
      agentPanel.hidden = false;
      return;
    }

    defaultPanel.hidden = false;
    agentPanel.hidden = true;
  }

  function showWelcome(options) {
    options = options || {};
    if (!options.force && conversationId > 0 && history.length > 0) {
      syncChatEmptyState();
      return;
    }
    clearFollowUps();
    if (!options.keepMessages) {
      messagesEl.querySelectorAll('.msg').forEach(function (el) {
        el.remove();
      });
    }
    syncChatEmptyState();
    syncWelcomeHero();
  }

  function removeWelcome() {
    if (chatMain) chatMain.classList.remove('is-empty');
  }

  function hasPendingGeneration() {
    if (window.CampusChatMedia && window.CampusChatMedia.hasPendingInHistory) {
      return window.CampusChatMedia.hasPendingInHistory(history);
    }
    return history.some(function (m) {
      return m.role === 'assistant' && String(m.content || '').trim().indexOf('<!--') === 0;
    });
  }

  function syncPendingGenerationUi() {
    bindThinkingToggle(messagesEl);
    messagesEl.querySelectorAll('[data-action="cancel-pending"]').forEach(function (btn) {
      if (btn.dataset.boundPendingCancel) return;
      btn.dataset.boundPendingCancel = '1';
      btn.addEventListener('click', async function () {
        await abortActiveGeneration({ partial: '' });
        await loadConversations();
        showToast('已停止等待');
      });
    });
  }

  function renderAllMessages() {
    messagesEl.querySelectorAll('.msg').forEach(function (el) {
      el.remove();
    });
    if (history.length === 0) {
      showWelcome({ force: true });
      return;
    }
    if (chatMain) chatMain.classList.remove('is-empty');
    history.forEach((m) => appendMessage(m.role, m.content, false, false));
    syncPendingGenerationUi();
    scrollMessagesToBottom();
  }

  const TOOL_ICONS = {
    copy:
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>',
    edit:
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>',
    speak:
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>',
    info:
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>',
    like:
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M7 11v10a1 1 0 001 1h2v-9H6a1 1 0 00-1 1v7a1 1 0 001 1h1zM17 11V6l-4-2-1 5-4 1v11h10a1 1 0 001-1v-5l-2-4z"/></svg>',
    dislike:
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M17 13V3a1 1 0 00-1-1h-2v9h4a1 1 0 001-1V3a1 1 0 00-1-1h-1zM7 13v5l4 2 1-5 4-1V3H6a1 1 0 00-1 1v5l2 4z"/></svg>',
    regen:
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M21 12a9 9 0 11-2.64-6.36"/><path d="M21 3v6h-6"/></svg>',
    quote:
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M3 10a4 4 0 014-4h1V4H7a6 6 0 00-6 6v1h2V10zM14 10a4 4 0 014-4h1V4h-1a6 6 0 00-6 6v1h2v-1z"/></svg>',
  };

  function toolBtn(action, title) {
    return (
      '<button type="button" class="msg-tool-btn" data-action="' +
      action +
      '" title="' +
      escapeHtml(title) +
      '" aria-label="' +
      escapeHtml(title) +
      '">' +
      (TOOL_ICONS[action] || '') +
      '</button>'
    );
  }

  function buildMsgToolbar(role) {
    if (role === 'user') {
      return (
        '<div class="msg-toolbar">' +
        toolBtn('quote', '引用') +
        toolBtn('copy', '复制') +
        toolBtn('edit', '编辑') +
        '</div>'
      );
    }
    return (
      '<div class="msg-toolbar">' +
      toolBtn('quote', '引用') +
      toolBtn('copy', '复制') +
      toolBtn('edit', '编辑') +
      toolBtn('speak', '朗读') +
      toolBtn('info', '信息') +
      toolBtn('like', '有帮助') +
      toolBtn('dislike', '没帮助') +
      toolBtn('regen', '重新生成') +
      '</div>'
    );
  }

  function svgIcon(name, size, className) {
    if (window.iconSvg) return window.iconSvg(name, size || 16, className || '');
    return '';
  }

  function utf8ToBase64(str) {
    return btoa(
      encodeURIComponent(str).replace(/%([0-9A-F]{2})/g, function (_, hex) {
        return String.fromCharCode(parseInt(hex, 16));
      })
    );
  }

  function utf8FromBase64(b64) {
    return decodeURIComponent(
      Array.prototype.map
        .call(atob(b64), function (c) {
          return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
        })
        .join('')
    );
  }

  function parseAssistantStoredContent(text) {
    const raw = String(text || '');
    const m = raw.match(/^<!--reasoning:([A-Za-z0-9+\/_=-]+)-->\n?/s);
    if (!m) return { reasoning: '', content: raw };
    let b64 = m[1].replace(/-/g, '+').replace(/_/g, '/');
    while (b64.length % 4) b64 += '=';
    try {
      return { reasoning: utf8FromBase64(b64), content: raw.slice(m[0].length) };
    } catch (_) {
      return { reasoning: '', content: raw };
    }
  }

  function packAssistantContent(content, reasoning) {
    const body = String(content || '');
    const think = String(reasoning || '').trim();
    if (!think) return body;
    const b64 = utf8ToBase64(think).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    return '<!--reasoning:' + b64 + '-->\n' + body;
  }

  function isDeepThinkEnabled() {
    return !!deepThinkEnabled;
  }

  function shouldStreamReasoning(opts) {
    opts = opts || {};
    if (opts.callMode) return false;
    return isDeepThinkEnabled();
  }

  function loadDeepThinkPref() {
    try {
      return localStorage.getItem(DEEP_THINK_KEY) === '1';
    } catch (_) {
      return false;
    }
  }

  function saveDeepThinkPref(on) {
    try {
      localStorage.setItem(DEEP_THINK_KEY, on ? '1' : '0');
    } catch (_) {}
  }

  function syncDeepThinkUi() {
    if (!btnDeepThink) return;
    btnDeepThink.classList.toggle('is-on', deepThinkEnabled);
    btnDeepThink.setAttribute('aria-pressed', deepThinkEnabled ? 'true' : 'false');
  }

  function initDeepThinkToggle() {
    deepThinkEnabled = loadDeepThinkPref();
    syncDeepThinkUi();
    btnDeepThink?.addEventListener('click', function () {
      deepThinkEnabled = !deepThinkEnabled;
      saveDeepThinkPref(deepThinkEnabled);
      syncDeepThinkUi();
      showToast(deepThinkEnabled ? '已开启深度思考' : '已关闭深度思考，将直接回答');
    });
  }

  function updateSendButtonMode(streaming) {
    if (!btnSend) return;
    btnSend.type = streaming ? 'button' : 'submit';
    btnSend.classList.toggle('send-btn--stop', !!streaming);
    btnSend.setAttribute('aria-label', streaming ? '停止生成' : '发送');
    const icon = btnSend.querySelector('[data-icon]');
    if (icon) {
      icon.setAttribute('data-icon', streaming ? 'square' : 'arrow-up');
      if (window.renderIcons) window.renderIcons(btnSend);
    }
  }

  async function cancelPendingOnServer(partialContent, reasoning) {
    if (!conversationId) return;
    try {
      await fetch(cfg.convUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'cancel_pending',
          id: conversationId,
          partial_content: partialContent || '',
          reasoning: reasoning || '',
        }),
      });
    } catch (_) {}
  }

  function popPendingAssistantFromHistory(partialContent) {
    if (history.length && history[history.length - 1].role === 'assistant') {
      if (isPendingMarkerContent(history[history.length - 1].content)) {
        if (partialContent) {
          history[history.length - 1].content = packAssistantContent(
            partialContent,
            activeStreamReasoning
          );
        } else {
          history.pop();
        }
        return;
      }
    }
    if (partialContent) {
      history.push({
        role: 'assistant',
        content: packAssistantContent(partialContent, activeStreamReasoning),
      });
    }
  }

  async function abortActiveGeneration(options) {
    options = options || {};
    const partial = options.partial != null ? options.partial : activeStreamContent;
    const reasoning = options.reasoning != null ? options.reasoning : activeStreamReasoning;
    const bubble = options.bubbleEl || activeStreamBubble;

    if (chatStreamAbort) {
      chatStreamAbort.abort();
      chatStreamAbort = null;
    }

    if (conversationId > 0) {
      await cancelPendingOnServer(partial, reasoning);
    }

    if (bubble) {
      if (partial) {
        finalizeAssistantBubble(bubble, partial, reasoning);
        popPendingAssistantFromHistory(partial);
      } else if (bubble.closest('.msg')) {
        bubble.closest('.msg').remove();
        if (history.length && history[history.length - 1].role === 'assistant') {
          history.pop();
        }
      }
    }

    activeStreamBubble = null;
    activeStreamReasoning = '';
    activeStreamContent = '';
    isStreaming = false;
    setLoading(false);
    updateScrollBottomButton();
  }

  function buildPendingAssistantHtml() {
    return (
      buildThinkingBlockHtml({ active: true, reasoning: '', collapsed: false }) +
      '<div class="msg-pending-row"><button type="button" class="msg-pending-cancel" data-action="cancel-pending">停止等待</button></div>'
    );
  }

  function buildAssistantDisplayHtml(text, opts) {
    opts = opts || {};
    if (isPendingMarkerContent(text)) {
      return buildPendingAssistantHtml();
    }
    const unpacked = parseAssistantStoredContent(text);
    const reasoning = opts.reasoning != null ? opts.reasoning : unpacked.reasoning;
    const content = opts.content != null ? opts.content : unpacked.content;
    let html = '';
    if (reasoning || opts.thinkingActive) {
      html += buildThinkingBlockHtml({
        active: !!opts.thinkingActive,
        reasoning: reasoning || '',
        collapsed: opts.collapsed != null ? opts.collapsed : !!reasoning,
      });
    }
    html += '<div class="ai-response-host">' + renderAssistantHtml(content) + '</div>';
    return html;
  }

  function attachmentLineHtml(name) {
    return (
      '<span class="msg-attach-line">' +
      svgIcon('paperclip', 14, 'inline-icon') +
      '<span>' + escapeHtml(name) + '</span></span>'
    );
  }

  function buildThinkingBlockHtml(opts) {
    opts = opts || {};
    const active = !!opts.active;
    const collapsed = !!opts.collapsed;
    const hidden = !!opts.hidden;
    const reasoning = String(opts.reasoning || '');
    const label = active
      ? '思考中'
      : reasoning
        ? '思考过程'
        : '已完成思考';
    const cls =
      'ai-thinking-block' +
      (active ? ' is-active' : ' is-done') +
      (collapsed ? ' is-collapsed' : '') +
      (hidden ? ' is-hidden' : '');
    const dots = active
      ? '<span class="ai-thinking-block__dots" aria-hidden="true"><span></span><span></span><span></span></span>'
      : '';
    const body =
      reasoning || active
        ? '<div class="ai-thinking-block__body"><div class="ai-thinking-block__text">' +
          escapeHtml(reasoning) +
          '</div></div>'
        : '';
    return (
      '<div class="' + cls + '">' +
      '<button type="button" class="ai-thinking-block__toggle" aria-expanded="' +
      (!collapsed) +
      '">' +
      '<span class="ai-thinking-block__icon">' +
      svgIcon(active ? 'sparkles' : 'brain', 16) +
      '</span>' +
      '<span class="ai-thinking-block__label">' +
      escapeHtml(label) +
      '</span>' +
      dots +
      '<span class="ai-thinking-block__chev">' +
      svgIcon('chevron-down', 14) +
      '</span></button>' +
      body +
      '</div>'
    );
  }

  function bindThinkingToggle(root) {
    if (!root) return;
    root.querySelectorAll('.ai-thinking-block__toggle').forEach(function (btn) {
      if (btn.dataset.boundThinking) return;
      btn.dataset.boundThinking = '1';
      btn.addEventListener('click', function () {
        const block = btn.closest('.ai-thinking-block');
        if (!block || block.classList.contains('is-hidden')) return;
        block.classList.toggle('is-collapsed');
        btn.setAttribute('aria-expanded', block.classList.contains('is-collapsed') ? 'false' : 'true');
      });
    });
  }

  function updateThinkingBlock(el, opts) {
    if (!el) return;
    const block = el.classList && el.classList.contains('ai-thinking-block') ? el : el.querySelector('.ai-thinking-block');
    if (!block) return;
    const active = !!opts.active;
    const reasoning = String(opts.reasoning || '');
    const hide = !!opts.hidden;
    block.classList.toggle('is-active', active);
    block.classList.toggle('is-done', !active);
    block.classList.toggle('is-hidden', hide && !reasoning);
    if (opts.collapsed != null) {
      block.classList.toggle('is-collapsed', !!opts.collapsed);
    }
    const labelEl = block.querySelector('.ai-thinking-block__label');
    if (labelEl) {
      labelEl.textContent = active ? '思考中' : reasoning ? '思考过程' : '已完成思考';
    }
    const iconEl = block.querySelector('.ai-thinking-block__icon');
    if (iconEl) {
      iconEl.innerHTML = svgIcon(active ? 'sparkles' : 'brain', 16);
    }
    let dotsEl = block.querySelector('.ai-thinking-block__dots');
    if (active && !dotsEl) {
      const toggle = block.querySelector('.ai-thinking-block__toggle');
      if (toggle) {
        const chev = toggle.querySelector('.ai-thinking-block__chev');
        const wrap = document.createElement('span');
        wrap.className = 'ai-thinking-block__dots';
        wrap.setAttribute('aria-hidden', 'true');
        wrap.innerHTML = '<span></span><span></span><span></span>';
        if (chev) toggle.insertBefore(wrap, chev);
        else toggle.appendChild(wrap);
      }
    } else if (!active && dotsEl) {
      dotsEl.remove();
    }
    let bodyEl = block.querySelector('.ai-thinking-block__body');
    if (reasoning || active) {
      if (!bodyEl) {
        bodyEl = document.createElement('div');
        bodyEl.className = 'ai-thinking-block__body';
        bodyEl.innerHTML = '<div class="ai-thinking-block__text"></div>';
        block.appendChild(bodyEl);
      }
      const textEl = bodyEl.querySelector('.ai-thinking-block__text');
      if (textEl) textEl.textContent = reasoning;
      bodyEl.hidden = false;
    } else if (bodyEl && !reasoning && !active) {
      bodyEl.hidden = true;
    }
    bindThinkingToggle(block);
  }

  function buildTypingIndicatorHtml() {
    return buildThinkingBlockHtml({ active: true, reasoning: '', collapsed: false });
  }

  function buildUserMsgContentHtml(content, typing) {
    const inner = typing ? buildTypingIndicatorHtml() : formatContent(content, 'user');
    return (
      '<div class="msg__content msg-content card-spotlight card-spotlight--msg-user" ' +
      'data-spotlight-card data-spotlight-color="rgba(180, 151, 207, 0.22)">' +
      inner +
      '</div>'
    );
  }

  function buildAiMsgShellHtml(innerHtml, extraBubbleClass) {
    return (
      '<div class="msg-bubble msg-bubble--ai' + (extraBubbleClass || '') + '">' +
      '<div class="border-glow-card border-glow-card--msg" data-border-glow data-border-glow-preset="msg">' +
      '<span class="edge-light" aria-hidden="true"></span>' +
      '<div class="border-glow-inner">' +
      '<div class="msg__content msg-content">' +
      innerHtml +
      '</div></div></div></div>'
    );
  }

  function buildMsgBodyHtml(role, content, typing) {
    const isUser = role === 'user';
    const toolbarInner = typing
      ? ''
      : buildMsgToolbar(role).replace(/^<div class="msg-toolbar">|<\/div>$/g, '');
    const toolbarHtml = toolbarInner
      ? '<div class="msg__actions msg-toolbar">' + toolbarInner + '</div>'
      : '';

    if (isUser) {
      return (
        '<div class="msg-bubble msg-bubble--user">' +
        buildUserMsgContentHtml(content, typing) +
        toolbarHtml +
        '</div>'
      );
    }

    const inner = typing
      ? buildAssistantStreamingInner(isDeepThinkEnabled())
      : buildAssistantDisplayHtml(content) + toolbarHtml;
    return buildAiMsgShellHtml(inner, typing ? ' is-streaming is-typing' : '');
  }

  function buildStreamingBubbleShell(showReasoning) {
    return buildAiMsgShellHtml(buildAssistantStreamingInner(showReasoning), ' is-streaming');
  }

  function mountMsgEnhancements(body) {
    if (!body) return;
    if (window.mountSpotlightCards) window.mountSpotlightCards(body);
    if (window.mountBorderGlowCards) window.mountBorderGlowCards(body);
    if (window.mountChatMedia) window.mountChatMedia(body);
  }

  function appendMessage(role, content, typing, doScroll) {
    if (doScroll !== false) removeWelcome();
    const isUser = role === 'user';
    const wrap = document.createElement('article');
    wrap.className = 'msg msg--' + (isUser ? 'user' : 'ai') + ' msg-' + role;
    wrap.innerHTML =
      buildMsgHeadHtml(role) +
      '<div class="msg__body msg-body">' +
      buildMsgBodyHtml(role, content, typing) +
      '</div>';
    ensureMsgAvatarOnWrap(wrap, role);
    const body = wrap.querySelector('.msg-body');
    if (!typing && body) {
      bindMessageToolbar(body, content, role, wrap);
      mountMsgEnhancements(body);
      if (role === 'assistant') bindThinkingToggle(body);
    } else if (body && window.mountSpotlightCards) {
      mountMsgEnhancements(body);
    }
    if (typing && role === 'assistant') {
      bindThinkingToggle(body);
    }
    messagesEl.appendChild(wrap);
    if (doScroll !== false) scrollMessagesToBottom();
    return wrap;
  }

  function finalizeAssistantBubble(bodyEl, content, reasoning, durationSec) {
    if (!bodyEl) return;
    const wrap = bodyEl.closest('.msg');
    ensureMsgAvatarOnWrap(wrap, 'assistant');
    const packed = packAssistantContent(content, reasoning);
    const displayHtml = buildAssistantDisplayHtml(packed, {
      collapsed: true,
      durationSec: durationSec != null ? durationSec : null,
    });
    const toolbarInner = buildMsgToolbar('assistant').replace(/^<div class="msg-toolbar">|<\/div>$/g, '');
    const toolbarHtml = toolbarInner
      ? '<div class="msg__actions msg-toolbar">' + toolbarInner + '</div>'
      : '';
    const contentEl = bodyEl.querySelector('.msg-bubble--ai .msg__content, .msg-bubble--ai .msg-content');
    if (contentEl) {
      contentEl.innerHTML = displayHtml + toolbarHtml;
      bodyEl.querySelector('.msg-bubble--ai')?.classList.remove('is-streaming', 'is-typing');
    } else {
      bodyEl.innerHTML =
        '<div class="msg-bubble msg-bubble--ai">' +
        '<div class="border-glow-card border-glow-card--msg" data-border-glow data-border-glow-preset="msg">' +
        '<span class="edge-light" aria-hidden="true"></span>' +
        '<div class="border-glow-inner">' +
        '<div class="msg__content msg-content">' +
        displayHtml +
        toolbarHtml +
        '</div></div></div></div>';
    }
    bindMessageToolbar(bodyEl, packed, 'assistant', wrap);
    bindThinkingToggle(bodyEl);
    mountMsgEnhancements(bodyEl);
    if (window.mountBorderGlowCards) window.mountBorderGlowCards(bodyEl);
    scrollMessagesToBottom();
  }

  function setBubbleText(bodyEl, text) {
    if (!bodyEl) return;
    const unpacked = parseAssistantStoredContent(text);
    finalizeAssistantBubble(bodyEl, unpacked.content, unpacked.reasoning);
  }

  async function regenerateFromWrap(wrap) {
    if (isStreaming) {
      await abortActiveGeneration();
    }
    if (hasPendingGeneration()) {
      await abortActiveGeneration({ partial: '' });
    }
    while (history.length && history[history.length - 1].role === 'assistant') {
      history.pop();
    }
    if (!history.length || history[history.length - 1].role !== 'user') {
      showToast('无法重新生成');
      return;
    }
    if (wrap) wrap.remove();
    clearFollowUps();
    const assistantWrap = appendMessage('assistant', '', true);
    const bubble = assistantWrap.querySelector('.msg-body');
    activeStreamBubble = bubble;
    activeStreamReasoning = '';
    activeStreamContent = '';
    chatStreamAbort = new AbortController();
    setLoading(true);
    isStreaming = true;
    stickToBottom = true;
    try {
      await streamChat(bubble, { signal: chatStreamAbort.signal });
      await loadConversations();
      await syncCurrentConvTimestamp();
      showFollowUpsForAssistantWrap(assistantWrap);
    } catch (err) {
      if (!(err && err.name === 'AbortError')) {
        setBubbleText(bubble, '请求失败：' + (err.message || String(err)));
      }
    } finally {
      chatStreamAbort = null;
      activeStreamBubble = null;
      isStreaming = false;
      setLoading(false);
      if (stickToBottom) {
        scrollMessagesToBottom(true);
      } else {
        updateScrollBottomButton();
      }
    }
  }

  function formatUserDisplay(text, files, images) {
    const parts = [];
    if (text) parts.push(text);
    if (images && images.length) {
      images.forEach(function (img) {
        parts.push('![' + (img.name || '图片') + '](' + img.url + ')');
      });
    }
    if (files.length) {
      files.forEach(function (f) {
        parts.push('[[attach:' + f.filename + ']]');
      });
    }
    return parts.join('\n') || (images && images.length ? '（图片）' : '（附件）');
  }

  function formatUserStoredContent(text) {
    if (!text || text.indexOf('【附件：') === -1) return text;
    const cut = text.indexOf('\n\n【附件：');
    const main = cut >= 0 ? text.slice(0, cut).trim() : '';
    const names = [];
    const re = /【附件：([^\】]+)】/g;
    let m;
    while ((m = re.exec(text)) !== null) {
      names.push('[[attach:' + m[1] + ']]');
    }
    const parts = [];
    if (main) parts.push(main);
    parts.push.apply(parts, names);
    return parts.join('\n') || '（附件）';
  }

  function isPendingMarkerContent(text) {
    const t = String(text || '').trim();
    return (
      t === '<!--text-pending-->' ||
      t.indexOf('<!--queue-pending:') === 0 ||
      t.indexOf('<!--video-pending:') === 0
    );
  }

  function formatContent(text, role) {
    if (!text) return '';
    if (role === 'assistant') {
      if (isPendingMarkerContent(text)) {
        return buildPendingAssistantHtml();
      }
      return buildAssistantDisplayHtml(text, { collapsed: true });
    }
    const display = formatUserStoredContent(text);
    const lines = display.split('\n');
    const htmlParts = [];
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      const attachMatch = line.match(/^\[\[attach:(.+)\]\]$/);
      if (attachMatch) {
        htmlParts.push(attachmentLineHtml(attachMatch[1]));
      } else if (line) {
        htmlParts.push(escapeHtml(line));
      }
    }
    return '<div class="msg-text">' + htmlParts.join('<br>') + '</div>';
  }

  function renderAssistantHtml(text) {
    return typeof renderMarkdown === 'function'
      ? renderMarkdown(text)
      : '<div class="md-body">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div>';
  }

  let streamMdTimer = null;

  function renderStreamMarkdownNow(el, text, streaming) {
    if (!el) return;
    if (window.CampusCodeWorkspace && window.CampusCodeWorkspace.renderStreamingContent) {
      window.CampusCodeWorkspace.renderStreamingContent(el, text, { streaming: !!streaming });
      return;
    }
    el.innerHTML = renderAssistantHtml(text);
    if (streaming) {
      const cursor = document.createElement('span');
      cursor.className = 'stream-text-cursor';
      cursor.setAttribute('aria-hidden', 'true');
      cursor.textContent = '|';
      el.appendChild(cursor);
    }
  }

  function scheduleStreamMarkdown(el, text) {
    if (!el) return;
    if (streamMdTimer) clearTimeout(streamMdTimer);
    streamMdTimer = setTimeout(function () {
      streamMdTimer = null;
      renderStreamMarkdownNow(el, text, true);
      scrollMessagesToBottom();
    }, 40);
  }

  function flushStreamMarkdown(el, text) {
    if (streamMdTimer) {
      clearTimeout(streamMdTimer);
      streamMdTimer = null;
    }
    renderStreamMarkdownNow(el, text, false);
  }

  function scrollMessagesToBottom(force) {
    const scroller = messagesWrap || messagesEl;
    if (!scroller) return;

    if (!force && isGenerating() && !stickToBottom) {
      updateScrollBottomButton();
      return;
    }

    if (force) stickToBottom = true;

    function applyScroll() {
      const top = Math.max(0, scroller.scrollHeight - scroller.clientHeight);
      scroller.scrollTop = top;
      const last = messagesEl && messagesEl.lastElementChild;
      if (last && typeof last.scrollIntoView === 'function') {
        try {
          last.scrollIntoView({ block: 'end', inline: 'nearest', behavior: 'auto' });
        } catch (_) {
          last.scrollIntoView(false);
        }
      }
      scroller.scrollTop = Math.max(0, scroller.scrollHeight - scroller.clientHeight);
    }

    applyScroll();
    requestAnimationFrame(function () {
      applyScroll();
      requestAnimationFrame(applyScroll);
    });
    [0, 48, 120, 280].forEach(function (ms) {
      setTimeout(applyScroll, ms);
    });
    updateScrollBottomButton();
  }

  function setLoading(on) {
    btnSend.disabled = false;
    btnSend.classList.toggle('loading', on);
    updateSendButtonMode(on);
    if (btnVoice) btnVoice.classList.toggle('disabled', on);
  }

  function prepareMessagesForApi(messages) {
    if (window.CampusChatTransport && window.CampusChatTransport.prepareMessagesForApi) {
      return window.CampusChatTransport.prepareMessagesForApi(messages);
    }
    return messages || [];
  }

  const CALL_MODE_SYSTEM_PROMPT =
    '你现在正在和用户进行实时语音通话。请像普通人面对面聊天一样回答：' +
    '用口语化、自然流畅的中文，句子完整连贯；一般一两到三句话说完要点，不要拆成很多短句，也不要重复啰嗦。' +
    '不要使用 Markdown、列表、标题、代码块或表情符号；避免「首先/其次/综上所述」等书面套话。' +
    '语气友好直接，必要时可以简短反问或确认。';

  function prepareCallModeMessagesForApi(messages) {
    const apiMessages = prepareMessagesForApi(messages);
    return [{ role: 'system', content: CALL_MODE_SYSTEM_PROMPT }].concat(apiMessages);
  }

  let callModeAbort = null;

  async function streamChat(bubbleEl, opts) {
    opts = opts || {};
    const signal = opts.signal;

    let res;
    try {
      res = await fetch(cfg.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        signal: signal,
        body: JSON.stringify({
          model_id: modelId,
          conversation_id: conversationId,
          messages: opts.callMode ? prepareCallModeMessagesForApi(history) : prepareMessagesForApi(history),
          stream: true,
          deep_think: opts.callMode ? false : isDeepThinkEnabled(),
          agent: activeAgentRef,
        }),
      });
    } catch (err) {
      if (err && err.name === 'AbortError') throw err;
      throw err;
    }

    if (!res.ok) {
      const parsed = await parseJsonResponse(res).catch(function () {
        return { res: res, data: {} };
      });
      const errJson = parsed.data || {};
      if (res.status === 403 || res.status === 413) {
        throw new Error(errJson.error || '请求被安全策略拦截，请稍后重试');
      }
      throw new Error(errJson.error || res.statusText || '请求失败');
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let full = '';
    let buffer = '';

    const showReasoning = shouldStreamReasoning(opts);
    let contentHost = bubbleEl.querySelector('.ai-response-host');
    let thinkingEl = bubbleEl.querySelector('.ai-thinking-block');
    if (!contentHost) {
      bubbleEl.innerHTML = buildStreamingBubbleShell(showReasoning);
      contentHost = bubbleEl.querySelector('.ai-response-host');
      thinkingEl = bubbleEl.querySelector('.ai-thinking-block');
      if (window.mountBorderGlowCards) window.mountBorderGlowCards(bubbleEl);
      bindThinkingToggle(bubbleEl);
    } else {
      bubbleEl.querySelector('.msg-bubble--ai')?.classList.add('is-streaming');
      if (!showReasoning && thinkingEl) {
        thinkingEl.remove();
        thinkingEl = null;
      }
    }
    ensureMsgAvatarOnWrap(bubbleEl.closest('.msg'), 'assistant');
    activeStreamBubble = bubbleEl;

    let fullReasoning = '';
    let hasContent = false;

    try {
      while (true) {
        if (signal && signal.aborted) {
          await reader.cancel().catch(function () {});
          break;
        }

        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() || '';

        for (const line of lines) {
          const trimmed = line.trim();
          if (!trimmed.startsWith('data:')) continue;
          const payloads = trimmed
            .slice(5)
            .trim()
            .split(/data:\s*/i)
            .map(function (s) {
              return s.trim();
            })
            .filter(Boolean);

          for (let pi = 0; pi < payloads.length; pi++) {
            const data = payloads[pi];
            if (data === '[DONE]') continue;

            let json;
            try {
              json = JSON.parse(data);
            } catch (parseErr) {
              if (parseErr instanceof SyntaxError) continue;
              throw parseErr;
            }

            if (json.conversation_id) {
              conversationId = parseInt(json.conversation_id, 10);
              if (activeAgentRef && window.CampusChatAgents && window.CampusChatAgents.setAgentConversationId) {
                window.CampusChatAgents.setAgentConversationId(
                  agentKeyFromRef(activeAgentRef),
                  conversationId
                );
              }
            }
            if (json.title) {
              updateConversationTitle(json.conversation_id || conversationId, json.title);
            }
            if (json.reasoning && showReasoning) {
              fullReasoning += json.reasoning;
              activeStreamReasoning = fullReasoning;
              updateThinkingBlock(thinkingEl, {
                active: !hasContent,
                reasoning: fullReasoning,
                collapsed: false,
                hidden: false,
              });
            }
            if (json.content) {
              if (!hasContent) {
                hasContent = true;
                if (showReasoning) {
                  updateThinkingBlock(thinkingEl, {
                    active: false,
                    reasoning: fullReasoning,
                    collapsed: !!fullReasoning,
                    hidden: false,
                  });
                }
              }
              full += json.content;
              activeStreamContent = full;
              if (contentHost) {
                contentHost.classList.remove('is-empty');
                scheduleStreamMarkdown(contentHost, full);
              }
              if (typeof opts.onChunk === 'function') opts.onChunk(full);
            }
            if (json.error) throw new Error(json.error);
          }
        }
      }
    } catch (err) {
      if (!(err && err.name === 'AbortError') && !(signal && signal.aborted)) {
        throw err;
      }
    }

    if (signal && signal.aborted) {
      throw new DOMException('Aborted', 'AbortError');
    }

    if (contentHost) {
      flushStreamMarkdown(contentHost, full);
    }

    if (!showReasoning) {
      fullReasoning = '';
    }

    updateThinkingBlock(thinkingEl, {
      active: false,
      reasoning: fullReasoning,
      collapsed: !!fullReasoning,
      hidden: !showReasoning,
    });

    if (!full) {
      finalizeAssistantBubble(bubbleEl, '（模型未返回内容）', fullReasoning);
      history.push({ role: 'assistant', content: packAssistantContent('（模型未返回内容）', fullReasoning) });
      return;
    }

    finalizeAssistantBubble(bubbleEl, full, fullReasoning);
    history.push({ role: 'assistant', content: packAssistantContent(full, fullReasoning) });
  }

  async function sendCallModeMessage(text, hooks) {
    const trimmed = String(text || '').trim();
    if (!trimmed || !modelId) throw new Error('无法发送');
    if (hasPendingGeneration()) throw new Error('有任务正在生成中');

    await ensureConversation();
    removeWelcome();
    scrollMessagesToBottom();

    appendMessage('user', formatUserDisplay(trimmed, []));
    scrollMessagesToBottom();
    history.push({ role: 'user', content: trimmed });

    const assistantWrap = appendMessage('assistant', '', true);
    scrollMessagesToBottom();
    const assistantBubble = assistantWrap.querySelector('.msg-body');
    setLoading(true);
    isStreaming = true;
    stickToBottom = true;

    const ac = new AbortController();
    callModeAbort = ac;

    try {
      await streamChat(assistantBubble, {
        callMode: true,
        signal: ac.signal,
        onChunk: hooks && typeof hooks.onAiPartial === 'function' ? hooks.onAiPartial : null,
      });
      await loadConversations();
      await syncCurrentConvTimestamp();
      const last = history[history.length - 1];
      return last && last.role === 'assistant' ? last.content : '';
    } catch (err) {
      if (err && err.name === 'AbortError') {
        const last = history[history.length - 1];
        if (last && last.role === 'assistant') {
          return last.content;
        }
        assistantWrap?.remove();
        return '';
      }
      throw err;
    } finally {
      callModeAbort = null;
      setLoading(false);
      isStreaming = false;
      updateScrollBottomButton();
    }
  }

  function abortCallModeStream() {
    if (callModeAbort) {
      callModeAbort.abort();
    }
  }

  function buildMessageWithFiles(text) {
    const parts = [];
    if (text) parts.push(text);
    imageAttachments.forEach(function (img) {
      parts.push('\n\n![' + (img.name || '图片') + '](' + img.url + ')');
    });
    attachments.forEach((a) => {
      parts.push('\n\n【附件：' + a.filename + '】\n' + a.text);
    });
    return parts.join('').trim();
  }

  function uploadImageAttachment(file) {
    if (!file || !(file.type || '').startsWith('image/')) {
      return Promise.reject(new Error('请选择图片文件'));
    }
    if (Object.keys(pendingUploads).length > 0) {
      return Promise.reject(new Error('请等待当前上传完成'));
    }
    const id = 'img-' + Date.now();
    const el = createImageUploadCard(id, file);
    pendingUploads[id] = { file: file, el: el, kind: 'image' };
    renderAttachments();
    setAttachDisabled(true);

    const fd = new FormData();
    fd.append('file', file);

    return fetch(cfg.mediaRefUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
    })
      .then(function (res) {
        return res.json().catch(function () {
          return {};
        }).then(function (data) {
          if (!res.ok) throw new Error(data.error || '图片上传失败');
          delete pendingUploads[id];
          imageAttachments.push({
            url: data.url,
            name: data.name || file.name || '图片',
          });
          renderAttachments();
          setAttachDisabled(false);
          showToast('图片已添加');
          return data;
        });
      })
      .catch(function (err) {
        delete pendingUploads[id];
        renderAttachments();
        setAttachDisabled(false);
        throw err;
      });
  }

  function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function uploadFileWithProgress(file) {
    const id = 'up-' + Date.now();
    const el = createUploadCard(id, file);
    pendingUploads[id] = { file, el };
    renderAttachments();
    setAttachDisabled(true);

    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      const fd = new FormData();
      fd.append('file', file);

      xhr.upload.addEventListener('progress', (e) => {
        if (!e.lengthComputable) return;
        const pct = Math.min(88, Math.round((e.loaded / e.total) * 88));
        updateUploadCard(id, pct, '上传中… ' + pct + '%', 'uploading');
      });

      xhr.addEventListener('load', () => {
        updateUploadCard(id, 92, '正在解析文档…', 'parsing');
        let data;
        try {
          data = JSON.parse(xhr.responseText);
        } catch {
          setUploadCardError(id, '服务器响应无效');
          reject(new Error('invalid json'));
          return;
        }
        if (xhr.status >= 400 || data.error) {
          setUploadCardError(id, data.error || '上传失败');
          reject(new Error(data.error || 'upload failed'));
          return;
        }
        updateUploadCard(id, 100, '已就绪', 'done');
        setTimeout(() => {
          delete pendingUploads[id];
          attachments.push({ filename: data.filename, text: data.text });
          renderAttachments();
          setAttachDisabled(false);
        }, 350);
        resolve(data);
      });

      xhr.addEventListener('error', () => {
        setUploadCardError(id, '网络错误，请重试');
        reject(new Error('network'));
      });

      xhr.addEventListener('abort', () => {
        delete pendingUploads[id];
        renderAttachments();
        setAttachDisabled(false);
        reject(new Error('abort'));
      });

      xhr.open('POST', cfg.uploadUrl);
      xhr.withCredentials = true;
      xhr.send(fd);
      pendingUploads[id].xhr = xhr;
    }).catch(() => {
      setAttachDisabled(Object.keys(pendingUploads).length > 0);
    });
  }

  function createUploadCard(id, file) {
    const card = document.createElement('div');
    card.className = 'file-upload-card';
    card.innerHTML =
      '<div class="file-upload-head">' +
      '<span class="file-upload-icon">' + svgIcon('file-text', 18) + '</span>' +
      '<div class="file-upload-info">' +
      '<span class="file-upload-name">' +
      escapeHtml(file.name) +
      '</span>' +
      '<span class="file-upload-meta">' +
      formatFileSize(file.size) +
      '</span></div>' +
      '<button type="button" class="file-upload-cancel">×</button></div>' +
      '<div class="file-upload-status">准备上传…</div>' +
      '<div class="file-upload-track"><div class="file-upload-bar"></div></div>';
    card.querySelector('.file-upload-cancel').addEventListener('click', () => {
      pendingUploads[id]?.xhr?.abort();
      delete pendingUploads[id];
      renderAttachments();
      setAttachDisabled(Object.keys(pendingUploads).length > 0);
    });
    return card;
  }

  function createImageUploadCard(id, file) {
    const card = document.createElement('div');
    card.className = 'file-upload-card file-upload-card--image';
    const previewUrl = URL.createObjectURL(file);
    card.innerHTML =
      '<div class="file-upload-head">' +
      '<img class="file-upload-thumb" alt="" src="' +
      escapeHtml(previewUrl) +
      '">' +
      '<div class="file-upload-info">' +
      '<span class="file-upload-name">' +
      escapeHtml(file.name) +
      '</span>' +
      '<span class="file-upload-meta">上传中…</span></div>' +
      '<button type="button" class="file-upload-cancel">×</button></div>';
    card.querySelector('.file-upload-cancel').addEventListener('click', () => {
      URL.revokeObjectURL(previewUrl);
      delete pendingUploads[id];
      renderAttachments();
      setAttachDisabled(Object.keys(pendingUploads).length > 0);
    });
    return card;
  }

  function updateUploadCard(id, percent, statusText, phase) {
    const item = pendingUploads[id];
    if (!item) return;
    item.el.className = 'file-upload-card file-upload-' + phase;
    const bar = item.el.querySelector('.file-upload-bar');
    const status = item.el.querySelector('.file-upload-status');
    if (bar) bar.style.width = percent + '%';
    if (status) status.textContent = statusText;
  }

  function setUploadCardError(id, message) {
    const item = pendingUploads[id];
    if (!item) return;
    item.el.className = 'file-upload-card file-upload-error';
    const status = item.el.querySelector('.file-upload-status');
    if (status) status.textContent = message;
    setTimeout(() => {
      delete pendingUploads[id];
      renderAttachments();
      setAttachDisabled(Object.keys(pendingUploads).length > 0);
    }, 4000);
  }

  function setAttachDisabled(on) {
    document.querySelector('.btn-attach')?.classList.toggle('disabled', on);
    if (btnVoice) btnVoice.classList.toggle('disabled', on);
    if (fileInput) fileInput.disabled = on;
  }

  function renderAttachments() {
    if (!fileAttachmentsEl) return;
    const hasPending = Object.keys(pendingUploads).length > 0;
    const hasDone = attachments.length > 0 || imageAttachments.length > 0;
    if (!hasPending && !hasDone) {
      fileAttachmentsEl.hidden = true;
      fileAttachmentsEl.innerHTML = '';
      return;
    }
    fileAttachmentsEl.hidden = false;
    fileAttachmentsEl.innerHTML = '';
    Object.values(pendingUploads).forEach((p) => fileAttachmentsEl.appendChild(p.el));
    imageAttachments.forEach(function (img, i) {
      const chip = document.createElement('span');
      chip.className = 'file-chip file-chip-done file-chip--image';
      chip.innerHTML =
        '<img class="file-chip__thumb" src="' +
        escapeHtml(img.url) +
        '" alt="">' +
        '<span class="file-chip__name">' +
        escapeHtml(img.name) +
        '</span>' +
        ' <button type="button" data-img-i="' +
        i +
        '">×</button>';
      chip.querySelector('button').addEventListener('click', function () {
        const idx = parseInt(chip.querySelector('button').getAttribute('data-img-i') || '-1', 10);
        if (idx >= 0) imageAttachments.splice(idx, 1);
        renderAttachments();
      });
      fileAttachmentsEl.appendChild(chip);
    });
    attachments.forEach((a, i) => {
      const chip = document.createElement('span');
      chip.className = 'file-chip file-chip-done';
      chip.innerHTML =
        '<span class="file-chip__check">' + svgIcon('check', 14, 'inline-icon') + '</span>' +
        escapeHtml(a.filename) +
        ' <button type="button" data-i="' + i + '">×</button>';
      chip.querySelector('button').addEventListener('click', () => {
        attachments.splice(i, 1);
        renderAttachments();
      });
      fileAttachmentsEl.appendChild(chip);
    });
  }

  const chatToast = document.getElementById('chat-toast');
  let toastTimer = null;

  function showToast(msg) {
    if (typeof window.toast === 'function') {
      window.toast(msg, 'info');
      return;
    }
    if (!chatToast) return;
    chatToast.textContent = msg;
    chatToast.hidden = false;
    chatToast.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      chatToast.classList.remove('is-visible');
      setTimeout(function () {
        chatToast.hidden = true;
      }, 280);
    }, 2000);
  }

  function copyToClipboard(text) {
    const s = String(text);
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(s).then(
        function () { return true; },
        function () { return fallbackCopy(s); }
      );
    }
    return Promise.resolve(fallbackCopy(s));
  }

  function fallbackCopy(text) {
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      const ok = document.execCommand('copy');
      ta.remove();
      return ok;
    } catch (e) {
      return false;
    }
  }

  function bindMessageToolbar(bubble, rawContent, role, wrap) {
    const copyText =
      role === 'user' ? formatUserStoredContent(rawContent) : String(rawContent || '');
    bubble.querySelectorAll('.msg-tool-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const action = btn.getAttribute('data-action');
        if (action === 'copy') {
          copyToClipboard(copyText).then(function (ok) {
            showToast(ok ? '已复制' : '复制失败，请长按选择文字');
          });
          return;
        }
        if (action === 'quote') {
          applyQuoteFromMessage(bubble, rawContent, role);
          return;
        }
        if (action === 'edit') {
          promptEl.value = copyText;
          promptEl.style.height = 'auto';
          promptEl.style.height = Math.min(promptEl.scrollHeight, 220) + 'px';
          promptEl.focus();
          return;
        }
        if (action === 'speak') {
          if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
            const u = new SpeechSynthesisUtterance(copyText.slice(0, 8000));
            u.lang = 'zh-CN';
            window.speechSynthesis.speak(u);
          } else {
            showToast('当前浏览器不支持朗读');
          }
          return;
        }
        if (action === 'info') {
          showToast('字数约 ' + copyText.length);
          return;
        }
        if (action === 'like' || action === 'dislike') {
          bubble.querySelectorAll('.msg-tool-btn[data-action="like"], .msg-tool-btn[data-action="dislike"]').forEach(function (b) {
            b.classList.remove('is-active');
          });
          btn.classList.add('is-active');
          showToast('感谢反馈');
          return;
        }
        if (action === 'regen' && wrap) {
          regenerateFromWrap(wrap);
        }
      });
    });
    if (role === 'assistant') {
      if (window.CampusCodeWorkspace) {
        window.CampusCodeWorkspace.enhanceBubble(bubble);
      }
      mountMsgEnhancements(bubble);
      if (window.CampusChatMedia && window.CampusChatMedia.resumeAssistantBubble) {
        window.CampusChatMedia.resumeAssistantBubble(bubble, rawContent, role);
      }
    }
  }

  function quoteCode(code, lang) {
    const fence = '```' + (lang || '') + '\n' + code + '\n```';
    pendingQuote = { type: 'text', text: fence, role: 'assistant' };
    renderComposerQuote();
    showToast('已引用代码，可在下方继续提问');
    promptEl.focus();
  }

  function replaceLastAssistantContent(content) {
    for (let i = history.length - 1; i >= 0; i--) {
      if (history[i].role === 'assistant') {
        history[i].content = content;
        return;
      }
    }
    history.push({ role: 'assistant', content: content });
  }


  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  if (window.CampusCodeWorkspace) {
    window.CampusCodeWorkspace.install({
      cfg: cfg,
      showToast: showToast,
      copyToClipboard: copyToClipboard,
      quoteCode: quoteCode,
    });
  }

  if (window.CampusComposerCode) {
    window.CampusComposerCode.install({
      composerWrap: document.querySelector('.composer'),
      composerLeftDefault: document.getElementById('composer-left-default'),
      composerCodeTools: document.getElementById('composer-code-tools'),
      btnEnterCodeMode: document.getElementById('btn-enter-code-mode'),
      btnCodeModeClose: document.getElementById('btn-code-mode-close'),
      promptEl: promptEl,
    });
  }

  if (window.CampusChatMedia) {
    window.CampusChatMedia.install({
      cfg: cfg,
      form: form,
      promptEl: promptEl,
      btnSend: btnSend,
      btnVoice: document.getElementById('btn-voice'),
      composerWrap: document.querySelector('.composer'),
      composerLeftDefault: document.getElementById('composer-left-default'),
      composerImageTools: document.getElementById('composer-image-tools'),
      composerVideoTools: document.getElementById('composer-video-tools'),
      btnEnterImageMode: document.getElementById('btn-enter-image-mode'),
      btnEnterVideoMode: document.getElementById('btn-enter-video-mode'),
      btnImageModeClose: document.getElementById('btn-image-mode-close'),
      btnVideoModeClose: document.getElementById('btn-video-mode-close'),
      btnImageRef: document.getElementById('btn-image-ref'),
      btnVideoRef: document.getElementById('btn-video-ref'),
      imageRefInput: document.getElementById('image-ref-input'),
      videoRefInput: document.getElementById('video-ref-input'),
      mediaRefStrip: document.getElementById('media-ref-strip'),
      sidebarQuota: document.getElementById('sidebar-quota'),
      getConversationId: function () {
        return conversationId;
      },
      setConversationId: function (id) {
        conversationId = id;
      },
      getHistory: function () {
        return history;
      },
      getIsStreaming: function () {
        return isStreaming;
      },
      setIsStreaming: function (v) {
        isStreaming = v;
        updateScrollBottomButton();
      },
      pushHistory: function (msg) {
        history.push(msg);
      },
      replaceLastAssistantContent: replaceLastAssistantContent,
      appendMessage: appendMessage,
      setBubbleText: setBubbleText,
      removeWelcome: removeWelcome,
      ensureConversation: ensureConversation,
      setLoading: setLoading,
      showToast: showToast,
      loadConversations: loadConversations,
      clearAttachments: function () {
        attachments = [];
        imageAttachments = [];
        renderAttachments();
      },
      clearComposerQuote: clearComposerQuote,
    });
  }

  if (window.CampusChatAgents) {
    window.CampusChatAgents.install({
      cfg: cfg,
      showToast: showToast,
      getActiveAgentRef: function () {
        return activeAgentRef;
      },
      setActiveAgentRef: function (ref) {
        activeAgentRef = ref;
        syncAgentTopbarClear();
      },
      setActiveAgentProfile: function (agent) {
        activeAgentProfile = agent || null;
      },
      setModelId: function (id) {
        modelId = parseInt(id, 10) || modelId;
        if (modelSelect) modelSelect.value = String(modelId);
        syncModelPickerUi();
      },
      createConversation: createConversation,
      startNewChatWithAgent: startNewChatWithAgent,
      openAgentChat: openAgentChat,
      startNewChatWithCurrentModel: startNewChatWithCurrentModel,
      syncAgentTopbarClear: syncAgentTopbarClear,
      clearAgentContext: clearAgentContext,
    });
  }

  function syncMobileViewport() {
    if (!document.body.classList.contains('has-chat-mobile')) {
      document.documentElement.style.removeProperty('--keyboard-offset');
      return;
    }
    const vv = window.visualViewport;
    if (!vv) return;
    const kb = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
    document.documentElement.style.setProperty('--keyboard-offset', kb + 'px');
  }

  function initMobileChatLayout() {
    function applyMobileClass() {
      document.body.classList.toggle('has-chat-mobile', isMobileLayout());
      syncMobileViewport();
      onViewportLayoutChange();
    }
    applyMobileClass();
    mqSidebar.addEventListener('change', applyMobileClass);

    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', syncMobileViewport);
      window.visualViewport.addEventListener('scroll', syncMobileViewport);
    }
    window.addEventListener('resize', syncMobileViewport);

    promptEl?.addEventListener('focus', function () {
      if (!isMobileLayout()) return;
      requestAnimationFrame(function () {
        syncMobileViewport();
        scrollMessagesToBottom();
      });
      setTimeout(function () {
        syncMobileViewport();
        scrollMessagesToBottom();
      }, 320);
    });

    promptEl?.addEventListener('blur', function () {
      if (!isMobileLayout()) return;
      setTimeout(syncMobileViewport, 120);
    });
  }

  initMobileChatLayout();

  window.CampusChatCall = {
    sendMessage: sendCallModeMessage,
    showToast: showToast,
    abort: abortCallModeStream,
  };
})();
