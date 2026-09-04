(function () {
  'use strict';

  var STORAGE_KEY = 'app-theme';
  var META_COLORS = { dark: '#212121', light: '#f7f7f8' };

  function readStoredTheme() {
    try {
      var stored = localStorage.getItem(STORAGE_KEY);
      if (stored === 'light' || stored === 'dark') return stored;
    } catch (_) {}
    return 'dark';
  }

  function applyTheme(theme, persist) {
    var next = theme === 'light' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    if (persist !== false) {
      try {
        localStorage.setItem(STORAGE_KEY, next);
      } catch (_) {}
    }
    var meta = document.getElementById('meta-theme-color');
    if (meta) meta.setAttribute('content', META_COLORS[next]);
    var scheme = document.querySelector('meta[name="color-scheme"]');
    if (scheme) scheme.setAttribute('content', next === 'light' ? 'light dark' : 'dark light');
    updateToggleUi(next);
    document.dispatchEvent(
      new CustomEvent('wanai:themechange', { detail: { theme: next } })
    );
    return next;
  }

  function updateToggleUi(theme) {
    var isLight = theme === 'light';
    var label = isLight ? '切换到夜间模式' : '切换到白天模式';
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
      var icon = btn.querySelector('[data-icon]');
      if (icon) icon.setAttribute('data-icon', isLight ? 'moon' : 'sun');
      var text = btn.querySelector('.theme-toggle__label');
      if (text) text.textContent = isLight ? '夜间模式' : '白天模式';
    });
    if (window.renderIcons) window.renderIcons(document);
  }

  function toggleTheme() {
    return applyTheme(readStoredTheme() === 'dark' ? 'light' : 'dark');
  }

  window.WanaiTheme = {
    get: readStoredTheme,
    set: applyTheme,
    toggle: toggleTheme,
  };

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-theme-toggle]')) {
      e.preventDefault();
      toggleTheme();
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      updateToggleUi(readStoredTheme());
    });
  } else {
    updateToggleUi(readStoredTheme());
  }
})();
