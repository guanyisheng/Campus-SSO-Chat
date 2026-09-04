/**
 * Code blocks toolbar + split-screen programming workspace
 */
(function () {
  'use strict';

  let ctx = null;
  let wsState = { code: '', lang: '', mode: 'html' };

  const LANG_MAP = {
    js: 'javascript',
    javascript: 'javascript',
    ts: 'typescript',
    py: 'python',
    python: 'python',
    java: 'java',
    html: 'html',
    htm: 'html',
    php: 'php',
    c: 'c',
    'c++': 'cpp',
    cpp: 'cpp',
    go: 'go',
    sh: 'bash',
    bash: 'bash',
    shell: 'bash',
    text: 'plaintext',
    plaintext: 'plaintext',
  };

  const RUNNABLE_LANGS = new Set(['javascript', 'python', 'php', 'java', 'c', 'cpp', 'go', 'bash']);
  const PREVIEW_LANGS = new Set(['html', 'htm']);

  const ICON_COPY =
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';
  const ICON_QUOTE =
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M3 10a4 4 0 014-4h1V4H7a6 6 0 00-6 6v1h2V10zM14 10a4 4 0 014-4h1V4h-1a6 6 0 00-6 6v1h2v-1z"/></svg>';
  const ICON_EXPAND =
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>';
  const ICON_CHEVRON =
    '<svg class="code-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>';

  function c() {
    return ctx;
  }

  function $(id) {
    return document.getElementById(id);
  }

  function normalizeLang(raw) {
    if (!raw) return '';
    const key = String(raw).toLowerCase().trim();
    return LANG_MAP[key] || key;
  }

  function displayLang(lang) {
    const n = normalizeLang(lang);
    return n || 'plaintext';
  }

  function isHtmlLang(lang) {
    return PREVIEW_LANGS.has(lang);
  }

  function isRunnableLang(lang) {
    return RUNNABLE_LANGS.has(lang);
  }

  /** @returns {{type:'text'|'code', content:string, lang?:string, open?:boolean}[]} */
  function splitMarkdownAndCode(text) {
    const parts = [];
    const s = String(text || '');
    let i = 0;

    while (i < s.length) {
      const fenceStart = s.indexOf('```', i);
      if (fenceStart === -1) {
        const tail = s.slice(i);
        if (tail) parts.push({ type: 'text', content: tail });
        break;
      }

      if (fenceStart > i) {
        parts.push({ type: 'text', content: s.slice(i, fenceStart) });
      }

      let pos = fenceStart + 3;
      let lang = '';
      const langLineEnd = s.indexOf('\n', pos);

      if (langLineEnd === -1) {
        lang = s.slice(pos).trim();
        parts.push({ type: 'code', lang: normalizeLang(lang), content: '', open: true });
        break;
      }

      lang = s.slice(pos, langLineEnd).trim();
      pos = langLineEnd + 1;

      const closeFence = s.indexOf('```', pos);
      if (closeFence === -1) {
        parts.push({
          type: 'code',
          lang: normalizeLang(lang),
          content: s.slice(pos),
          open: true,
        });
        break;
      }

      parts.push({
        type: 'code',
        lang: normalizeLang(lang),
        content: s.slice(pos, closeFence),
        open: false,
      });
      i = closeFence + 3;
      if (s[i] === '\n') i += 1;
    }

    return parts;
  }

  function getCodeFromWrap(wrap) {
    const codeEl = wrap.querySelector('code');
    return codeEl ? codeEl.textContent : '';
  }

  function setCodeBlockCollapsed(wrap, collapsed) {
    if (!wrap) return;
    wrap.classList.toggle('is-collapsed', collapsed);
    const btn = wrap.querySelector('.btn-code-collapse');
    if (btn) btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
  }

  function wireCodeBlockActions(wrap, lang) {
    const isHtml = isHtmlLang(lang);
    const isRun = isRunnableLang(lang);

    wrap.querySelector('.btn-code-primary')?.addEventListener('click', function () {
      const code = getCodeFromWrap(wrap);
      if (isHtml) {
        openWorkspace(code, lang, { tab: 'preview', autoPreview: true });
      } else {
        openWorkspace(code, lang, { tab: 'code' });
        void runWorkspaceCode();
      }
    });

    wrap.querySelector('.btn-code-copy')?.addEventListener('click', function () {
      const code = getCodeFromWrap(wrap);
      c().copyToClipboard(code).then(function (ok) {
        c().showToast(ok ? '已复制代码' : '复制失败');
      });
    });

    wrap.querySelector('.btn-code-quote')?.addEventListener('click', function () {
      quoteCode(getCodeFromWrap(wrap), lang);
    });

    wrap.querySelector('.btn-code-expand')?.addEventListener('click', function () {
      const code = getCodeFromWrap(wrap);
      openWorkspace(code, lang, {
        tab: isHtml ? 'preview' : 'code',
        autoPreview: isHtml,
      });
    });

    wrap.querySelector('.btn-code-collapse')?.addEventListener('click', function () {
      setCodeBlockCollapsed(wrap, !wrap.classList.contains('is-collapsed'));
    });
  }

  function buildCodeBlockWrap(lang, code, options) {
    options = options || {};
    const isHtml = isHtmlLang(lang);
    const isRun = isRunnableLang(lang);
    const label = displayLang(lang);

    const wrap = document.createElement('div');
    wrap.className = 'code-block-wrap';
    if (options.streaming) wrap.classList.add('is-streaming');
    if (options.collapsed) wrap.classList.add('is-collapsed');
    wrap.dataset.codeLang = lang;
    if (options.streamIdx != null) wrap.dataset.streamIdx = String(options.streamIdx);

    const head = document.createElement('div');
    head.className = 'code-block-head';

    const headLeft = document.createElement('div');
    headLeft.className = 'code-block-head-left';

    const langSpan = document.createElement('span');
    langSpan.className = 'code-block-lang';
    langSpan.textContent = label;

    const btnCollapse = document.createElement('button');
    btnCollapse.type = 'button';
    btnCollapse.className = 'btn-code-collapse';
    btnCollapse.title = '折叠';
    btnCollapse.setAttribute('aria-label', '折叠代码块');
    btnCollapse.setAttribute('aria-expanded', options.collapsed ? 'false' : 'true');
    btnCollapse.innerHTML = ICON_CHEVRON;

    headLeft.appendChild(langSpan);
    headLeft.appendChild(btnCollapse);

    const actions = document.createElement('div');
    actions.className = 'code-block-actions';

    if (isHtml || isRun) {
      const btnPrimary = document.createElement('button');
      btnPrimary.type = 'button';
      btnPrimary.className = 'btn-code-primary';
      btnPrimary.innerHTML =
        '<span class="btn-code-primary__play" aria-hidden="true">▶</span>' + (isHtml ? '预览' : '运行');
      actions.appendChild(btnPrimary);
    }

    const btnCopy = document.createElement('button');
    btnCopy.type = 'button';
    btnCopy.className = 'btn-code-icon btn-code-copy';
    btnCopy.title = '复制';
    btnCopy.setAttribute('aria-label', '复制');
    btnCopy.innerHTML = ICON_COPY;
    actions.appendChild(btnCopy);

    const btnQuote = document.createElement('button');
    btnQuote.type = 'button';
    btnQuote.className = 'btn-code-icon btn-code-quote';
    btnQuote.title = '引用';
    btnQuote.setAttribute('aria-label', '引用代码');
    btnQuote.innerHTML = ICON_QUOTE;
    actions.appendChild(btnQuote);

    const btnExpand = document.createElement('button');
    btnExpand.type = 'button';
    btnExpand.className = 'btn-code-icon btn-code-expand';
    btnExpand.title = '全屏';
    btnExpand.setAttribute('aria-label', '全屏打开');
    btnExpand.innerHTML = ICON_EXPAND;
    actions.appendChild(btnExpand);

    head.appendChild(headLeft);
    head.appendChild(actions);

    const body = document.createElement('div');
    body.className = 'code-block-body';

    const pre = document.createElement('pre');
    const codeEl = document.createElement('code');
    if (lang) codeEl.className = 'language-' + lang;
    codeEl.textContent = code || '';
    if (options.streaming && options.showCursor) {
      const cur = document.createElement('span');
      cur.className = 'code-stream-cursor';
      cur.textContent = '|';
      codeEl.appendChild(cur);
    }
    pre.appendChild(codeEl);
    body.appendChild(pre);

    wrap.appendChild(head);
    wrap.appendChild(body);
    wireCodeBlockActions(wrap, lang);
    return wrap;
  }

  function renderTextPart(content) {
    const div = document.createElement('div');
    div.className = 'stream-text-part';
    if (typeof renderMarkdown === 'function') {
      const html = renderMarkdown(content);
      div.innerHTML = html.replace(/^<div class="md-body">|<\/div>$/g, '');
    } else {
      div.textContent = content;
    }
    return div;
  }

  function collectCollapseState(host) {
    const state = {};
    host.querySelectorAll('.code-block-wrap[data-stream-idx]').forEach(function (wrap) {
      state[wrap.dataset.streamIdx] = wrap.classList.contains('is-collapsed');
    });
    return state;
  }

  function renderStreamingContent(host, text, options) {
    if (!host) return;
    options = options || {};
    const collapseState = collectCollapseState(host);
    const parts = splitMarkdownAndCode(text);
    const hasOpenCode = parts.some(function (p) {
      return p.type === 'code' && p.open;
    });

    const root = document.createElement('div');
    root.className = 'md-body stream-md-body';

    parts.forEach(function (part, idx) {
      if (part.type === 'text') {
        if (!part.content) return;
        if (!options.streaming && !part.content.trim()) return;
        root.appendChild(renderTextPart(part.content));
        return;
      }

      const isLastCode = idx === parts.length - 1 && part.type === 'code';
      root.appendChild(
        buildCodeBlockWrap(part.lang, part.content, {
          streamIdx: idx,
          streaming: !!options.streaming && part.open,
          collapsed: !!collapseState[idx],
          showCursor: !!options.streaming && part.open && isLastCode && hasOpenCode,
        })
      );
    });

    if (options.streaming && !hasOpenCode) {
      const cursor = document.createElement('span');
      cursor.className = 'stream-text-cursor';
      cursor.setAttribute('aria-hidden', 'true');
      cursor.textContent = '|';
      root.appendChild(cursor);
    }

    host.innerHTML = '';
    host.appendChild(root);
  }

  function openWorkspace(code, lang, options) {
    options = options || {};
    if (window.CampusLessonPlan && typeof window.CampusLessonPlan.close === 'function') {
      window.CampusLessonPlan.close();
    }
    const ws = $('code-workspace');
    if (!ws) return;

    wsState.code = code;
    wsState.lang = lang;
    wsState.mode = isHtmlLang(lang) ? 'html' : isRunnableLang(lang) ? 'run' : 'view';

    const source = $('code-ws-source');
    const langEl = $('code-ws-lang');
    const hint = $('code-ws-hint');
    const body = $('code-ws-body');
    const tabPreview = $('code-ws-tab-preview');
    const btnRun = $('code-ws-run');
    const btnPreview = $('code-ws-preview-btn');
    const consoleEl = $('code-ws-console');
    const consoleOut = $('code-ws-console-out');

    if (source) source.textContent = code;
    if (langEl) langEl.textContent = lang || 'code';
    if (consoleOut) {
      consoleOut.textContent = '';
      consoleOut.classList.remove('is-error');
    }

    const isHtml = wsState.mode === 'html';
    const isRun = wsState.mode === 'run';

    if (hint) {
      hint.textContent = isHtml
        ? lang + ' 本地预览，注意内容合规与信息安全'
        : isRun
          ? lang + ' 代码运行，部分依赖或交互可能受限'
          : lang + ' 代码查看';
    }

    if (tabPreview) tabPreview.hidden = !isHtml;
    if (btnRun) btnRun.hidden = !isRun;
    if (btnPreview) btnPreview.hidden = !isHtml;
    if (consoleEl) consoleEl.hidden = !isRun;
    if (body) {
      body.classList.toggle('is-run-layout', isRun);
    }

    ws.hidden = false;
    document.body.classList.add('has-code-workspace');

    if (options.tab === 'preview' && isHtml) {
      setWorkspaceTab('preview');
      renderHtmlPreview(code);
    } else if (options.tab === 'console' && isRun) {
      setWorkspaceTab('code');
    } else {
      setWorkspaceTab(isHtml && options.autoPreview ? 'preview' : 'code');
      if (isHtml && options.autoPreview) renderHtmlPreview(code);
    }

    if (window.renderIcons) window.renderIcons(ws);
  }

  function closeWorkspace() {
    const ws = $('code-workspace');
    if (ws) ws.hidden = true;
    document.body.classList.remove('has-code-workspace');
    const frame = $('code-ws-preview-frame');
    if (frame) frame.removeAttribute('srcdoc');
  }

  function setWorkspaceTab(tab) {
    document.querySelectorAll('.code-ws-tab').forEach(function (btn) {
      const on = btn.getAttribute('data-tab') === tab;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
      if (btn.getAttribute('data-tab') === 'preview') btn.hidden = !isHtmlLang(wsState.lang);
    });
    document.querySelectorAll('.code-ws-panel').forEach(function (panel) {
      const on = panel.getAttribute('data-panel') === tab;
      panel.classList.toggle('is-active', on);
      panel.hidden = !on;
    });
    if (tab === 'preview' && isHtmlLang(wsState.lang)) {
      renderHtmlPreview(wsState.code);
    }
  }

  function renderHtmlPreview(html) {
    const frame = $('code-ws-preview-frame');
    if (!frame) return;
    frame.srcdoc = html;
  }

  async function runWorkspaceCode() {
    const lang = wsState.lang;
    const code = wsState.code;
    const out = $('code-ws-console-out');
    if (!out) return;

    out.hidden = false;
    out.classList.remove('is-error');
    out.textContent = '运行中…';

    try {
      if (lang === 'javascript') {
        runJavaScriptLocal(code, out);
      } else if (c().cfg.runCodeUrl) {
        await runCodeRemote(lang, code, out);
      } else {
        out.textContent = '运行服务未配置';
        out.classList.add('is-error');
      }
    } catch (e) {
      out.textContent = e.message || '运行失败';
      out.classList.add('is-error');
    }
  }

  function runJavaScriptLocal(code, outputEl) {
    const logs = [];
    const origLog = console.log;
    const origErr = console.error;
    console.log = function () {
      logs.push(Array.from(arguments).map(String).join(' '));
    };
    console.error = function () {
      logs.push('[error] ' + Array.from(arguments).map(String).join(' '));
    };
    try {
      const fn = new Function(code);
      const ret = fn();
      if (ret !== undefined) logs.push(String(ret));
    } catch (e) {
      logs.push('Error: ' + (e.message || e));
      outputEl.classList.add('is-error');
    } finally {
      console.log = origLog;
      console.error = origErr;
    }
    outputEl.textContent = logs.join('\n') || '(无输出)';
  }

  async function runCodeRemote(lang, code, outputEl) {
    const res = await fetch(c().cfg.runCodeUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ language: lang, code: code }),
    });
    const data = await res.json().catch(function () {
      return {};
    });
    if (!res.ok) {
      outputEl.classList.add('is-error');
      let errText = data.error || '运行失败';
      if (data.stderr) errText += '\n' + data.stderr;
      else if (data.stdout) errText += '\n' + data.stdout;
      outputEl.textContent = errText.trim();
      return;
    }
    let text = (data.stdout || '') + (data.stderr ? ((data.stdout ? '\n' : '') + data.stderr) : '');
    if (data.exit_code !== 0 && data.exit_code !== null && text === '') {
      text = '退出码: ' + data.exit_code;
    }
    if (data.exit_code !== 0 && data.exit_code !== null) {
      outputEl.classList.add('is-error');
    }
    outputEl.textContent = text || '(无输出)';
  }

  function quoteCode(code, lang) {
    if (typeof c().quoteCode === 'function') {
      c().quoteCode(code, lang);
      return;
    }
    c().showToast('引用失败');
  }

  function enhanceBubble(bubble) {
    if (!bubble) return;
    bubble.querySelectorAll('pre').forEach(function (pre) {
      if (pre.closest('.code-block-wrap')) return;

      const codeEl = pre.querySelector('code');
      const code = codeEl ? codeEl.textContent : pre.textContent || '';
      const langMatch = codeEl && codeEl.className.match(/language-([\w+#-]+)/i);
      const lang = normalizeLang(langMatch ? langMatch[1] : '');

      const wrap = buildCodeBlockWrap(lang, code, {});
      const body = wrap.querySelector('.code-block-body');
      const generatedPre = body && body.querySelector('pre');
      if (generatedPre) generatedPre.remove();
      const parent = pre.parentNode;
      parent.insertBefore(wrap, pre);
      if (body) body.appendChild(pre);
    });
  }

  function bindWorkspaceUi() {
    $('code-ws-close')?.addEventListener('click', closeWorkspace);
    $('code-ws-copy')?.addEventListener('click', function () {
      c().copyToClipboard(wsState.code).then(function (ok) {
        c().showToast(ok ? '已复制代码' : '复制失败');
      });
    });
    $('code-ws-run')?.addEventListener('click', function () {
      void runWorkspaceCode();
    });
    $('code-ws-preview-btn')?.addEventListener('click', function () {
      setWorkspaceTab('preview');
      renderHtmlPreview(wsState.code);
    });
    $('code-ws-console-clear')?.addEventListener('click', function () {
      const out = $('code-ws-console-out');
      if (out) {
        out.textContent = '';
        out.classList.remove('is-error');
      }
    });
    document.querySelectorAll('.code-ws-tab').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setWorkspaceTab(btn.getAttribute('data-tab') || 'code');
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && document.body.classList.contains('has-code-workspace')) {
        closeWorkspace();
      }
    });
  }

  function install(context) {
    ctx = context;
    bindWorkspaceUi();
  }

  window.CampusCodeWorkspace = {
    install: install,
    enhanceBubble: enhanceBubble,
    renderStreamingContent: renderStreamingContent,
    splitMarkdownAndCode: splitMarkdownAndCode,
    open: openWorkspace,
    close: closeWorkspace,
  };
})();
