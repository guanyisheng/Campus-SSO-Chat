/**
 * RotatingText — vanilla port (React Bits / motion)
 */
(function () {
  'use strict';

  const ENTER_MS = 520;
  const EXIT_MS = 420;

  function splitIntoCharacters(text) {
    if (typeof Intl !== 'undefined' && Intl.Segmenter) {
      try {
        const segmenter = new Intl.Segmenter('zh', { granularity: 'grapheme' });
        return Array.from(segmenter.segment(text), function (seg) {
          return seg.segment;
        });
      } catch (_) {}
    }
    return Array.from(text);
  }

  function parseTexts(raw) {
    if (Array.isArray(raw)) return raw.filter(Boolean);
    if (typeof raw === 'string') {
      try {
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) return parsed.filter(Boolean);
      } catch (_) {}
    }
    return [];
  }

  function getStaggerDelay(index, total, staggerFrom, staggerDuration) {
    const step = Math.max(0, staggerDuration) * 1000;
    if (staggerFrom === 'last') return (total - 1 - index) * step;
    if (staggerFrom === 'center') {
      const center = Math.floor(total / 2);
      return Math.abs(center - index) * step;
    }
    if (staggerFrom === 'random') {
      const randomIndex = Math.floor(Math.random() * total);
      return Math.abs(randomIndex - index) * step;
    }
    return index * step;
  }

  function buildWordElements(text, splitBy) {
    const words = splitBy === 'lines' ? text.split('\n') : text.split(' ');
    return words.map(function (word, i, arr) {
      return {
        characters: splitBy === 'characters' ? splitIntoCharacters(word) : [word],
        needsSpace: i !== arr.length - 1 && splitBy !== 'lines',
      };
    });
  }

  function buildLayer(text, options) {
    const layer = document.createElement('span');
    layer.className = options.splitBy === 'lines' ? 'text-rotate-lines' : 'text-rotate';
    layer.setAttribute('aria-hidden', 'true');

    const wordObjs = buildWordElements(text, options.splitBy);
    let charOffset = 0;
    const totalChars = wordObjs.reduce(function (sum, w) {
      return sum + w.characters.length;
    }, 0);

    wordObjs.forEach(function (wordObj, wordIndex) {
      const wordEl = document.createElement('span');
      wordEl.className = 'text-rotate-word';
      if (options.splitLevelClassName) {
        options.splitLevelClassName.split(/\s+/).forEach(function (c) {
          if (c) wordEl.classList.add(c);
        });
      }

      wordObj.characters.forEach(function (char, charIndex) {
        const wrap = document.createElement('span');
        wrap.className = 'text-rotate-char-wrap';
        const el = document.createElement('span');
        el.className = 'text-rotate-element';
        if (options.elementLevelClassName) {
          options.elementLevelClassName.split(/\s+/).forEach(function (c) {
            if (c) el.classList.add(c);
          });
        }
        el.textContent = char;
        wrap.appendChild(el);
        wordEl.appendChild(wrap);

        const delay = getStaggerDelay(
          charOffset + charIndex,
          totalChars,
          options.staggerFrom,
          options.staggerDuration
        );
        el.style.setProperty('--rt-delay', delay + 'ms');
      });

      charOffset += wordObj.characters.length;
      if (wordObj.needsSpace) {
        const space = document.createElement('span');
        space.className = 'text-rotate-space';
        space.textContent = ' ';
        wordEl.appendChild(space);
      }
      layer.appendChild(wordEl);
    });

    return layer;
  }

  function animateLayer(layer, className, baseDuration) {
    const elements = layer.querySelectorAll('.text-rotate-element');
    let maxDelay = 0;
    elements.forEach(function (el) {
      const delay = parseFloat(el.style.getPropertyValue('--rt-delay') || '0') || 0;
      maxDelay = Math.max(maxDelay, delay);
      el.classList.remove('is-enter', 'is-exit');
      void el.offsetWidth;
      el.classList.add(className);
    });
    return maxDelay + baseDuration;
  }

  function mountRotatingText(host, userOptions) {
    if (!host || host.dataset.rtMounted === '1') return null;

    const texts = parseTexts(userOptions.texts || host.getAttribute('data-texts'));
    if (!texts.length) return null;

    const options = {
      texts: texts,
      rotationInterval: parseInt(userOptions.rotationInterval || host.getAttribute('data-interval') || '2000', 10),
      staggerDuration: parseFloat(userOptions.staggerDuration || host.getAttribute('data-stagger-duration') || '0.025'),
      staggerFrom: userOptions.staggerFrom || host.getAttribute('data-stagger-from') || 'last',
      splitBy: userOptions.splitBy || host.getAttribute('data-split-by') || 'characters',
      loop: userOptions.loop !== false,
      auto: userOptions.auto !== false,
      mainClassName: userOptions.mainClassName || host.getAttribute('data-main-class') || '',
      splitLevelClassName: userOptions.splitLevelClassName || '',
      elementLevelClassName: userOptions.elementLevelClassName || '',
    };

    host.textContent = '';
    host.classList.add('text-rotate-host');

    const root = document.createElement('span');
    root.className = 'text-rotate';
    if (options.mainClassName) {
      options.mainClassName.split(/\s+/).forEach(function (c) {
        if (c) root.classList.add(c);
      });
    }

    const sr = document.createElement('span');
    sr.className = 'text-rotate-sr-only';
    root.appendChild(sr);

    const stage = document.createElement('span');
    stage.className = 'text-rotate-stage';
    root.appendChild(stage);

    host.appendChild(root);

    let currentIndex = 0;
    let busy = false;
    let timerId = null;

    function showIndex(index, animateIn) {
      currentIndex = index;
      sr.textContent = texts[index];
      const layer = buildLayer(texts[index], options);
      stage.innerHTML = '';
      stage.appendChild(layer);
      if (animateIn !== false) {
        animateLayer(layer, 'is-enter', ENTER_MS);
      }
    }

    function next() {
      if (busy || texts.length < 2) return;
      busy = true;
      const layer = stage.querySelector('.text-rotate');
      const wait = layer ? animateLayer(layer, 'is-exit', EXIT_MS) : EXIT_MS;
      window.setTimeout(function () {
        let nextIndex = currentIndex + 1;
        if (nextIndex >= texts.length) nextIndex = options.loop ? 0 : currentIndex;
        if (nextIndex !== currentIndex) showIndex(nextIndex, true);
        busy = false;
      }, wait);
    }

    function startAuto() {
      stopAuto();
      if (!options.auto || texts.length < 2) return;
      timerId = window.setInterval(next, Math.max(options.rotationInterval, 800));
    }

    function stopAuto() {
      if (timerId) {
        clearInterval(timerId);
        timerId = null;
      }
    }

    showIndex(0, true);
    startAuto();

    host.dataset.rtMounted = '1';

    return {
      next: next,
      jumpTo: function (i) {
        if (busy) return;
        const idx = Math.max(0, Math.min(i, texts.length - 1));
        if (idx !== currentIndex) showIndex(idx, true);
      },
      destroy: function () {
        stopAuto();
        host.dataset.rtMounted = '0';
        host.innerHTML = '';
      },
    };
  }

  function boot() {
    const welcome = document.getElementById('chat-rotating-text');
    if (welcome) {
      const cfg = window.CAMPUS_CHAT || {};
      mountRotatingText(welcome, {
        texts: cfg.welcomeRotatingTexts || welcome.getAttribute('data-texts'),
        rotationInterval: cfg.welcomeRotateInterval || 2000,
        staggerFrom: 'last',
        staggerDuration: 0.025,
        mainClassName: 'text-rotate--welcome',
      });
    }

    document.querySelectorAll('[data-rotating-text]:not(#chat-rotating-text)').forEach(function (el) {
      mountRotatingText(el, {});
    });
  }

  window.mountRotatingText = mountRotatingText;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
