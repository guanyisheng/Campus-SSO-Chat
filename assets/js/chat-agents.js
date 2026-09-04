(function () {
  'use strict';

  var ctx = null;
  var agents = [];
  var agentModels = [];
  var agentStatus = { max_user_agents: 3, user_agent_count: 0, can_create: true };
  var editingAgent = null;

  function c() {
    return ctx || {};
  }

  function cfg() {
    return (c().cfg || window.CAMPUS_CHAT || {});
  }

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function agentKey(agent) {
    if (!agent) return '';
    return String(agent.type || '') + ':' + String(agent.id || '');
  }

  function findAgent(key) {
    return agents.find(function (a) {
      return agentKey(a) === key;
    });
  }

  function renderSidebar() {
    var listEl = document.getElementById('agent-list');
    if (!listEl) return;
    listEl.innerHTML = '';
    if (!agents.length) {
      var empty = document.createElement('li');
      empty.className = 'agent-list__empty';
      empty.textContent = '暂无智能体';
      listEl.appendChild(empty);
      return;
    }
    var active = c().getActiveAgentRef ? c().getActiveAgentRef() : null;
    var activeKey = active ? active.type + ':' + active.id : '';
    agents.forEach(function (agent) {
      var li = document.createElement('li');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className =
        'agent-item' +
        (agentKey(agent) === activeKey ? ' is-active' : '') +
        (agent.is_preset ? ' is-preset' : '');
      btn.dataset.agentKey = agentKey(agent);
      var avatarHtml = agent.avatar_url
        ? '<img class="agent-item__avatar" src="' + escapeHtml(agent.avatar_url) + '" alt="">'
        : '<span class="agent-item__avatar agent-item__avatar--fallback">' +
          escapeHtml((agent.display_name || 'AI').slice(0, 1)) +
          '</span>';
      btn.innerHTML =
        avatarHtml +
        '<span class="agent-item__body">' +
        '<span class="agent-item__name">' +
        escapeHtml(agent.display_name) +
        '</span>' +
        (agent.is_preset
          ? '<span class="agent-item__tag">官方</span>'
          : '<span class="agent-item__tag agent-item__tag--mine">我的</span>') +
        '</span>';
      btn.addEventListener('click', function () {
        selectAgent(agent);
      });
      li.appendChild(btn);
      listEl.appendChild(li);
    });
  }

  function renderComposerPill() {
    var pill = document.getElementById('agent-active-pill');
    if (!pill) return;
    var activeRef = c().getActiveAgentRef ? c().getActiveAgentRef() : null;
    var agent = activeRef ? findAgent(activeRef.type + ':' + activeRef.id) : null;
    if (!agent) {
      pill.hidden = true;
      pill.innerHTML = '';
      if (typeof c().syncAgentTopbarClear === 'function') {
        c().syncAgentTopbarClear();
      }
      return;
    }
    pill.hidden = false;
    pill.innerHTML =
      '<span class="agent-active-pill__inner">' +
      (agent.avatar_url
        ? '<img class="agent-active-pill__avatar" src="' + escapeHtml(agent.avatar_url) + '" alt="">'
        : '') +
      '<span class="agent-active-pill__name">' +
      escapeHtml(agent.display_name) +
      '</span>' +
      '<button type="button" class="agent-active-pill__clear" id="btn-clear-agent" aria-label="退出智能体">×</button>' +
      '</span>';
    if (typeof c().syncAgentTopbarClear === 'function') {
      c().syncAgentTopbarClear();
    }
    var clearBtn = document.getElementById('btn-clear-agent');
    if (clearBtn) {
      clearBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (typeof c().startNewChatWithCurrentModel === 'function') {
          c().startNewChatWithCurrentModel();
        } else {
          clearActiveAgent();
        }
      });
    }
  }

  function applyAgentModel(agent) {
    if (!agent || !agent.model_id || !c().setModelId) return;
    c().setModelId(parseInt(agent.model_id, 10));
  }

  function setActiveAgent(agent) {
    if (!agent || !c().setActiveAgentRef) return;
    c().setActiveAgentRef({ type: agent.type, id: agent.id });
    if (c().setActiveAgentProfile) c().setActiveAgentProfile(agent);
    applyAgentModel(agent);
    renderSidebar();
    renderComposerPill();
  }

  function setActiveAgentUI(agent) {
    if (!agent) {
      clearActiveAgent();
      return;
    }
    if (c().setActiveAgentRef) {
      c().setActiveAgentRef({ type: agent.type, id: agent.id });
    }
    if (c().setActiveAgentProfile) c().setActiveAgentProfile(agent);
    applyAgentModel(agent);
    renderSidebar();
    renderComposerPill();
  }

  function setAgentConversationId(key, convId) {
    var agent = findAgent(key);
    if (agent) agent.conversation_id = convId;
  }

  function clearActiveAgent() {
    if (c().setActiveAgentRef) c().setActiveAgentRef(null);
    if (c().setActiveAgentProfile) c().setActiveAgentProfile(null);
    renderSidebar();
    renderComposerPill();
  }

  async function selectAgent(agent) {
    if (typeof c().openAgentChat === 'function') {
      await c().openAgentChat(agent);
      return;
    }
    setActiveAgent(agent);
    if (typeof c().createConversation === 'function') {
      await c().createConversation(true);
    }
  }

  function openModal(agent) {
    var modal = document.getElementById('agentModal');
    if (!modal) return;
    editingAgent = agent || null;
    var titleEl = document.getElementById('agent-modal-title');
    var nameEl = document.getElementById('agent-form-name');
    var descEl = document.getElementById('agent-form-desc');
    var promptEl = document.getElementById('agent-form-prompt');
    var modelEl = document.getElementById('agent-form-model');
    var avatarPreview = document.getElementById('agent-form-avatar-preview');
    var avatarInput = document.getElementById('agent-form-avatar');
    var limitHint = document.getElementById('agent-form-limit-hint');

    if (titleEl) titleEl.textContent = agent ? '编辑智能体' : '创建智能体';
    if (nameEl) nameEl.value = agent ? agent.display_name || '' : '';
    if (descEl) descEl.value = agent ? agent.description || '' : '';
    if (promptEl) promptEl.value = agent ? agent.system_prompt || '' : '';
    if (avatarInput) avatarInput.value = '';
    if (avatarPreview) {
      avatarPreview.innerHTML = agent && agent.avatar_url
        ? '<img src="' + escapeHtml(agent.avatar_url) + '" alt="">'
        : '<span class="agent-form-avatar-fallback">AI</span>';
    }
    if (modelEl) {
      modelEl.innerHTML = '<option value="0">（跟随当前模型）</option>';
      agentModels.forEach(function (m) {
        var opt = document.createElement('option');
        opt.value = String(m.id);
        opt.textContent = m.name || m.display_name || m.model_name;
        if (agent && String(agent.model_id) === String(m.id)) opt.selected = true;
        modelEl.appendChild(opt);
      });
    }
    if (limitHint) {
      limitHint.textContent =
        '已创建 ' +
        (agentStatus.user_agent_count || 0) +
        ' / ' +
        (agentStatus.max_user_agents || 3) +
        ' 个';
    }
    modal.hidden = false;
    document.body.classList.add('agent-modal-open');
    if (window.renderIcons) window.renderIcons(modal);
  }

  function closeModal() {
    var modal = document.getElementById('agentModal');
    if (modal) modal.hidden = true;
    document.body.classList.remove('agent-modal-open');
    editingAgent = null;
  }

  var initialAgentHandled = false;

  async function loadAgents() {
    var url = cfg().agentsUrl;
    if (!url) return;
    var res = await fetch(url, { credentials: 'same-origin' });
    var data = await res.json().catch(function () {
      return {};
    });
    if (!res.ok) throw new Error(data.error || '加载智能体失败');
    agents = data.agents || [];
    agentModels = data.models || [];
    agentStatus = data.status || agentStatus;
    renderSidebar();
    renderComposerPill();
    updateManageButton();

    var initialKey = String(cfg().initialAgent || '').trim();
    if (initialKey && !initialAgentHandled) {
      initialAgentHandled = true;
      var initialAgent = findAgent(initialKey);
      if (initialAgent && typeof c().openAgentChat === 'function') {
        try {
          await c().openAgentChat(initialAgent);
        } catch (err) {
          if (c().showToast) c().showToast(err.message || '打开智能体失败');
        }
      }
    }
  }

  function updateManageButton() {
    var btn = document.getElementById('btn-manage-agents');
    if (!btn) return;
    btn.disabled = false;
    btn.setAttribute('aria-disabled', agentStatus.can_create ? 'false' : 'true');
    btn.title = agentStatus.can_create
      ? '创建智能体（最多 ' + agentStatus.max_user_agents + ' 个）'
      : '已达上限，右键「我的」智能体可编辑';
  }

  async function saveAgent(e) {
    e.preventDefault();
    var form = document.getElementById('agent-form');
    if (!form) return;
    var fd = new FormData(form);
    fd.append('action', editingAgent ? 'update' : 'create');
    if (editingAgent) fd.append('id', String(editingAgent.id));

    var res = await fetch(cfg().agentsUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
    });
    var data = await res.json().catch(function () {
      return {};
    });
    if (!res.ok) {
      if (c().showToast) c().showToast(data.error || '保存失败');
      return;
    }
    agentStatus = data.status || agentStatus;
    closeModal();
    await loadAgents();
    if (c().showToast) c().showToast(editingAgent ? '智能体已更新' : '智能体已创建');
  }

  async function deleteAgent(agent) {
    if (!agent || agent.is_preset) return;
    if (!confirm('确定删除智能体「' + agent.display_name + '」？')) return;
    var res = await fetch(cfg().agentsUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', id: agent.id }),
    });
    var data = await res.json().catch(function () {
      return {};
    });
    if (!res.ok) {
      if (c().showToast) c().showToast(data.error || '删除失败');
      return;
    }
    var active = c().getActiveAgentRef ? c().getActiveAgentRef() : null;
    if (active && active.type === 'user' && active.id === agent.id) {
      clearActiveAgent();
    }
    agentStatus = data.status || agentStatus;
    await loadAgents();
    if (c().showToast) c().showToast('已删除');
  }

  function handleManageClick(e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    if (!agentStatus.can_create) {
      var mine = agents.filter(function (a) {
        return !a.is_preset;
      });
      if (mine.length === 1) {
        openModal(mine[0]);
        return;
      }
      if (mine.length > 1) {
        if (c().showToast) {
          c().showToast('已达上限：右键点击「我的」智能体可编辑');
        }
        return;
      }
      if (c().showToast) c().showToast('已达 ' + (agentStatus.max_user_agents || 3) + ' 个上限');
      return;
    }
    openModal(null);
  }

  function bindUi() {
    var btn = document.getElementById('btn-manage-agents');
    if (btn && btn.dataset.agentBound !== '1') {
      btn.dataset.agentBound = '1';
      btn.addEventListener('click', handleManageClick);
    }
    document.getElementById('agent-form')?.addEventListener('submit', saveAgent);
    document.getElementById('agent-form-avatar')?.addEventListener('change', function (e) {
      var file = e.target.files && e.target.files[0];
      var preview = document.getElementById('agent-form-avatar-preview');
      if (!file || !preview) return;
      preview.innerHTML = '<img src="' + URL.createObjectURL(file) + '" alt="">';
    });
    document.querySelectorAll('[data-close-agent-modal]').forEach(function (el) {
      el.addEventListener('click', closeModal);
    });
    document.getElementById('agent-list')?.addEventListener('contextmenu', function (e) {
      var btn = e.target.closest('.agent-item');
      if (!btn) return;
      var agent = findAgent(btn.dataset.agentKey || '');
      if (!agent || agent.is_preset) return;
      e.preventDefault();
      if (confirm('编辑「' + agent.display_name + '」？\n确定 = 编辑，取消 = 删除')) {
        openModal(agent);
      } else {
        deleteAgent(agent);
      }
    });
  }

  function syncFromConversation(data) {
    if (!data) return;
    var agent = data.agent;
    if (agent && agent.type && agent.id) {
      setActiveAgent(agent);
      return;
    }
    if (data.conversation && data.conversation.agent_type && data.conversation.agent_id) {
      var found = findAgent(data.conversation.agent_type + ':' + data.conversation.agent_id);
      if (found) setActiveAgent(found);
      else if (c().setActiveAgentRef) {
        c().setActiveAgentRef({
          type: data.conversation.agent_type,
          id: parseInt(data.conversation.agent_id, 10),
        });
        renderComposerPill();
        renderSidebar();
      }
      return;
    }
    clearActiveAgent();
  }

  function install(context) {
    ctx = context;
    bindUi();
    loadAgents()
      .then(function () {
        updateManageButton();
      })
      .catch(function (err) {
        if (c().showToast) c().showToast(err.message || '智能体加载失败');
      });
  }

  bindUi();

  function findAgentByRef(ref) {
    if (!ref || !ref.type || !ref.id) return null;
    return findAgent(String(ref.type) + ':' + String(ref.id));
  }

  window.CampusChatAgents = {
    install: install,
    loadAgents: loadAgents,
    syncFromConversation: syncFromConversation,
    clearActiveAgent: clearActiveAgent,
    setActiveAgentUI: setActiveAgentUI,
    setAgentConversationId: setAgentConversationId,
    findAgentByRef: findAgentByRef,
    getAgents: function () {
      return agents.slice();
    },
  };
})();
