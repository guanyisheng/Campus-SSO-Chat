/**
 * Chat media — thumbnails, lightbox, custom video player
 */
(function () {
  'use strict';

  const ICON_PLAY =
    '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>';
  const ICON_PAUSE =
    '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg>';
  const ICON_VOL =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>';
  const ICON_MUTE =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>';
  const ICON_FS =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M8 3H5a2 2 0 00-2 2v3m18 0V5a2 2 0 00-2-2h-3m0 18h3a2 2 0 002-2v-3M3 16v3a2 2 0 002 2h3"/></svg>';

  let lightboxEl = null;

  /** 修复历史消息中错误的 /api/api/user_media.php 路径 */
  function normalizeMediaSrc(src) {
    if (!src || typeof src !== 'string') return src || '';
    var s = src.trim().replace(/\/api\/api\//g, '/api/');
    try {
      var u = new URL(s, window.location.href);
      u.pathname = u.pathname.replace(/\/api\/api\//g, '/api/');
      if (u.origin === window.location.origin) {
        return u.pathname + u.search + (u.hash || '');
      }
      return u.href;
    } catch (_) {
      return s;
    }
  }

  function formatTime(sec) {
    if (!isFinite(sec) || sec < 0) sec = 0;
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return m + ':' + String(s).padStart(2, '0');
  }

  function fileNameFromUrl(url, fallback) {
    try {
      const u = new URL(normalizeMediaSrc(url), window.location.href);
      const mediaFile = u.searchParams.get('f');
      if (mediaFile) {
        return decodeURIComponent(mediaFile);
      }
      const path = u.pathname;
      const base = path.split('/').pop() || '';
      if (base && base !== 'user_media.php') {
        return decodeURIComponent(base);
      }
    } catch (_) {}
    return fallback || 'download';
  }

  /** Cross-origin download often navigates same tab — always open media in a new tab. */
  function configureMediaLink(link, src, fileName) {
    if (!link || !src) return;
    link.href = src;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    if (fileName) link.download = fileName;
  }

  function ensureLightbox() {
    if (lightboxEl) return lightboxEl;
    lightboxEl = document.createElement('div');
    lightboxEl.id = 'media-lightbox';
    lightboxEl.className = 'media-lightbox';
    lightboxEl.hidden = true;
    lightboxEl.setAttribute('role', 'dialog');
    lightboxEl.setAttribute('aria-modal', 'true');
    lightboxEl.setAttribute('aria-label', '查看原图');
    lightboxEl.innerHTML =
      '<div class="media-lightbox__panel">' +
      '<button type="button" class="media-lightbox__close" aria-label="关闭">×</button>' +
      '<img class="media-lightbox__img" alt="">' +
      '<div class="media-lightbox__toolbar">' +
      '<a class="msg-media-download media-lightbox__open" href="#" target="_blank" rel="noopener noreferrer">新标签页打开</a>' +
      '<a class="msg-media-download media-lightbox__download" href="#" target="_blank" rel="noopener noreferrer" download>下载原图</a>' +
      '</div></div>';
    document.body.appendChild(lightboxEl);

    lightboxEl.querySelector('.media-lightbox__close')?.addEventListener('click', closeLightbox);
    lightboxEl.addEventListener('click', function (e) {
      if (e.target === lightboxEl) closeLightbox();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && lightboxEl && !lightboxEl.hidden) closeLightbox();
    });
    return lightboxEl;
  }

  function openLightbox(src, alt) {
    src = normalizeMediaSrc(src);
    const lb = ensureLightbox();
    const img = lb.querySelector('.media-lightbox__img');
    const openLink = lb.querySelector('.media-lightbox__open');
    const downloadLink = lb.querySelector('.media-lightbox__download');
    if (img) {
      img.src = src;
      img.alt = alt || '原图';
    }
    configureMediaLink(openLink, src);
    configureMediaLink(downloadLink, src, fileNameFromUrl(src, 'image'));
    lb.hidden = false;
    document.body.classList.add('media-lightbox-open');
  }

  function closeLightbox() {
    if (!lightboxEl) return;
    lightboxEl.hidden = true;
    document.body.classList.remove('media-lightbox-open');
    const img = lightboxEl.querySelector('.media-lightbox__img');
    if (img) img.removeAttribute('src');
  }

  function buildImageCard(src, alt) {
    src = normalizeMediaSrc(src);
    const card = document.createElement('div');
    card.className = 'msg-media-card msg-media-card--image card-spotlight card-spotlight--media';
    card.setAttribute('data-spotlight-card', '');
    card.setAttribute('data-spotlight-color', 'rgba(0, 229, 255, 0.18)');

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'msg-media-thumb';
    btn.setAttribute('aria-label', '查看原图');

    const loader = document.createElement('span');
    loader.className = 'msg-media-thumb__loader';
    loader.setAttribute('aria-hidden', 'true');

    const img = document.createElement('img');
    img.loading = 'lazy';
    img.decoding = 'async';
    img.alt = alt || '生成图片';
    img.addEventListener('load', function () {
      btn.classList.add('is-loaded');
    });
    img.addEventListener('error', function () {
      btn.classList.add('is-loaded');
      loader.textContent = '加载失败';
    });
    img.src = src;

    const hint = document.createElement('span');
    hint.className = 'msg-media-thumb__hint';
    hint.textContent = '点击查看原图';

    btn.appendChild(loader);
    btn.appendChild(img);
    btn.appendChild(hint);
    btn.addEventListener('click', function () {
      openLightbox(src, alt);
    });

    const bar = document.createElement('div');
    bar.className = 'msg-media-card__bar';
    bar.innerHTML =
      '<span class="msg-media-card__label">' + (alt || '生成图片') + '</span>' +
      '<button type="button" class="msg-media-quote">引用</button>' +
      '<a class="msg-media-download msg-media-original" href="' + escapeAttr(src) +
      '" target="_blank" rel="noopener noreferrer">原图</a>' +
      '<a class="msg-media-download" href="' + escapeAttr(src) + '" target="_blank" rel="noopener noreferrer" download="' +
      escapeAttr(fileNameFromUrl(src, 'image')) +
      '">下载</a>';

    card.appendChild(btn);
    card.appendChild(bar);

    const quoteBtn = bar.querySelector('.msg-media-quote');
    quoteBtn?.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (window.CampusChatQuote) {
        window.CampusChatQuote.fromImage(src, alt);
      }
    });

    return card;
  }

  function escapeAttr(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function initCustomVideo(wrap, src) {
    if (!wrap || wrap.dataset.videoReady === '1') return;
    wrap.dataset.videoReady = '1';

    const video = wrap.querySelector('video');
    if (!video) return;
    video.removeAttribute('controls');
    video.src = src;
    video.preload = 'metadata';
    video.playsInline = true;

    const screen = wrap.querySelector('.custom-video__screen');
    const bigPlay = wrap.querySelector('.custom-video__big-play');
    const btnPlay = wrap.querySelector('[data-action="play"]');
    const btnMute = wrap.querySelector('[data-action="mute"]');
    const btnFs = wrap.querySelector('[data-action="fullscreen"]');
    const seek = wrap.querySelector('.custom-video__seek');
    const bar = wrap.querySelector('.custom-video__bar');
    const timeEl = wrap.querySelector('.custom-video__time');

    function syncPlayState() {
      const playing = !video.paused && !video.ended;
      wrap.classList.toggle('is-playing', playing);
      if (btnPlay) btnPlay.innerHTML = playing ? ICON_PAUSE : ICON_PLAY;
    }

    function syncProgress() {
      const dur = video.duration || 0;
      const cur = video.currentTime || 0;
      const pct = dur > 0 ? (cur / dur) * 100 : 0;
      if (bar) bar.style.width = pct + '%';
      if (seek) {
        seek.value = String(pct);
        seek.max = '100';
      }
      if (timeEl) timeEl.textContent = formatTime(cur) + ' / ' + formatTime(dur);
    }

    function togglePlay() {
      if (video.paused) video.play().catch(function () {});
      else video.pause();
    }

    bigPlay?.addEventListener('click', togglePlay);
    screen?.addEventListener('click', function (e) {
      if (e.target === bigPlay || e.target.closest('.custom-video__big-play')) return;
      togglePlay();
    });
    btnPlay?.addEventListener('click', togglePlay);

    btnMute?.addEventListener('click', function () {
      video.muted = !video.muted;
      btnMute.innerHTML = video.muted ? ICON_MUTE : ICON_VOL;
    });

    seek?.addEventListener('input', function () {
      const dur = video.duration || 0;
      if (dur > 0) video.currentTime = (parseFloat(seek.value) / 100) * dur;
    });

    btnFs?.addEventListener('click', function () {
      const target = screen || wrap;
      if (document.fullscreenElement) document.exitFullscreen();
      else if (target.requestFullscreen) target.requestFullscreen();
    });

    video.addEventListener('play', syncPlayState);
    video.addEventListener('pause', syncPlayState);
    video.addEventListener('ended', syncPlayState);
    video.addEventListener('timeupdate', syncProgress);
    video.addEventListener('loadedmetadata', syncProgress);

    syncPlayState();
    syncProgress();
    if (btnMute) btnMute.innerHTML = video.muted ? ICON_MUTE : ICON_VOL;
  }

  function buildVideoCard(src, label) {
    src = normalizeMediaSrc(src);
    const card = document.createElement('div');
    card.className = 'msg-media-card msg-media-card--video card-spotlight card-spotlight--media';
    card.setAttribute('data-spotlight-card', '');
    card.setAttribute('data-spotlight-color', 'rgba(180, 151, 207, 0.2)');

    const player = document.createElement('div');
    player.className = 'custom-video';
    player.innerHTML =
      '<div class="custom-video__screen">' +
      '<video playsinline preload="metadata"></video>' +
      '<button type="button" class="custom-video__big-play" aria-label="播放">' +
      ICON_PLAY +
      '</button></div>' +
      '<div class="custom-video__controls">' +
      '<button type="button" class="custom-video__btn" data-action="play" aria-label="播放/暂停">' +
      ICON_PLAY +
      '</button>' +
      '<div class="custom-video__progress">' +
      '<div class="custom-video__track"><div class="custom-video__bar"></div>' +
      '<input type="range" class="custom-video__seek" min="0" max="100" value="0" aria-label="进度"></div>' +
      '<span class="custom-video__time">0:00 / 0:00</span></div>' +
      '<button type="button" class="custom-video__btn" data-action="mute" aria-label="静音">' +
      ICON_VOL +
      '</button>' +
      '<button type="button" class="custom-video__btn" data-action="fullscreen" aria-label="全屏">' +
      ICON_FS +
      '</button>' +
      '<a class="msg-media-download custom-video__download" href="' +
      escapeAttr(src) +
      '" target="_blank" rel="noopener noreferrer" download="' +
      escapeAttr(fileNameFromUrl(src, 'video.mp4')) +
      '">下载</a></div>';

    const bar = document.createElement('div');
    bar.className = 'msg-media-card__bar';
    bar.innerHTML =
      '<span class="msg-media-card__label">' + (label || '生成视频') + '</span>';

    card.appendChild(player);
    card.appendChild(bar);
    initCustomVideo(player, src);
    return card;
  }

  function isVideoUrl(href) {
    return /\.(mp4|webm|mov|m4v)(\?|$)/i.test(href);
  }

  function enhanceImages(root) {
    root.querySelectorAll('.msg__content img, .msg-content img, .md-body img').forEach(function (img) {
      if (img.closest('.msg-media-card')) return;
      const src = normalizeMediaSrc(img.getAttribute('src') || '');
      if (!src) return;
      const alt = img.getAttribute('alt') || '生成图片';
      const card = buildImageCard(src, alt);
      img.replaceWith(card);
    });
  }

  function enhanceVideos(root) {
    root.querySelectorAll('.msg__content a, .msg-content a, .md-body a').forEach(function (a) {
      if (a.closest('.msg-media-card')) return;
      const href = a.getAttribute('href') || '';
      if (!isVideoUrl(href) && a.textContent.indexOf('生成视频') === -1) return;
      if (!href) return;
      const card = buildVideoCard(href, a.textContent.trim() || '生成视频');
      a.replaceWith(card);
    });

    root.querySelectorAll('.msg__video video, video').forEach(function (video) {
      if (video.closest('.custom-video')) return;
      const src = video.getAttribute('src') || '';
      if (!src) return;
      const card = buildVideoCard(src, '生成视频');
      const host = video.closest('.msg__video') || video.parentElement;
      if (host) host.replaceWith(card);
      else video.replaceWith(card);
    });
  }

  function mountChatMedia(root) {
    if (!root) return;
    enhanceImages(root);
    enhanceVideos(root);
    if (window.mountSpotlightCards) window.mountSpotlightCards(root);
  }

  window.mountChatMedia = mountChatMedia;
  window.openMediaLightbox = openLightbox;
})();
