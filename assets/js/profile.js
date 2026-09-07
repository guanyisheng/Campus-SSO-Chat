(function () {
  'use strict';

  function qs(id) {
    return document.getElementById(id);
  }

  function cfg() {
    return window.CAMPUS_CHAT || {};
  }

  function renderApiKeyStatus(status) {
    var baseEl = qs('profile-api-base');
    var endpointEl = qs('profile-api-endpoint');
    var statusEl = qs('profile-api-status');
    var revokeBtn = qs('btn-revoke-api-key');
    var newWrap = qs('profile-api-key-new');
    if (!statusEl) return;

    if (baseEl && status && status.base_url) {
      baseEl.textContent = 'Base URL：' + status.base_url;
    }
    if (endpointEl && status && status.endpoint) {
      endpointEl.textContent = '对话：POST ' + status.endpoint;
    }

    if (!status || status.enabled === false) {
      statusEl.textContent = 'API Key 功能已关闭';
      if (revokeBtn) revokeBtn.hidden = true;
      return;
    }

    if (status.has_key) {
      statusEl.textContent = '已生成 Key：' + (status.prefix || '') + '…';
      if (revokeBtn) revokeBtn.hidden = false;
    } else {
      statusEl.textContent = '尚未生成 API Key';
      if (revokeBtn) revokeBtn.hidden = true;
      if (newWrap) newWrap.hidden = true;
    }
  }

  function loadApiKeyStatus() {
    var url = cfg().userApiKeyUrl;
    if (!url) return Promise.resolve();
    return fetch(url, { credentials: 'same-origin' })
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok) throw new Error(data.error || '加载失败');
          renderApiKeyStatus(data);
        });
      })
      .catch(function () {
        renderApiKeyStatus(null);
      });
  }

  function postApiKeyAction(action) {
    var url = cfg().userApiKeyUrl;
    if (!url) return Promise.reject(new Error('未配置接口'));
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: action }),
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) throw new Error(data.error || '操作失败');
        return data;
      });
    });
  }

  function bindApiKeyControls() {
    var genBtn = qs('btn-generate-api-key');
    var revokeBtn = qs('btn-revoke-api-key');
    var copyBtn = qs('btn-copy-api-key');
    if (!genBtn) return;

    genBtn.addEventListener('click', function () {
      if (!confirm('生成新 Key 将使旧 Key 立即失效，是否继续？')) return;
      genBtn.disabled = true;
      postApiKeyAction('generate')
        .then(function (data) {
          renderApiKeyStatus(data.status || null);
          var wrap = qs('profile-api-key-new');
          var val = qs('profile-api-key-value');
          if (wrap && val && data.key) {
            val.textContent = data.key;
            wrap.hidden = false;
          }
          if (window.showToast && data.message) window.showToast(data.message);
        })
        .catch(function (err) {
          if (window.showToast) window.showToast(err.message || '生成失败');
        })
        .finally(function () {
          genBtn.disabled = false;
        });
    });

    revokeBtn?.addEventListener('click', function () {
      if (!confirm('确定撤销当前 API Key？')) return;
      revokeBtn.disabled = true;
      postApiKeyAction('revoke')
        .then(function (data) {
          renderApiKeyStatus(data.status || null);
          var wrap = qs('profile-api-key-new');
          if (wrap) wrap.hidden = true;
          if (window.showToast) window.showToast('已撤销');
        })
        .catch(function (err) {
          if (window.showToast) window.showToast(err.message || '撤销失败');
        })
        .finally(function () {
          revokeBtn.disabled = false;
        });
    });

    copyBtn?.addEventListener('click', function () {
      var val = qs('profile-api-key-value');
      var text = val ? val.textContent.trim() : '';
      if (!text) return;
      navigator.clipboard.writeText(text).then(function () {
        if (window.showToast) window.showToast('已复制');
      });
    });
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
    void loadApiKeyStatus();
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

    bindApiKeyControls();
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
  window.openProfileModal = openProfileModal;
})();
