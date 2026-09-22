(() => {
  const apiPrefix = () => String(window.settings?.base_url || '/').replace(/\/?$/, '/')
    + 'api/v2/' + String(window.settings?.secure_path || '').replace(/^\/+|\/+$/g, '') + '/server/machine/';
  const authToken = () => {
    try {
      const stored = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || '{}');
      return stored.expire == null || stored.expire > Date.now() ? stored.value : null;
    } catch { return null; }
  };
  async function request(action, body) {
    const authorization = authToken();
    if (!authorization) throw new Error('请先登录管理后台');
    const response = await fetch(apiPrefix() + action, {
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
  async function open() {
    if (overlay) return;
    overlay = el('div', 'dboard-machine-sort-overlay');
    const dialog = el('section', 'dboard-machine-sort-dialog');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-label', '服务器拖动排序');
    const heading = el('h2', '', '服务器拖动排序');
    const description = el('p', '', '拖动服务器调整顺序，手机上可使用上下按钮。保存后，服务器管理列表会按此顺序显示。');
    const list = el('ol', 'dboard-machine-sort-list');
    const status = el('p', 'dboard-machine-sort-status', '正在读取服务器…');
    status.setAttribute('role', 'status');
    const actions = el('div', 'dboard-machine-sort-actions');
    const cancel = el('button', '', '取消');
    const save = el('button', 'primary', '保存排序');
    save.disabled = true;
    const close = () => {
      if (save.disabled && save.textContent === '正在保存…') return;
      overlay?.remove();
      overlay = null;
    };
    cancel.addEventListener('click', close);
    actions.append(cancel, save);
    dialog.append(heading, description, list, status, actions);
    overlay.append(dialog);
    overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
    overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    document.body.append(overlay);
    cancel.focus();
    try {
      const machines = await request('fetch');
      if (!overlay?.isConnected) return;
      if (!Array.isArray(machines)) throw new Error('服务器列表格式不正确');
      if (!machines.length) { status.textContent = '暂无服务器'; return; }
      status.textContent = '';
      let dragged = null;
      for (const machine of machines) {
        const row = el('li', 'dboard-machine-sort-row');
        row.dataset.machineId = String(machine.id);
        const handle = el('button', 'dboard-machine-sort-handle', '⠿');
        handle.type = 'button';
        handle.draggable = true;
        handle.title = '拖动排序';
        handle.setAttribute('aria-label', '拖动 ' + machine.name);
        const name = el('span', 'dboard-machine-sort-name', machine.name);
        const meta = el('small', '', 'SID: ' + machine.id + (machine.admin_group ? ' · ' + machine.admin_group : ''));
        const label = el('span', 'dboard-machine-sort-label');
        label.append(name, meta);
        handle.addEventListener('dragstart', event => {
          dragged = row;
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', row.dataset.machineId);
          row.classList.add('dragging');
        });
        handle.addEventListener('dragend', () => { row.classList.remove('dragging'); dragged = null; });
        row.addEventListener('dragover', event => {
          if (dragged) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; }
        });
        row.addEventListener('drop', event => {
          event.preventDefault();
          if (!dragged || dragged === row) return;
          const before = [...list.children].indexOf(dragged) > [...list.children].indexOf(row);
          list.insertBefore(dragged, before ? row : row.nextSibling);
          dragged = null;
        });
        const controls = el('span', 'dboard-machine-sort-controls');
        for (const [direction, text, symbol] of [[-1, '上移', '↑'], [1, '下移', '↓']]) {
          const button = el('button', '', symbol);
          button.type = 'button';
          button.title = text + machine.name;
          button.setAttribute('aria-label', text + machine.name);
          button.addEventListener('click', () => {
            const target = direction < 0 ? row.previousElementSibling : row.nextElementSibling;
            if (target) list.insertBefore(row, direction < 0 ? target : target.nextSibling);
          });
          controls.append(button);
        }
        row.append(handle, label, controls);
        list.append(row);
      }
      save.disabled = false;
      save.addEventListener('click', async () => {
        save.disabled = true;
        save.textContent = '正在保存…';
        status.textContent = '';
        try {
          const ids = [...list.children].map(row => Number(row.dataset.machineId));
          await request('sort', { ids });
          location.reload();
        } catch (error) {
          status.textContent = error.message || '保存失败';
          save.disabled = false;
          save.textContent = '保存排序';
        }
      });
    } catch (error) {
      if (overlay?.isConnected) status.textContent = error.message || '读取失败';
    }
  }
  window.DBoardMachineSort = { open };
})();
