(function () {
  'use strict';

  function qs(id) {
    return document.getElementById(id);
  }

  function openProfileModal() {
    var modal = qs('profile-modal');
    if (!modal) return;
    var quotaHost = qs('profile-quota-host');
    var sidebarQuota = qs('sidebar-quota');
    if (quotaHost && sidebarQuota) {
      quotaHost.innerHTML = sidebarQuota.innerHTML;
    } else if (quotaHost && window.renderQuotaBars && window.CAMPUS_CHAT && window.CAMPUS_CHAT.quota) {
      window.renderQuotaBars(quotaHost, window.CAMPUS_CHAT.quota);
    }
    modal.hidden = false;
    document.body.classList.add('profile-modal-open');
    if (window.renderIcons) window.renderIcons(modal);
  }

  function closeProfileModal() {
    var modal = qs('profile-modal');
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('profile-modal-open');
  }

  function bindProfileModal() {
    var modal = qs('profile-modal');
    var openBtn = qs('btn-open-profile');
    if (!modal || !openBtn) return;

    openBtn.addEventListener('click', function () {
      openProfileModal();
    });

    modal.querySelector('.profile-modal__backdrop')?.addEventListener('click', closeProfileModal);
    modal.querySelector('.profile-modal__close')?.addEventListener('click', closeProfileModal);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) {
        closeProfileModal();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindProfileModal);
  } else {
    bindProfileModal();
  }

  window.CampusProfile = {
    open: openProfileModal,
    close: closeProfileModal,
  };
})();
