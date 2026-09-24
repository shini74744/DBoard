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
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 45000);
    try {
    const response = await fetch(api() + action, {
      signal: controller.signal,
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
    } finally { clearTimeout(timeout); }
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
    const host = overlay;
    const isOpen = () => overlay === host && host.isConnected;
    let polling = false;
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
    const notice = el('p', 'dboard-machine-sort-status', '下载和校验期间旧节点保持运行；重启后确认结果，异常时尝试回退。慢网络请耐心等待，勿重复下发。');
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
    let dispatching = false;
    let submittedIds = [];
    const selected = new Set();
    const statusNodes = new Map();
    const labels = { queued: '指令已下发', accepted: '任务已接收', downloading: '下载中', validating: '校验中', restarting: '重启中', verifying: '正在确认结果', rolling_back: '正在回退', rolled_back: '升级未完成，已回退', unknown: '结果待确认', success: '升级成功', failed: '升级失败', skipped: '已跳过' };
    const activeStates = new Set(['queued','accepted','downloading','validating','restarting','verifying','rolling_back']);
    const isPending = status => activeStates.has(status?.state) || (status?.state === 'unknown' && Date.now()/1000 - Number(status.created_at || 0) < 3300);
    const chosenIds = () => mode === 'all' ? [...eligibleIds] : [...selected].filter(id => eligibleIds.has(id));
    function refreshCount() {
      const ids = chosenIds();
      const tasks = entries.filter(entry => submittedIds.includes(Number(entry.id)));
      const pendingCount = tasks.filter(entry => isPending(entry.upgrade_status)).length;
      const successCount = tasks.filter(entry => entry.upgrade_status?.state === 'success').length;
      const failedCount = tasks.filter(entry => ['failed', 'rolled_back'].includes(entry.upgrade_status?.state)).length;
      count.textContent = running
        ? `后台任务 ${submittedIds.length} 台 · 进行中 ${pendingCount} 台 · 成功 ${successCount} 台 · 失败或回退 ${failedCount} 台。`
        : latestVersion
        ? `最新版本 ${latestVersion} · ${eligibleIds.size} 台待升级 · 已选择 ${ids.length} 台。`
        : '无法检测 GitHub 最新版本，暂不能发起升级。';
      confirm.disabled = dispatching || (!running && (!latestVersion || !ids.length));
      confirm.textContent = dispatching ? '正在下发…' : running ? '后台执行并关闭'
        : `${mode === "all" ? "升级全部服务器" : "升级所选服务器"}（${ids.length}）`;
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
        if (machine.upgrade_status?.state) update(id, machine.upgrade_status.state, machine.upgrade_status.message, machine.upgrade_status.target_version);
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
      if (!isOpen() || !submittedIds.length || polling) return;
      polling = true;
      try {
        let pending = false;
        for (const group of chunks(submittedIds, 25)) {
          const data = await request('upgradeStatus?ids=' + encodeURIComponent(group.join(',')));
          if (!isOpen()) return;
          for (const [id, item] of Object.entries(data || {})) {
            const state = item.status?.state;
            const machine = entries.find(entry => Number(entry.id) === Number(id));
            if (machine) { machine.node_version = item.version || machine.node_version; machine.upgrade_status = item.status; }
            if (state) update(id, state, item.status.message, item.status.target_version || item.version);
            const currentLabel = statusNodes.get(Number(id))?.parentElement?.querySelector('small');
            if (currentLabel) currentLabel.textContent = `SID: ${id} · 当前 ${item.version || '版本未知'}`;
            if (state === 'success') {
              eligibleIds.delete(Number(id)); selected.delete(Number(id));
              const checkbox = statusNodes.get(Number(id))?.parentElement?.querySelector('input');
              if (checkbox) { checkbox.checked = false; checkbox.disabled = true; }
            }
            if (isPending(item.status)) pending = true;
          }
        }
        refreshCount();
        if (!pending) {
          if (timer) clearInterval(timer);
          timer = null;
          running = false;
          notice.textContent = '本批任务状态已更新，请查看各服务器的结果。';
          try {
            const release = await request('latestRelease');
            if (!isOpen()) return;
            latestVersion = release.version;
            eligibleIds = new Set(release.upgradeable_machine_ids.map(Number));
          } catch { notice.textContent = '结果已更新，暂时无法刷新可升级列表，请稍后重新打开。'; }
          renderRows();
        }
      } catch (error) { if(isOpen()) notice.textContent = '暂时无法读取状态，正在重试；这不代表升级失败。'; }
      finally { polling = false; }
    }
    confirm.addEventListener('click', async () => {
      if (dispatching) return;
      if (running) { close(); return; }
      submittedIds = chosenIds();
      if (!submittedIds.length || running) return;
      running = true;
      dispatching = true;
      renderRows();
      notice.textContent = '正在提交升级任务，下发完成后可关闭窗口或离开页面。';
      try {
        let target = '';
        for (const group of chunks(submittedIds, 25)) {
          const data = await request('upgrade', { ids: group });
          target = data.target_version;
          for (const [id, item] of Object.entries(data.machines || {})) {
            const machine = entries.find(entry => Number(entry.id) === Number(id));
            if (machine) machine.upgrade_status = item;
            update(id, item.state, item.message, item.target_version);
          }
        }
        dispatching = false;
        if (!isOpen()) return;
        refreshCount();
        notice.textContent = `目标版本 ${target}：任务已下发，正在后台执行。可以关闭窗口或离开页面，稍后重新打开查看结果。`;
        if (timer) clearInterval(timer);
        timer = setInterval(poll, 3000);
        await poll();
      } catch (error) {
        dispatching = false;
        if (!isOpen()) return;
        refreshCount();
        notice.textContent = (error.message || '下发结果暂未确认') + '；正在查询服务器实际任务状态。';
        if (timer) clearInterval(timer);
        timer = setInterval(poll, 3000);
        await poll();
      }
    });
    try {
      const machines = await request('fetch');
      if (!isOpen()) return;
      if (!Array.isArray(machines)) throw new Error('服务器列表格式不正确');
      entries = machines;
      if (!entries.length) { count.textContent = '暂无服务器'; return; }
      submittedIds = entries.filter(entry => isPending(entry.upgrade_status)).map(entry => Number(entry.id));
      running = submittedIds.length > 0;
      renderRows();
      if (running) {
        notice.textContent = '升级任务正在后台执行，可以关闭窗口或离开页面，稍后重新打开查看结果。';
        if (timer) clearInterval(timer);
        timer = setInterval(poll, 3000);
        void poll();
      } else count.textContent = '正在检测 GitHub 最新版本…';
      const release = await request('latestRelease');
      if (!isOpen()) return;
      if (!Array.isArray(release?.upgradeable_machine_ids) || !release.version) {
        throw new Error('最新版本信息格式不正确');
      }
      latestVersion = release.version;
      eligibleIds = new Set(release.upgradeable_machine_ids.map(Number));
      renderRows();
    } catch (error) {
      if (!isOpen()) return;
      if (entries.length) { renderRows(); if (!running) notice.textContent = error.message || '版本检测失败'; }
      else count.textContent = error.message || '读取服务器失败';
    }
  }
  window.DBoardMachineUpgrade = { open };
})();
