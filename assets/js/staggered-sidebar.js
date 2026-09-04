/**
 * StaggeredMenu — 手机全屏遮罩 / PC 25% 分栏（CSS 驱动，避免 GSAP 布局卡死）
 */
(function () {
  'use strict';

  var MQ_MOBILE = window.matchMedia('(max-width: 1023px)');

  var gsap = null;
  var wrap = null;
  var panel = null;
  var isOpen = false;
  var ready = false;

  function loadGsap() {
    if (window.gsap) return Promise.resolve(window.gsap);
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js';
      s.async = true;
      s.onload = function () {
        resolve(window.gsap);
      };
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function isMobile() {
    return MQ_MOBILE.matches;
  }

  function isEnabled() {
    return false;
  }

  function getStaggerItems() {
    if (!panel) return [];
    return Array.from(
      panel.querySelectorAll(
        '.sidebar-nav .sidebar-item, .sidebar-section, .sidebar-section--agents, .agent-list .agent-item, #conv-list .conv-item, .sidebar-footer > *'
      )
    );
  }

  function clearItemStates() {
    if (!panel) return;
    var items = getStaggerItems();
    if (gsap && items.length) {
      gsap.set(items, { clearProps: 'transform,opacity' });
    } else {
      items.forEach(function (el) {
        el.style.removeProperty('opacity');
        el.style.removeProperty('transform');
      });
    }
    if (panel) {
      panel.style.removeProperty('transform');
    }
  }

  function revealPanelAndItems() {
    clearItemStates();
    if (gsap && panel) {
      gsap.set(panel, { clearProps: 'transform,x,xPercent,opacity' });
    }
  }

  function animateItemsIn() {
    if (!gsap || prefersReducedMotion()) return;
    var items = getStaggerItems();
    if (!items.length) return;
    gsap.fromTo(
      items,
      { y: 12, opacity: 0 },
      {
        y: 0,
        opacity: 1,
        duration: 0.32,
        ease: 'power3.out',
        stagger: 0.025,
        onComplete: clearItemStates,
      }
    );
  }

  function ensureInit() {
    if (!wrap) wrap = document.getElementById('staggered-sidebar');
    if (!panel) panel = document.getElementById('sidebar');
    return !!(wrap && panel);
  }

  function applyOpenClasses() {
    if (!wrap || !panel) return;
    wrap.classList.add('is-open');
    wrap.classList.toggle('is-mobile', isMobile());
    wrap.classList.toggle('is-desktop', !isMobile());
    panel.classList.add('is-open');
    document.body.classList.add('chat-sidebar-open');
    isOpen = true;
  }

  function applyCloseClasses() {
    if (wrap) {
      wrap.classList.remove('is-open', 'is-mobile', 'is-desktop');
    }
    if (panel) {
      panel.classList.remove('is-open');
    }
    document.body.classList.remove('chat-sidebar-open');
    isOpen = false;
  }

  function openMenu() {
    ensureInit();
    if (!wrap || !panel) return false;
    if (isOpen) {
      revealPanelAndItems();
      return true;
    }
    applyOpenClasses();
    revealPanelAndItems();
    animateItemsIn();
    return true;
  }

  function closeMenu() {
    ensureInit();
    if (!wrap || !panel) {
      document.body.classList.remove('chat-sidebar-open');
      isOpen = false;
      return true;
    }
    applyCloseClasses();
    revealPanelAndItems();
    return true;
  }

  function refreshItems() {
    if (!isOpen) return;
    animateItemsIn();
  }

  function onMqChange() {
    if (!ensureInit()) return;
    var wasOpen = isOpen;
    applyCloseClasses();
    clearItemStates();
    if (wasOpen) {
      openMenu();
    }
  }

  function init() {
    if (!ensureInit()) return;
    if (wrap.dataset.staggeredReady === '1') return;
    wrap.dataset.staggeredReady = '1';

    wrap.classList.add('is-css-fallback');
    clearItemStates();

    loadGsap()
      .then(function (g) {
        gsap = g;
        ready = true;
        clearItemStates();
      })
      .catch(function () {
        ready = true;
        clearItemStates();
      });

    MQ_MOBILE.addEventListener('change', onMqChange);
  }

  window.CampusStaggeredSidebar = {
    isEnabled: isEnabled,
    isOpen: function () {
      return isOpen;
    },
    open: openMenu,
    close: closeMenu,
    refreshItems: refreshItems,
    clearItemStates: clearItemStates,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
