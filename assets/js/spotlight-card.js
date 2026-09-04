/**
 * SpotlightCard — vanilla port (React Bits)
 */
(function () {
  'use strict';

  function initSpotlightCard(el) {
    if (!el || el.dataset.spotlightMounted === '1') return;

    const defaultColor = el.getAttribute('data-spotlight-color') ||
      'rgba(255, 255, 255, 0.12)';

    function update(clientX, clientY) {
      const rect = el.getBoundingClientRect();
      el.style.setProperty('--mouse-x', (clientX - rect.left) + 'px');
      el.style.setProperty('--mouse-y', (clientY - rect.top) + 'px');
      el.style.setProperty('--spotlight-color', defaultColor);
      el.classList.add('is-active');
    }

    el.addEventListener('mousemove', function (e) {
      update(e.clientX, e.clientY);
    });

    el.addEventListener('touchmove', function (e) {
      if (e.touches[0]) update(e.touches[0].clientX, e.touches[0].clientY);
    }, { passive: true });

    el.addEventListener('mouseleave', function () {
      el.classList.remove('is-active');
    });

    el.dataset.spotlightMounted = '1';
  }

  function mountSpotlightCards(root) {
    const scope = root || document;
    scope.querySelectorAll('[data-spotlight-card]').forEach(initSpotlightCard);
  }

  window.initSpotlightCard = initSpotlightCard;
  window.mountSpotlightCards = mountSpotlightCards;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      mountSpotlightCards();
    });
  } else {
    mountSpotlightCards();
  }
})();
