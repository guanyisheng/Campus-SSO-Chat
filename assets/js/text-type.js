/**
 * TextType — vanilla port (React Bits), type-once only (no delete / loop)
 */
(function (global) {
  'use strict';

  function prefersReducedMotion() {
    return global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function mountTextType(el, opts) {
    if (!el) return function () {};

    opts = opts || {};
    var fullText = opts.text || el.getAttribute('data-text') || el.textContent || '';
    if (!fullText) return function () {};

    if (prefersReducedMotion()) {
      el.textContent = fullText;
      el.classList.add('text-type', 'is-done');
      return function () {};
    }

    var typingSpeed = opts.typingSpeed != null ? opts.typingSpeed : 50;
    var initialDelay = opts.initialDelay != null ? opts.initialDelay : 0;
    var showCursor = opts.showCursor !== false;
    var cursorCharacter = opts.cursorCharacter != null ? opts.cursorCharacter : '|';

    el.textContent = '';
    el.classList.add('text-type');

    var content = document.createElement('span');
    content.className = 'text-type__content';
    el.appendChild(content);

    var cursor = null;
    if (showCursor) {
      cursor = document.createElement('span');
      cursor.className = 'text-type__cursor';
      cursor.textContent = cursorCharacter;
      el.appendChild(cursor);
    }

    var charIndex = 0;
    var timer = null;
    var cancelled = false;

    function tick() {
      if (cancelled) return;
      if (charIndex < fullText.length) {
        content.textContent = fullText.slice(0, charIndex + 1);
        charIndex += 1;
        timer = setTimeout(tick, typingSpeed);
      } else {
        el.classList.add('is-done');
      }
    }

    timer = setTimeout(tick, initialDelay);

    return function destroy() {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }

  /** Incremental typewriter for SSE / streaming text */
  function createStreamTypewriter(el, opts) {
    if (!el) {
      return { setTarget: function () {}, flush: function () { return Promise.resolve(); }, destroy: function () {} };
    }

    opts = opts || {};
    var typingSpeed = opts.typingSpeed != null ? opts.typingSpeed : 28;
    var showCursor = opts.showCursor !== false;
    var reduced = prefersReducedMotion();

    el.textContent = '';
    el.classList.add('text-type', 'text-type--stream');

    var content = document.createElement('span');
    content.className = 'text-type__content';
    el.appendChild(content);

    var cursor = null;
    if (showCursor) {
      cursor = document.createElement('span');
      cursor.className = 'text-type__cursor';
      cursor.textContent = opts.cursorCharacter != null ? opts.cursorCharacter : '|';
      el.appendChild(cursor);
    }

    var target = '';
    var displayed = 0;
    var timer = null;
    var flushResolve = null;
    var cancelled = false;

    function finishFlush() {
      el.classList.add('is-done');
      if (flushResolve) {
        var r = flushResolve;
        flushResolve = null;
        r();
      }
    }

    function tick() {
      if (cancelled) return;
      if (displayed < target.length) {
        displayed += 1;
        content.textContent = target.slice(0, displayed);
        if (opts.onTick) opts.onTick();
        timer = setTimeout(tick, typingSpeed);
      } else {
        timer = null;
        finishFlush();
      }
    }

    function ensureTicking() {
      if (reduced) {
        displayed = target.length;
        content.textContent = target;
        finishFlush();
        return;
      }
      if (!timer && displayed < target.length) tick();
    }

    return {
      setTarget: function (text) {
        target = String(text || '');
        el.classList.remove('is-done');
        if (reduced) {
          displayed = target.length;
          content.textContent = target;
          if (opts.onTick) opts.onTick();
          return;
        }
        ensureTicking();
      },
      flush: function () {
        if (reduced || displayed >= target.length) return Promise.resolve();
        return new Promise(function (resolve) {
          flushResolve = resolve;
          ensureTicking();
        });
      },
      destroy: function () {
        cancelled = true;
        if (timer) clearTimeout(timer);
      },
    };
  }

  global.CampusTextType = {
    mountTextType: mountTextType,
    createStreamTypewriter: createStreamTypewriter,
  };
})(typeof window !== 'undefined' ? window : globalThis);
