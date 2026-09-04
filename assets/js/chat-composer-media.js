/**
 * Composer image / video generation mode
 */
(function () {
  'use strict';

  /** @type {Record<string, unknown> | null} */
  let ctx = null;

  let composerMediaMode = null;
  let imageRatioIdx = 1;
  let imageStyleKey = 'default';
  let imageModelKey = 'pony_v6';
  let imageSize = '1024x768';
  /** @type {string} 优化后的英文提示词，仅用于提交生图 */
  let imagePromptGen = '';
  /** @type {string} 上次优化时对应的中文原文 */
  let imagePromptOptimizedFrom = '';
  let imagePromptOptimizing = false;
  let videoRatioIdx = 1;
  /** @type {{url:string, name?:string}[]} */
  let mediaRefImages = [];
  let mediaRefUploading = false;

  const IMAGE_RATIO_OPTIONS = [
    { label: '1:1', size: '1024x1024' },
    { label: '4:3', size: '1024x768' },
    { label: '3:4', size: '768x1024' },
    { label: '16:9', size: '1280x720' },
    { label: '9:16', size: '720x1280' },
  ];

  const IMAGE_STYLE_OPTIONS = [
    { key: 'default', label: '默认' },
    { key: 'portrait', label: '人像摄影' },
    { key: 'cinematic', label: '电影写真' },
    { key: 'chinese', label: '中国风' },
    { key: 'anime', label: '动漫' },
    { key: '3d', label: '3D渲染' },
    { key: 'cyberpunk', label: '赛博朋克' },
    { key: 'cg', label: 'CG 动画' },
    { key: 'ink', label: '水墨画' },
    { key: 'oil', label: '油画' },
    { key: 'classical', label: '古典' },
    { key: 'watercolor', label: '水彩画' },
    { key: 'cartoon', label: '卡通' },
  ];

  function imageModelOptions() {
    const fromCfg = c().cfg.imageModels;
    if (Array.isArray(fromCfg) && fromCfg.length) {
      return fromCfg.map(function (m) {
        return { key: String(m.key || ''), label: String(m.label || m.key || '') };
      }).filter(function (m) {
        return m.key !== '';
      });
    }
    return [
      { key: 'pony_v6', label: 'Pony V6 XL' },
      { key: 'juggernaut_xl_v8', label: 'Juggernaut XL v8' },
    ];
  }

  function normalizeImageModelKey(key) {
    const options = imageModelOptions();
    const found = options.find(function (m) {
      return m.key === key;
    });
    if (found) return found.key;
    const fallback = String(c().cfg.imageModelDefault || 'pony_v6');
    return options.some(function (m) {
      return m.key === fallback;
    })
      ? fallback
      : options[0]?.key || 'pony_v6';
  }

  const VIDEO_RATIO_OPTIONS = [
    { label: '16:9', width: 1280, height: 720 },
    { label: '9:16', width: 720, height: 1280 },
    { label: '4:3', width: 1152, height: 768 },
    { label: '1:1', width: 1024, height: 1024 },
  ];

  function c() {
    return ctx;
  }

  function detectMentionPrefix(text, aliases) {
    if (!text || !aliases || !aliases.length) return null;
    const trimmed = text.trimStart();
    const lower = trimmed.toLowerCase();
    for (let i = 0; i < aliases.length; i++) {
      const alias = String(aliases[i] || '').trim();
      if (!alias) continue;
      if (lower.indexOf(alias.toLowerCase()) === 0) return alias;
    }
    return null;
  }

  function detectImageMentionPrefix(text) {
    return detectMentionPrefix(text, (c().cfg.imageMentions || ['@图片']));
  }

  function detectVideoMentionPrefix(text) {
    return detectMentionPrefix(text, (c().cfg.videoMentions || ['@视频']));
  }

  function parseUserMention(text) {
    const trimmed = (text || '').trim();
    if (!trimmed) return null;

    const imgAlias = detectImageMentionPrefix(trimmed);
    if (imgAlias && c().cfg.enableImage !== false) {
      const prompt = trimmed.slice(imgAlias.length).trim();
      if (!prompt) return { type: 'image', prompt: '', empty: true };
      return { type: 'image', prompt: prompt };
    }

    const vidAlias = detectVideoMentionPrefix(trimmed);
    if (vidAlias && c().cfg.enableVideo !== false) {
      const prompt = trimmed.slice(vidAlias.length).trim();
      if (!prompt) return { type: 'video', prompt: '', empty: true };
      return { type: 'video', prompt: prompt };
    }

    return null;
  }

  function getMediaSubmitIntent(text) {
    const mention = parseUserMention(text);
    if (mention) return mention;
    const trimmed = (text || '').trim();
    if (!trimmed) return null;
    if (composerMediaMode === 'image' && c().cfg.enableImage !== false) {
      return { type: 'image', prompt: trimmed };
    }
    if (composerMediaMode === 'video' && c().cfg.enableVideo !== false) {
      return { type: 'video', prompt: trimmed };
    }
    return null;
  }

  function defaultAlias(type) {
    const list = type === 'image' ? c().cfg.imageMentions : c().cfg.videoMentions;
    return (list && list[0]) || (type === 'image' ? '@图片' : '@视频');
  }

  function getImagePromptRaw() {
    let text = (c().promptEl?.value || '').trim();
    const alias = detectMentionPrefix(text, c().cfg.imageMentions || ['@图片']);
    if (alias) {
      text = text.slice(alias.length).trim();
    }
    return text;
  }

  function syncImagePromptOptimizeUi() {
    const btn = document.getElementById('btn-image-prompt-optimize');
    if (!btn) return;
    btn.classList.toggle('is-ready', composerMediaMode === 'image' && imagePromptGen !== '');
  }

  async function optimizeImagePrompt() {
    if (imagePromptOptimizing) return;
    const raw = getImagePromptRaw();
    if (!raw) {
      c().showToast('请先输入图片描述');
      return;
    }
    const url = c().cfg.imagePromptOptimizeUrl;
    if (!url) {
      c().showToast('未配置提示词优化接口');
      return;
    }

    const btn = document.getElementById('btn-image-prompt-optimize');
    imagePromptOptimizing = true;
    if (btn) {
      btn.disabled = true;
      btn.classList.add('is-loading');
      btn.classList.remove('is-ready');
    }
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ prompt: raw }),
      });
      const data = await res.json().catch(function () {
        return {};
      });
      if (!res.ok) {
        throw new Error(data.error || '优化失败');
      }
      imagePromptGen = String(data.optimized || '').trim();
      imagePromptOptimizedFrom = raw;
      if (!imagePromptGen) {
        throw new Error('优化结果为空');
      }
      syncImagePromptOptimizeUi();
      c().showToast(data.translated ? '已优化，提交时将使用英文生图' : '已优化，提交时将使用润色后的英文');
    } catch (e) {
      imagePromptGen = '';
      imagePromptOptimizedFrom = '';
      syncImagePromptOptimizeUi();
      c().showToast(e.message || '优化失败');
    } finally {
      imagePromptOptimizing = false;
      if (btn) {
        btn.disabled = false;
        btn.classList.remove('is-loading');
        syncImagePromptOptimizeUi();
      }
    }
  }

  function enterMediaMode(mode) {
    if (mode === 'image' && c().cfg.enableImage === false) return;
    if (mode === 'video' && c().cfg.enableVideo === false) return;
    if (window.CampusComposerCode) window.CampusComposerCode.exitCodeMode();

    if (composerMediaMode && composerMediaMode !== mode) clearMediaRefImages();
    composerMediaMode = mode;

    const wrap = c().composerWrap;
    wrap?.classList.toggle('is-image-mode', mode === 'image');
    wrap?.classList.toggle('is-video-mode', mode === 'video');

    if (c().composerLeftDefault) c().composerLeftDefault.hidden = true;
    if (c().composerImageTools) c().composerImageTools.hidden = mode !== 'image';
    if (c().composerVideoTools) c().composerVideoTools.hidden = mode !== 'video';

    const alias = defaultAlias(mode);
    const val = c().promptEl.value || '';
    if (!detectMentionPrefix(val, mode === 'image' ? c().cfg.imageMentions : c().cfg.videoMentions)) {
      c().promptEl.value = alias + (val.trim() ? ' ' + val.trim() : ' ');
    }
    c().promptEl.focus();
    if (window.renderIcons) window.renderIcons(wrap || document);
  }

  function stripMentionFromPrompt(type) {
    const aliases = type === 'image' ? c().cfg.imageMentions : c().cfg.videoMentions;
    const alias = detectMentionPrefix(c().promptEl.value, aliases);
    if (!alias) return;
    c().promptEl.value = c().promptEl.value.trim().slice(alias.length).trim();
  }

  function clearImagePromptGen() {
    imagePromptGen = '';
    imagePromptOptimizedFrom = '';
    syncImagePromptOptimizeUi();
  }

  function exitMediaMode() {
    composerMediaMode = null;
    clearImagePromptGen();
    c().composerWrap?.classList.remove('is-image-mode', 'is-video-mode');
    if (c().composerLeftDefault) c().composerLeftDefault.hidden = false;
    if (c().composerImageTools) c().composerImageTools.hidden = true;
    if (c().composerVideoTools) c().composerVideoTools.hidden = true;
    stripMentionFromPrompt('image');
    stripMentionFromPrompt('video');
    clearMediaRefImages();
    if (typeof c().clearComposerQuote === 'function') c().clearComposerQuote();
  }

  function syncComposerMediaUi() {
    const img = detectImageMentionPrefix(c().promptEl.value);
    const vid = detectVideoMentionPrefix(c().promptEl.value);
    if (img && c().cfg.enableImage !== false) {
      if (composerMediaMode !== 'image') enterMediaMode('image');
      return;
    }
    if (vid && c().cfg.enableVideo !== false) {
      if (composerMediaMode !== 'video') enterMediaMode('video');
    }
  }

  function renderMediaRefStrip() {
    const strip = c().mediaRefStrip;
    if (!strip) return;
    strip.innerHTML = '';
    if (!mediaRefImages.length) {
      strip.hidden = true;
      return;
    }
    strip.hidden = false;
    mediaRefImages.forEach(function (item, idx) {
      const el = document.createElement('div');
      el.className = 'media-ref-item';
      el.innerHTML =
        '<img src="' + escapeAttr(item.url) + '" alt="参考图">' +
        '<button type="button" class="media-ref-item__remove" aria-label="移除">×</button>';
      el.querySelector('.media-ref-item__remove')?.addEventListener('click', function () {
        mediaRefImages.splice(idx, 1);
        renderMediaRefStrip();
      });
      strip.appendChild(el);
    });
  }

  function clearMediaRefImages() {
    mediaRefImages = [];
    renderMediaRefStrip();
  }

  function addMediaRefFromUrl(url, name) {
    if (!url) return;
    if (mediaRefImages.some(function (i) {
      return i.url === url;
    })) {
      return;
    }
    if (mediaRefImages.length >= 3) {
      c().showToast('最多 3 张参考图');
      return;
    }
    mediaRefImages.push({ url: url, name: name || '引用图片' });
    renderMediaRefStrip();
  }

  function enterMediaModePublic(mode) {
    enterMediaMode(mode);
  }

  function getMediaRefUrls() {
    return mediaRefImages.map(function (i) {
      return i.url;
    });
  }

  function escapeAttr(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  async function uploadMediaRef(file) {
    const fd = new FormData();
    fd.append('file', file);
    const res = await fetch(c().cfg.mediaRefUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
    });
    const data = await res.json().catch(function () {
      return {};
    });
    if (!res.ok) throw new Error(data.error || '参考图上传失败');
    return data.url;
  }

  function buildPopoverItems(pickerId, options, onPick) {
    const picker = document.getElementById(pickerId);
    if (!picker) return;
    picker.innerHTML = '';
    options.forEach(function (opt, idx) {
      const item = document.createElement('div');
      item.className = 'c-popover__item' + (opt.active ? ' is-active' : '');
      item.innerHTML =
        '<div class="c-popover__item__main"><div class="c-popover__item__title">' +
        escapeAttr(opt.title) +
        '</div></div><span class="c-popover__item__check" data-icon="check"></span>';
      item.addEventListener('click', function () {
        onPick(opt, idx);
        picker.classList.remove('is-open');
      });
      picker.appendChild(item);
    });
    if (window.renderIcons) window.renderIcons(picker);
  }

  function syncImageOptionLabels() {
    const ratio = IMAGE_RATIO_OPTIONS[imageRatioIdx] || IMAGE_RATIO_OPTIONS[1];
    imageSize = ratio.size;
    const ratioLabel = document.getElementById('image-ratio-label');
    const sizeLabel = document.getElementById('image-size-label');
    const styleLabel = document.getElementById('image-style-label');
    const modelLabel = document.getElementById('image-model-label');
    if (ratioLabel) ratioLabel.textContent = '比例 ' + ratio.label;
    if (sizeLabel) sizeLabel.textContent = ratio.size.replace('x', '×');
    const style = IMAGE_STYLE_OPTIONS.find(function (s) {
      return s.key === imageStyleKey;
    });
    if (styleLabel) styleLabel.textContent = style ? style.label : '风格';
    const model = imageModelOptions().find(function (m) {
      return m.key === imageModelKey;
    });
    if (modelLabel) modelLabel.textContent = model ? model.label : '生成模型';
  }

  function syncVideoOptionLabels() {
    const ratio = VIDEO_RATIO_OPTIONS[videoRatioIdx] || VIDEO_RATIO_OPTIONS[0];
    const label = document.getElementById('video-ratio-label');
    if (label) label.textContent = '比例 ' + ratio.label;
  }

  function initImageGenOptions() {
    imageModelKey = normalizeImageModelKey(imageModelKey);
    syncImageOptionLabels();
    buildPopoverItems(
      'imageModelPicker',
      imageModelOptions().map(function (m) {
        return { title: m.label, active: m.key === imageModelKey, key: m.key };
      }),
      function (opt) {
        imageModelKey = normalizeImageModelKey(opt.key || imageModelKey);
        syncImageOptionLabels();
        initImageGenOptions();
      }
    );
    buildPopoverItems(
      'imageRatioPicker',
      IMAGE_RATIO_OPTIONS.map(function (r, i) {
        return { title: r.label + ' · ' + r.size.replace('x', '×'), active: i === imageRatioIdx };
      }),
      function (_opt, idx) {
        imageRatioIdx = idx;
        syncImageOptionLabels();
        initImageGenOptions();
      }
    );
    buildPopoverItems(
      'imageStylePicker',
      IMAGE_STYLE_OPTIONS.map(function (s) {
        return { title: s.label, active: s.key === imageStyleKey, key: s.key };
      }),
      function (opt) {
        imageStyleKey = opt.key || 'default';
        syncImageOptionLabels();
        initImageGenOptions();
      }
    );
    buildPopoverItems(
      'imageSizePicker',
      IMAGE_RATIO_OPTIONS.map(function (r, i) {
        return { title: r.size.replace('x', '×'), active: i === imageRatioIdx };
      }),
      function (_opt, idx) {
        imageRatioIdx = idx;
        syncImageOptionLabels();
        initImageGenOptions();
      }
    );
  }

  function initVideoGenOptions() {
    syncVideoOptionLabels();
    buildPopoverItems(
      'videoRatioPicker',
      VIDEO_RATIO_OPTIONS.map(function (r, i) {
        return { title: r.label, active: i === videoRatioIdx };
      }),
      function (_opt, idx) {
        videoRatioIdx = idx;
        syncVideoOptionLabels();
        initVideoGenOptions();
      }
    );
  }

  function initMediaRefUpload() {
    c().btnImageRef?.addEventListener('click', function () {
      c().imageRefInput?.click();
    });
    c().btnVideoRef?.addEventListener('click', function () {
      c().videoRefInput?.click();
    });

    async function handleRefInput(input) {
      const file = input.files?.[0];
      input.value = '';
      if (!file) return;
      if (mediaRefImages.length >= 3) {
        c().showToast('最多 3 张参考图');
        return;
      }
      mediaRefUploading = true;
      try {
        const url = await uploadMediaRef(file);
        mediaRefImages.push({ url: url, name: file.name });
        renderMediaRefStrip();
      } catch (e) {
        c().showToast(e.message || '上传失败');
      } finally {
        mediaRefUploading = false;
      }
    }

    c().imageRefInput?.addEventListener('change', function () {
      handleRefInput(c().imageRefInput);
    });
    c().videoRefInput?.addEventListener('change', function () {
      handleRefInput(c().videoRefInput);
    });
  }

  function initComposerMediaTriggers() {
    c().btnEnterImageMode?.addEventListener('click', function () {
      enterMediaMode('image');
    });
    c().btnEnterVideoMode?.addEventListener('click', function () {
      enterMediaMode('video');
    });
    c().btnImageModeClose?.addEventListener('click', exitMediaMode);
    c().btnVideoModeClose?.addEventListener('click', exitMediaMode);
  }

  function buildMediaGenProgress(label, status) {
    const wrap = document.createElement('div');
    wrap.className = 'media-gen-progress is-indeterminate';
    const icon = window.iconSvg
      ? window.iconSvg('sparkles', 14, 'inline-icon media-gen-progress__icon')
      : '';
    wrap.innerHTML =
      '<div class="media-gen-progress__label">' +
      icon +
      escapeAttr(label) +
      '</div>' +
      '<div class="media-gen-progress__status">' +
      escapeAttr(status || '请稍候…') +
      '</div>' +
      '<div class="media-gen-progress__track"><div class="media-gen-progress__bar"></div></div>';
    return wrap;
  }

  function setProgressStatus(el, text, pct) {
    if (!el) return;
    const status = el.querySelector('.media-gen-progress__status');
    const bar = el.querySelector('.media-gen-progress__bar');
    if (status) status.textContent = text;
    if (bar && typeof pct === 'number') {
      el.classList.remove('is-indeterminate');
      bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
    }
  }

  function mountProgressInBubble(bubble, progressEl) {
    const content = bubble.querySelector('.msg__content, .msg-content');
    if (content) {
      content.innerHTML = '';
      content.appendChild(progressEl);
    }
  }

  function formatMediaError(res, data, fallback) {
    const err = (data && data.error) || fallback || '请求失败';
    if (res && (res.status === 524 || res.status === 504 || res.status === 502)) {
      if (res.status === 524 || res.status === 504) {
        return '生成耗时较长，任务仍在后台处理，请稍候…';
      }
    }
    if (res && res.status === 429) {
      if (data && data.quota) renderQuotaBadge(data.quota);
      return err.indexOf('429') >= 0 || err.indexOf('限流') >= 0
        ? err
        : err || '今日次数已用完，请明天再试';
    }
    if (err.indexOf('媒体 API 错误 (429)') >= 0 || err.indexOf('429') >= 0) {
      return '视频服务请求过于频繁，请稍后再试（上游 API 限流）';
    }
    return err;
  }

  function quotaRemaining(type) {
    const q = c().cfg.quota;
    if (!q || !q[type]) return null;
    const item = q[type];
    if (!item.enabled) return 0;
    if (item.limit <= 0) return Infinity;
    if (item.remaining != null) return item.remaining;
    return Math.max(0, (item.limit || 0) - (item.used || 0));
  }

  function hasQuotaFor(type) {
    const left = quotaRemaining(type);
    if (left === null) return true;
    if (left === Infinity) return true;
    return left > 0;
  }

  function quotaToastMessage(type) {
    return type === 'video' ? '今日生视频次数已用完，请明天再试' : '今日生图次数已用完，请明天再试';
  }

  function renderQuotaBadge(quota) {
    if (!quota) return;
    c().cfg.quota = quota;
    if (window.CAMPUS_PROFILE) window.CAMPUS_PROFILE.quota = quota;

    if (window.renderQuotaBars) {
      window.renderQuotaBars(c().sidebarQuota, quota);
    }

    if (window.updateProfileQuotaDisplay) window.updateProfileQuotaDisplay(quota);
  }

  async function pollMediaQueue(queueId, progressEl) {
    const url = c().cfg.mediaQueueUrl;
    if (!url) throw new Error('排队服务未配置');
    const maxAttempts = 600;
    for (let i = 0; i < maxAttempts; i++) {
      await new Promise(function (r) {
        setTimeout(r, 1500);
      });
      const res = await fetch(url + '?id=' + encodeURIComponent(queueId), {
        credentials: 'same-origin',
      });
      const data = await res.json().catch(function () {
        return {};
      });
      if (res.status === 524 || res.status === 504) {
        setProgressStatus(progressEl, '生成耗时较长，仍在后台处理…', null);
        continue;
      }
      if (data.status === 'queued') {
        const pos = data.position != null ? data.position : 0;
        setProgressStatus(
          progressEl,
          pos > 0 ? '排队中，前面还有 ' + pos + ' 个任务…' : '排队中…',
          null
        );
        continue;
      }
      if (data.status === 'processing') {
        setProgressStatus(progressEl, '正在提交生成…', null);
        continue;
      }
      if (data.status === 'completed') {
        if (data.job_type === 'video') {
          setProgressStatus(progressEl, '任务已提交，开始生成…', null);
        }
        return data;
      }
      if (data.status === 'failed') {
        throw new Error(data.error || '排队任务失败');
      }
      if (!res.ok) {
        throw new Error(data.error || '排队查询失败');
      }
    }
    throw new Error('排队超时，请稍后在对话中查看或重试');
  }

  async function runImageGeneration(prompt, displayText, refUrls, displayPrompt) {
    const assistantWrap = c().appendMessage('assistant', '', true);
    const assistantBubble = assistantWrap.querySelector('.msg-body');
    const progress = buildMediaGenProgress('正在生成图片', '提交请求…');
    mountProgressInBubble(assistantBubble, progress);

    try {
      const body = {
        prompt: prompt,
        size: imageSize,
        style_key: imageStyleKey,
        model: imageModelKey,
        images: refUrls || [],
        conversation_id: c().getConversationId(),
      };
      if (displayPrompt && displayPrompt !== prompt) {
        body.display_prompt = displayPrompt;
      }
      const res = await fetch(c().cfg.imageUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body),
      });
      const data = await res.json().catch(function () {
        return {};
      });
      if (!res.ok) throw new Error(formatMediaError(res, data, '生图失败'));

      if (data.conversation_id) c().setConversationId(parseInt(data.conversation_id, 10));
      if (data.pending_content && data.status !== 'completed') {
        c().pushHistory({ role: 'assistant', content: data.pending_content });
      }

      let result = data;
      if (data.queue_id && data.status !== 'completed') {
        setProgressStatus(progress, '已加入队列…', null);
        result = await pollMediaQueue(data.queue_id, progress);
      }

      if (result.conversation_id) c().setConversationId(parseInt(result.conversation_id, 10));
      if (typeof c().replaceLastAssistantContent === 'function') {
        c().replaceLastAssistantContent(result.content || '');
      } else {
        c().pushHistory({ role: 'assistant', content: result.content || '' });
      }
      c().setBubbleText(assistantBubble, result.content || '');
      if (result.quota) renderQuotaBadge(result.quota);
      await c().loadConversations();
    } catch (e) {
      const msg = '生成失败：' + (e.message || String(e));
      c().setBubbleText(assistantBubble, msg);
      if (typeof c().replaceLastAssistantContent === 'function') {
        c().replaceLastAssistantContent(msg);
      } else {
        c().pushHistory({ role: 'assistant', content: msg });
      }
      throw e;
    }
  }

  function isPendingAssistantContent(content) {
    const t = String(content || '').trim();
    return (
      t === '<!--text-pending-->' ||
      t.indexOf('<!--queue-pending:') === 0 ||
      t.indexOf('<!--video-pending:') === 0
    );
  }

  function parseQueuePendingContent(content) {
    const m = String(content || '')
      .trim()
      .match(/^<!--queue-pending:([A-Za-z0-9+\/=]+)-->$/);
    if (!m) return null;
    try {
      return JSON.parse(atob(m[1]));
    } catch (_) {
      return null;
    }
  }

  function parseVideoPendingContent(content) {
    const m = String(content || '')
      .trim()
      .match(/^<!--video-pending:([A-Za-z0-9+\/=]+)-->$/);
    if (!m) return null;
    try {
      return JSON.parse(atob(m[1]));
    } catch (_) {
      return null;
    }
  }

  function isVideoPollDone(data) {
    if (data.content) return true;
    if (!data.video_url) return false;
    const status = String(data.status || '').toLowerCase();
    if (status === 'failed' || status === 'error' || status === 'cancelled' || status === 'canceled') {
      return false;
    }
    return true;
  }

  function videoPollStatusText(data) {
    const status = String(data.status || '').toLowerCase();
    if (status === 'queued') return '排队中…';
    if (status === 'in_progress' || status === 'processing') {
      return data.progress != null ? '生成中 ' + data.progress + '%' : '生成中…';
    }
    if (data.progress != null) return '生成中 ' + data.progress + '%';
    return '生成中…';
  }

  async function pollVideoUntilDone(taskId, videoId, providerId, prompt, assistantBubble, progressEl) {
    const maxAttempts = 200;
    for (let i = 0; i < maxAttempts; i++) {
      const qs = new URLSearchParams({
        task_id: taskId || '',
        video_id: videoId || '',
        provider_id: String(providerId || 0),
        conversation_id: String(c().getConversationId()),
        prompt: prompt,
      });
      const res = await fetch(c().cfg.videoUrl + '?' + qs.toString(), { credentials: 'same-origin' });
      const data = await res.json().catch(function () {
        return {};
      });

      if (isVideoPollDone(data)) {
        const content = data.content || '[生成视频](' + data.video_url + ')\n\n> ' + prompt;
        if (typeof c().replaceLastAssistantContent === 'function') {
          c().replaceLastAssistantContent(content);
        } else {
          c().pushHistory({ role: 'assistant', content: content });
        }
        c().setBubbleText(assistantBubble, content);
        if (data.quota) renderQuotaBadge(data.quota);
        return;
      }

      setProgressStatus(progressEl, videoPollStatusText(data), data.progress != null ? data.progress : null);

      const status = String(data.status || '').toLowerCase();
      if (status === 'failed' || status === 'error' || status === 'cancelled' || status === 'canceled') {
        throw new Error(data.error || '视频生成失败');
      }
      if (!res.ok && data.error) {
        throw new Error(formatMediaError(res, data, data.error));
      }

      await new Promise(function (r) {
        setTimeout(r, 5000);
      });
    }
    throw new Error('视频生成超时，请刷新页面或稍后重试');
  }

  function resumePendingTextBubble(assistantBubble, rawContent) {
    if (!assistantBubble || assistantBubble.dataset.textPollActive === '1') return;
    if (String(rawContent || '').trim() !== '<!--text-pending-->') return;

    assistantBubble.dataset.textPollActive = '1';
    const progress = buildMediaGenProgress('正在生成回复', '恢复连接…');
    mountProgressInBubble(assistantBubble, progress);
    c().setIsStreaming(true);
    c().setLoading(true);

    const convId = c().getConversationId();
    let attempts = 0;

    const tick = function () {
      fetch(c().cfg.convUrl + '?id=' + encodeURIComponent(convId), { credentials: 'same-origin' })
        .then(function (res) {
          if (res.status === 404) {
            throw new Error('对话不存在或已过期，请刷新页面');
          }
          return res.json().catch(function () {
            return {};
          }).then(function (data) {
            return { res: res, data: data };
          });
        })
        .then(async function (payload) {
          const data = payload.data || {};
          const msgs = data.messages || [];
          for (let i = msgs.length - 1; i >= 0; i--) {
            if (msgs[i].role !== 'assistant') continue;
            const content = String(msgs[i].content || '');
            if (content === '<!--text-pending-->') {
              setProgressStatus(progress, '正在等待模型回复…', null);
              break;
            }
            if (content) {
              if (typeof c().replaceLastAssistantContent === 'function') {
                c().replaceLastAssistantContent(content);
              }
              c().setBubbleText(assistantBubble, content);
              delete assistantBubble.dataset.textPollActive;
              c().setIsStreaming(false);
              c().setLoading(false);
              await c().loadConversations();
              return;
            }
          }

          attempts++;
          if (attempts > 200) {
            const msg = '生成超时，请刷新页面或重试';
            if (typeof c().replaceLastAssistantContent === 'function') {
              c().replaceLastAssistantContent(msg);
            }
            c().setBubbleText(assistantBubble, msg);
            delete assistantBubble.dataset.textPollActive;
            c().setIsStreaming(false);
            c().setLoading(false);
            return;
          }
          setTimeout(tick, 3000);
        })
        .catch(function (err) {
          const msg = err && err.message ? err.message : '恢复生成状态失败，请刷新页面';
          if (typeof c().replaceLastAssistantContent === 'function') {
            c().replaceLastAssistantContent(msg);
          }
          c().setBubbleText(assistantBubble, msg);
          delete assistantBubble.dataset.textPollActive;
          c().setIsStreaming(false);
          c().setLoading(false);
        });
    };

    tick();
  }

  async function resumePendingQueueBubble(assistantBubble, rawContent) {
    if (!assistantBubble || assistantBubble.dataset.queuePollActive === '1') return;
    const pending = parseQueuePendingContent(rawContent);
    if (!pending) return;

    const queueId = parseInt(pending.queue_id, 10);
    if (!queueId) return;

    assistantBubble.dataset.queuePollActive = '1';
    const label = pending.job_type === 'video' ? '正在生成视频' : '正在生成图片';
    const progress = buildMediaGenProgress(label, '恢复查询…');
    mountProgressInBubble(assistantBubble, progress);
    c().setIsStreaming(true);
    c().setLoading(true);

    try {
      const result = await pollMediaQueue(queueId, progress);
      if (result.conversation_id) {
        c().setConversationId(parseInt(result.conversation_id, 10));
      }

      if (pending.job_type === 'image') {
        const content = result.content || '';
        if (typeof c().replaceLastAssistantContent === 'function') {
          c().replaceLastAssistantContent(content);
        }
        c().setBubbleText(assistantBubble, content);
        if (result.quota) renderQuotaBadge(result.quota);
        await c().loadConversations();
        return;
      }

      if (pending.job_type === 'video') {
        const taskId = String(result.task_id || result.id || '');
        const videoId = String(result.video_id || '');
        const providerId = parseInt(result.provider_id, 10) || parseInt(pending.provider_id, 10) || 0;
        const prompt = String(pending.prompt || result.prompt || '');

        if (result.pending_content && typeof c().replaceLastAssistantContent === 'function') {
          c().replaceLastAssistantContent(result.pending_content);
        }

        if (taskId || videoId) {
          setProgressStatus(progress, '生成中…', null);
          await pollVideoUntilDone(taskId, videoId, providerId, prompt, assistantBubble, progress);
          await c().loadConversations();
        } else if (result.content) {
          if (typeof c().replaceLastAssistantContent === 'function') {
            c().replaceLastAssistantContent(result.content);
          }
          c().setBubbleText(assistantBubble, result.content);
        }
      }
    } catch (e) {
      const msg = '生成失败：' + (e.message || String(e));
      if (typeof c().replaceLastAssistantContent === 'function') {
        c().replaceLastAssistantContent(msg);
      }
      c().setBubbleText(assistantBubble, msg);
    } finally {
      delete assistantBubble.dataset.queuePollActive;
      c().setIsStreaming(false);
      c().setLoading(false);
    }
  }

  function resumeAssistantBubble(bubble, rawContent, role) {
    if (role !== 'assistant' || !bubble) return;
    const t = String(rawContent || '').trim();
    if (t.indexOf('<!--queue-pending:') === 0) {
      void resumePendingQueueBubble(bubble, rawContent);
      return;
    }
    if (t.indexOf('<!--video-pending:') === 0) {
      resumePendingVideoBubble(bubble, rawContent);
      return;
    }
    if (t === '<!--text-pending-->') {
      resumePendingTextBubble(bubble, rawContent);
    }
  }

  function resumePendingVideoBubble(assistantBubble, rawContent) {
    if (!assistantBubble || assistantBubble.dataset.videoPollActive === '1') return;
    const pending = parseVideoPendingContent(rawContent);
    if (!pending) return;

    const taskId = String(pending.task_id || pending.id || '');
    const videoId = String(pending.video_id || '');
    const providerId = parseInt(pending.provider_id, 10) || 0;
    const prompt = String(pending.prompt || '');
    if (!taskId && !videoId) return;

    assistantBubble.dataset.videoPollActive = '1';
    const progress = buildMediaGenProgress('正在生成视频', '恢复查询…');
    mountProgressInBubble(assistantBubble, progress);

    c().setIsStreaming(true);
    c().setLoading(true);
    void pollVideoUntilDone(taskId, videoId, providerId, prompt, assistantBubble, progress)
      .then(function () {
        return c().loadConversations();
      })
      .catch(function (e) {
        const msg = '生成失败：' + (e.message || String(e));
        if (typeof c().replaceLastAssistantContent === 'function') {
          c().replaceLastAssistantContent(msg);
        } else {
          c().pushHistory({ role: 'assistant', content: msg });
        }
        c().setBubbleText(assistantBubble, msg);
      })
      .finally(function () {
        delete assistantBubble.dataset.videoPollActive;
        c().setIsStreaming(false);
        c().setLoading(false);
      });
  }

  async function runVideoGeneration(prompt, displayText, refUrls) {
    const assistantWrap = c().appendMessage('assistant', '', true);
    const assistantBubble = assistantWrap.querySelector('.msg-body');
    const progress = buildMediaGenProgress('正在生成视频', '提交任务…');
    mountProgressInBubble(assistantBubble, progress);

    try {
      const ratio = VIDEO_RATIO_OPTIONS[videoRatioIdx] || VIDEO_RATIO_OPTIONS[0];
      const res = await fetch(c().cfg.videoUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          prompt: prompt,
          width: ratio.width,
          height: ratio.height,
          images: refUrls || [],
          conversation_id: c().getConversationId(),
        }),
      });
      const data = await res.json().catch(function () {
        return {};
      });
      if (!res.ok) throw new Error(formatMediaError(res, data, '生视频失败'));

      let result = data;
      if (data.queue_id && data.status !== 'completed') {
        setProgressStatus(progress, '已加入队列…', null);
        result = await pollMediaQueue(data.queue_id, progress);
      }

      if (result.conversation_id) c().setConversationId(parseInt(result.conversation_id, 10));
      if (result.pending_content) {
        c().pushHistory({ role: 'assistant', content: result.pending_content });
      }

      const taskId = String(result.task_id || result.id || '');
      const videoId = String(result.video_id || '');
      const providerId = parseInt(result.provider_id, 10) || 0;
      if (!taskId && !videoId) {
        throw new Error('视频任务 ID 缺失，无法查询进度');
      }

      setProgressStatus(progress, '生成中…', null);
      await pollVideoUntilDone(taskId, videoId, providerId, prompt, assistantBubble, progress);
      await c().loadConversations();
    } catch (e) {
      const msg = '生成失败：' + (e.message || String(e));
      c().setBubbleText(assistantBubble, msg);
      if (typeof c().replaceLastAssistantContent === 'function') {
        c().replaceLastAssistantContent(msg);
      } else {
        c().pushHistory({ role: 'assistant', content: msg });
      }
      throw e;
    }
  }

  function syncImagePromptGenOnInput() {
    if (composerMediaMode !== 'image' || !imagePromptGen) return;
    const raw = getImagePromptRaw();
    if (raw !== imagePromptOptimizedFrom) {
      clearImagePromptGen();
    }
  }

  function install(context) {
    ctx = context;
    imageModelKey = normalizeImageModelKey(String(c().cfg.imageModelDefault || 'pony_v6'));

    document.getElementById('btn-image-prompt-optimize')?.addEventListener('click', function () {
      void optimizeImagePrompt();
    });

    initComposerMediaTriggers();
    initImageGenOptions();
    initVideoGenOptions();
    initMediaRefUpload();
    renderQuotaBadge(c().cfg.quota);

    c().promptEl?.addEventListener('input', function () {
      syncImagePromptGenOnInput();
      syncComposerMediaUi();
    });

    c().form?.addEventListener(
      'submit',
      function (e) {
        const text = c().promptEl.value.trim();
        const intent = getMediaSubmitIntent(text);
        if (!intent) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        void handleMediaSubmit(intent);
      },
      true
    );
  }

  function hasPendingInHistory(messages) {
    const list = messages || (typeof c().getHistory === 'function' ? c().getHistory() : []);
    for (let i = list.length - 1; i >= 0; i--) {
      if (list[i].role === 'assistant' && isPendingAssistantContent(list[i].content)) {
        return true;
      }
    }
    return false;
  }

  async function handleMediaSubmit(intent) {
    if (c().btnSend.disabled || c().getIsStreaming()) return;
    if (hasPendingInHistory()) {
      c().showToast('有任务正在生成中，请等待完成');
      return;
    }
    if (intent.empty) {
      c().showToast(intent.type === 'image' ? '请在 @图片 后输入描述' : '请在 @视频 后输入描述');
      return;
    }
    if (mediaRefUploading) {
      c().showToast('参考图上传中，请稍候');
      return;
    }
    if (!hasQuotaFor(intent.type === 'image' ? 'image' : 'video')) {
      c().showToast(quotaToastMessage(intent.type));
      return;
    }

    const refUrls = getMediaRefUrls();
    await c().ensureConversation();
    c().removeWelcome();

    let genPrompt = intent.prompt;
    let chatPrompt = intent.prompt;
    if (intent.type === 'image') {
      chatPrompt = getImagePromptRaw() || intent.prompt;
      genPrompt = imagePromptGen || chatPrompt;
    }

    const displayText =
      intent.type === 'image'
        ? defaultAlias('image') + ' ' + chatPrompt
        : defaultAlias('video') + ' ' + intent.prompt;

    c().appendMessage('user', displayText);
    c().pushHistory({ role: 'user', content: displayText });
    c().promptEl.value = '';
    c().promptEl.style.height = 'auto';
    clearImagePromptGen();
    clearMediaRefImages();
    if (typeof c().clearComposerQuote === 'function') c().clearComposerQuote();
    c().clearAttachments();

    c().setLoading(true);
    c().setIsStreaming(true);
    try {
      if (intent.type === 'image') {
        await runImageGeneration(genPrompt, displayText, refUrls, chatPrompt);
      } else {
        await runVideoGeneration(intent.prompt, displayText, refUrls);
      }
    } catch (err) {
      var msg = err.message || '生成失败';
      c().showToast(msg);
      if (msg.indexOf('次数已用完') >= 0 && window.openProfileModal) {
        setTimeout(function () { window.openProfileModal('quota'); }, 500);
      }
    } finally {
      c().setIsStreaming(false);
      c().setLoading(false);
      exitMediaMode();
    }
  }

  window.CampusChatMedia = {
    install: install,
    exitMediaMode: function () {
      if (ctx) exitMediaMode();
    },
    enterMediaMode: function (mode) {
      if (ctx) enterMediaModePublic(mode);
    },
    addMediaRefFromUrl: function (url, name) {
      if (ctx) addMediaRefFromUrl(url, name);
    },
    resumeAssistantBubble: function (bubble, rawContent, role) {
      if (ctx) resumeAssistantBubble(bubble, rawContent, role);
    },
    resumePendingVideoBubble: function (bubble, rawContent) {
      if (ctx) resumePendingVideoBubble(bubble, rawContent);
    },
    hasPendingInHistory: function (messages) {
      return ctx ? hasPendingInHistory(messages) : false;
    },
    isPendingAssistantContent: isPendingAssistantContent,
    renderQuotaBadge: function (quota) {
      if (ctx) renderQuotaBadge(quota);
    },
  };
})();
