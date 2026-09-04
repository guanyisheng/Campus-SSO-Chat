/**
 * Composer programming mode
 */
(function () {
  'use strict';

  let ctx = null;
  let codeModeActive = false;
  const DEFAULT_PLACEHOLDER = '发消息或描述你想写的代码…';
  let savedPlaceholder = '';

  function c() {
    return ctx;
  }

  function enterCodeMode() {
    if (codeModeActive) return;
    if (window.CampusChatMedia && window.CampusChatMedia.exitMediaMode) {
      window.CampusChatMedia.exitMediaMode();
    }
    codeModeActive = true;
    const wrap = c().composerWrap;
    wrap?.classList.add('is-code-mode');
    if (c().composerLeftDefault) c().composerLeftDefault.hidden = true;
    if (c().composerCodeTools) c().composerCodeTools.hidden = false;
    savedPlaceholder = c().promptEl.getAttribute('placeholder') || '';
    c().promptEl.setAttribute(
      'placeholder',
      '描述你想写的代码，例如：用 Python 写猜数字游戏、生成 HTML 邮件模板…'
    );
    c().promptEl.focus();
    if (window.renderIcons) window.renderIcons(wrap || document);
  }

  function exitCodeMode() {
    if (!codeModeActive) return;
    codeModeActive = false;
    c().composerWrap?.classList.remove('is-code-mode');
    if (c().composerLeftDefault) c().composerLeftDefault.hidden = false;
    if (c().composerCodeTools) c().composerCodeTools.hidden = true;
    if (savedPlaceholder) {
      c().promptEl.setAttribute('placeholder', savedPlaceholder);
    } else {
      c().promptEl.setAttribute('placeholder', DEFAULT_PLACEHOLDER);
    }
  }

  function install(context) {
    ctx = context;
    c().btnEnterCodeMode?.addEventListener('click', enterCodeMode);
    c().btnCodeModeClose?.addEventListener('click', exitCodeMode);
  }

  window.CampusComposerCode = {
    install: install,
    exitCodeMode: exitCodeMode,
    isActive: function () {
      return codeModeActive;
    },
  };
})();
