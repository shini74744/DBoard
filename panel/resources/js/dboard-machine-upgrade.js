(() => {
  const api = () => String(window.settings?.base_url || '/').replace(/\/?$/, '/')
    + 'api/v2/' + String(window.settings?.secure_path || '').replace(/^\/+|\/+$/g, '') + '/server/machine/';
  const token = () => {
    try {
      const entry = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || '{}');
      return entry.expire == null || entry.expire > Date.now() ? entry.value : null;
    } catch { return null; }
  };
  async function request(action, body) {
    const authorization = token();
    if (!authorization) throw new Error('请先登录管理后台');
    const response = await fetch(api() + action, {
      method: body === undefined ? 'GET' : 'POST',
      headers: { Authorization: authorization, 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const result = await response.json();
    if (!response.ok || result.status === 'fail' || (result.code && result.code !== 0)) {
      const first = result.errors && Object.values(result.errors).flat()[0];
      throw new Error(first || result.message || '操作失败');
    }
    return result.data;
  }
  const el = (tag, className, value) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (value !== undefined) node.textContent = value;
    return node;
  };
  let overlay = null;
  let timer = null;
  function close() {
    if (timer) clearInterval(timer);
    timer = null;
    overlay?.remove();
    overlay = null;
  }
  function chunks(items, size) {
    const result = [];
    for (let index = 0; index < items.length; index += size) result.push(items.slice(index, index + size));
    return result;
  }
  async function open() {
    if (overlay) return;
    overlay = el('div', 'dboard-machine-sort-overlay');
    const dialog = el('section', 'dboard-machine-sort-dialog dboard-machine-upgrade-dialog');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-label', '升级节点后端');
    dialog.append(el('h2', '', '升级节点后端'));
    dialog.append(el('p', '', '选择服务器或全部升级。每台服务器从 GitHub 最新 Release 下载节点程序并重启一次，其承载的节点会短暂断线。'));
    const modes = el('div', 'dboard-machine-upgrade-modes');
    const pickButton = el('button', '', '选择服务器');
    const allButton = el('button', '', '全部升级');
    modes.append(pickButton, allButton);
    const count = el('p', 'dboard-machine-upgrade-count', '正在读取服务器…');
    const list = el('ol', 'dboard-machine-sort-list');
    const notice = el('p', 'dboard-machine-sort-status', '旧版节点程序不支持后台升级，需要先手动更新一次。');
    notice.setAttribute('role', 'status');
    const actions = el('div', 'dboard-machine-sort-actions');
    const cancel = el('button', '', '关闭');
    const confirm = el('button', 'primary', '开始升级');
    confirm.disabled = true;
    cancel.addEventListener('click', close);
    actions.append(cancel, confirm);
    dialog.append(modes, count, list, notice, actions);
    overlay.append(dialog);
    overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
    overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    document.body.append(overlay);
    cancel.focus();
    let entries = [];
    let latestVersion = '';
    let eligibleIds = new Set();
    let mode = 'pick';
    let running = false;
    let submittedIds = [];
    const selected = new Set();
    const statusNodes = new Map();
    const labels = { queued: '指令已下发', accepted: '正在下载并升级', success: '升级成功',
      failed: '升级失败', skipped: '已跳过' };
    const chosenIds = () => mode === 'all' ? [...eligibleIds] : [...selected].filter(id => eligibleIds.has(id));
    function refreshCount() {
      const ids = chosenIds();
      count.textContent = latestVersion
        ? `最新版本 ${latestVersion} · ${eligibleIds.size} 台待升级 · 已选择 ${ids.length} 台。`
        : '无法检测 GitHub 最新版本，暂不能发起升级。';
      confirm.disabled = running || !latestVersion || !ids.length;
      confirm.textContent = `${mode === "all" ? "升级全部服务器" : "升级所选服务器"}（${ids.length}）`;
      pickButton.classList.toggle('active', mode === 'pick');
      allButton.classList.toggle('active', mode === 'all');
    }
    function renderRows() {
      list.replaceChildren();
      statusNodes.clear();
      for (const machine of entries) {
        const id = Number(machine.id);
        const row = el('li', 'dboard-machine-sort-row');
        const checkbox = el('input');
        checkbox.type = 'checkbox';
        checkbox.checked = eligibleIds.has(id) && (mode === 'all' || selected.has(id));
        checkbox.disabled = !eligibleIds.has(id) || mode === 'all' || running;
        checkbox.setAttribute('aria-label', `选择 ${machine.name || '服务器 #' + id}`);
        checkbox.addEventListener('change', () => {
          if (checkbox.checked) selected.add(id); else selected.delete(id);
          refreshCount();
        });
        const label = el('span', 'dboard-machine-sort-label');
        label.append(el('strong', '', machine.name || `服务器 #${id}`),
          el('small', '', `SID: ${id} · 当前 ${machine.node_version || '版本未知'}`));
        const state = el('span', 'dboard-machine-upgrade-state',
          !machine.upgrade_capable ? '离线或需先手动更新'
            : !latestVersion ? '版本检测失败' : eligibleIds.has(id) ? '可升级' : '已是最新版');
        statusNodes.set(id, state);
        row.append(checkbox, label, state);
        list.append(row);
      }
      refreshCount();
    }
    pickButton.addEventListener('click', () => { if (!running) { mode = 'pick'; renderRows(); } });
    allButton.addEventListener('click', () => { if (!running) { mode = 'all'; renderRows(); } });
    function update(id, state, message, version) {
      const node = statusNodes.get(Number(id));
      if (!node) return;
      node.textContent = (labels[state] || state || '等待中')
        + (version ? ` · ${version}` : '') + (message ? ` · ${message}` : '');
      node.dataset.state = state || '';
    }
    async function poll() {
      if (!overlay?.isConnected || !submittedIds.length) return;
      try {
        let pending = false;
        for (const group of chunks(submittedIds, 25)) {
          const data = await request('upgradeStatus?ids=' + encodeURIComponent(group.join(',')));
          for (const [id, item] of Object.entries(data || {})) {
            const state = item.status?.state;
            if (state) update(id, state, item.status.message, item.version || item.status.target_version);
            if (state === 'queued' || state === 'accepted') pending = true;
          }
        }
        if (!pending && timer) { clearInterval(timer); timer = null; }
      } catch (error) { notice.textContent = error.message || '读取升级状态失败'; }
    }
    confirm.addEventListener('click', async () => {
      submittedIds = chosenIds();
      if (!submittedIds.length || running) return;
      running = true;
      renderRows();
      confirm.textContent = '正在下发…';
      notice.textContent = '';
      try {
        let target = '';
        for (const group of chunks(submittedIds, 25)) {
          const data = await request('upgrade', { ids: group });
          target = data.target_version;
          for (const [id, item] of Object.entries(data.machines || {})) {
            update(id, item.state, item.message, item.target_version);
          }
        }
        notice.textContent = `目标版本 ${target}；等待服务器重新连接后确认结果。`;
        timer = setInterval(poll, 3000);
        await poll();
      } catch (error) {
        notice.textContent = error.message || '升级指令下发失败';
        running = false;
        refreshCount();
      }
    });
    try {
      const machines = await request('fetch');
      if (!overlay?.isConnected) return;
      if (!Array.isArray(machines)) throw new Error('服务器列表格式不正确');
      entries = machines;
      if (!entries.length) { count.textContent = '暂无服务器'; return; }
      count.textContent = '正在检测 GitHub 最新版本…';
      const release = await request('latestRelease');
      if (!overlay?.isConnected) return;
      if (!Array.isArray(release?.upgradeable_machine_ids) || !release.version) {
        throw new Error('最新版本信息格式不正确');
      }
      latestVersion = release.version;
      eligibleIds = new Set(release.upgradeable_machine_ids.map(Number));
      renderRows();
    } catch (error) {
      if (!overlay?.isConnected) return;
      if (entries.length) { renderRows(); notice.textContent = error.message || '版本检测失败'; }
      else count.textContent = error.message || '读取服务器失败';
    }
  }
  window.DBoardMachineUpgrade = { open };
})();
