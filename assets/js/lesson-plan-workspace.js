(function () {
  'use strict';

  var cfg = window.CAMPUS_CHAT || {};
  var ws = null;
  var iframe = null;
  var floatBtn = null;
  var statusEl = null;
  var sidebarBtn = null;
  var wsState = { running: false, done: false, progress: 0, message: '' };
  var iframeReady = false;
  var doneNotified = false;

  function $(id) {
    return document.getElementById(id);
  }

  function getTheme() {
    return document.documentElement.getAttribute('data-theme') || 'dark';
  }

  function embedUrl() {
    if (cfg.lessonPlanEmbedUrl) return cfg.lessonPlanEmbedUrl;
    var base = String(cfg.chatPageUrl || '/chat.php').replace(/\?.*$/, '').replace(/\/chat\.php$/, '');
    if (base.indexOf('chat.php') >= 0) {
      return base.replace('chat.php', 'lesson-plan.php') + '?embed=1';
    }
    return (base || '') + '/lesson-plan.php?embed=1';
  }

  function postToIframe(payload) {
    if (!iframe || !iframe.contentWindow || !iframeReady) return;
    try {
      iframe.contentWindow.postMessage(
        Object.assign({ source: 'campus-chat' }, payload),
        window.location.origin
      );
    } catch (_) {}
  }

  function syncThemeToIframe() {
    postToIframe({ type: 'lesson-plan:theme', theme: getTheme() });
  }

  function updateHeader() {
    if (!statusEl) return;
    statusEl.classList.remove('is-running', 'is-done');
    if (wsState.running) {
      statusEl.textContent =
        wsState.message ||
        ('生成中' + (wsState.progress > 0 ? ' ' + wsState.progress + '%' : '') + '（可挂起）');
      statusEl.classList.add('is-running');
      return;
    }
    if (wsState.done) {
      statusEl.textContent = wsState.message || '生成完成，可导出 docx';
      statusEl.classList.add('is-done');
      return;
    }
    statusEl.textContent = '填写课次后可预览或 AI 生成';
  }

  function updateFloat() {
    if (!floatBtn) return;
    var workspaceOpen = document.body.classList.contains('has-lesson-plan-workspace');
    var show = !workspaceOpen && (wsState.running || wsState.done);
    floatBtn.hidden = !show;
    if (!show) return;
    floatBtn.classList.toggle('is-running', !!wsState.running);
    floatBtn.classList.toggle('is-done', !!wsState.done && !wsState.running);
    if (wsState.running) {
      floatBtn.textContent =
        '教案生成中' + (wsState.progress > 0 ? ' ' + wsState.progress + '%' : '') + ' · 点击打开';
    } else {
      floatBtn.textContent = '教案已完成 · 点击导出';
    }
  }

  function setSidebarActive(on) {
    if (!sidebarBtn) return;
    sidebarBtn.classList.toggle('is-lesson-active', !!on);
  }

  function ensureIframe() {
    if (!iframe) return;
    if (iframe.getAttribute('src')) return;
    iframe.setAttribute('src', embedUrl());
  }

  function openWorkspace() {
    if (!cfg.enableLessonPlan) return;
    if (window.CampusCodeWorkspace && typeof window.CampusCodeWorkspace.close === 'function') {
      window.CampusCodeWorkspace.close();
    }
    ensureIframe();
    document.body.classList.add('has-lesson-plan-workspace');
    if (ws) ws.hidden = false;
    setSidebarActive(true);
    updateFloat();
    syncThemeToIframe();
    if (window.renderIcons && ws) window.renderIcons(ws);
    if (window.CampusStaggeredSidebar && typeof window.CampusStaggeredSidebar.close === 'function') {
      window.CampusStaggeredSidebar.close();
    }
  }

  function closeWorkspace() {
    document.body.classList.remove('has-lesson-plan-workspace');
    if (ws) ws.hidden = true;
    setSidebarActive(wsState.running || wsState.done);
    updateFloat();
  }

  function toast(msg) {
    if (typeof window.toast === 'function') {
      window.toast(msg);
      return;
    }
    var el = document.getElementById('chat-toast');
    if (!el) return;
    el.textContent = msg;
    el.hidden = false;
    el.classList.add('is-visible');
    window.setTimeout(function () {
      el.classList.remove('is-visible');
      window.setTimeout(function () {
        el.hidden = true;
      }, 280);
    }, 3200);
  }

  function onIframeMessage(e) {
    if (e.origin !== window.location.origin) return;
    var data = e.data;
    if (!data || data.source !== 'campus-lesson-plan') return;

    if (data.type === 'ready') {
      iframeReady = true;
      syncThemeToIframe();
      return;
    }

    if (data.type === 'status') {
      wsState.running = !!data.running;
      wsState.done = !!data.done;
      wsState.progress = parseInt(data.progress, 10) || 0;
      wsState.message = String(data.message || '');
      if (wsState.running) doneNotified = false;
      updateHeader();
      updateFloat();
      setSidebarActive(document.body.classList.contains('has-lesson-plan-workspace') || wsState.running || wsState.done);

      if (
        wsState.done &&
        !wsState.running &&
        !doneNotified &&
        !document.body.classList.contains('has-lesson-plan-workspace')
      ) {
        doneNotified = true;
        toast('教案 AI 生成已完成，点击右下角打开导出');
      }

      if (data.quota && window.renderQuotaBars) {
        var host = document.getElementById('sidebar-quota');
        if (host) window.renderQuotaBars(host, data.quota);
      }
    }
  }

  function bindThemeSync() {
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        window.setTimeout(syncThemeToIframe, 0);
      });
    });
    try {
      var obs = new MutationObserver(function () {
        syncThemeToIframe();
      });
      obs.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    } catch (_) {}
  }

  function init() {
    if (!cfg.enableLessonPlan) return;

    ws = $('lesson-plan-workspace');
    iframe = $('lesson-plan-iframe');
    floatBtn = $('lesson-plan-float');
    statusEl = $('lesson-plan-ws-status');
    sidebarBtn = $('btn-open-lesson-plan');

    $('btn-open-lesson-plan')?.addEventListener('click', function (e) {
      e.preventDefault();
      openWorkspace();
    });

    $('lesson-plan-ws-close')?.addEventListener('click', function () {
      closeWorkspace();
    });

    $('lesson-plan-ws-minimize')?.addEventListener('click', function () {
      closeWorkspace();
    });

    floatBtn?.addEventListener('click', function () {
      openWorkspace();
    });

    window.addEventListener('message', onIframeMessage);
    bindThemeSync();
    updateHeader();
    updateFloat();
  }

  window.CampusLessonPlan = {
    open: openWorkspace,
    close: closeWorkspace,
    isOpen: function () {
      return document.body.classList.contains('has-lesson-plan-workspace');
    },
    getState: function () {
      return Object.assign({}, wsState);
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
