(() => {
  const configs = {
    '/server/group': { resource: 'group', label: '权限组' },
    '/server/route': { resource: 'route', label: '出站规则' },
  };
  const current = () => configs[location.hash.slice(1).split(/[?#]/)[0].replace(/\/$/, '')];
  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  };
  async function request(config, action, body) {
    let authorization;
    try { authorization = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || '{}').value; } catch {}
    if (!authorization) throw new Error('请先登录管理后台');
    const base = String(window.settings?.base_url || '').replace(/\/$/, '');
    const secure = String(window.settings?.secure_path || '').replace(/^\/+|\/+$/g, '');
    const response = await fetch(base + '/api/v2/' + secure + '/server/' + config.resource + '/' + action, {
      method: body === undefined ? 'GET' : 'POST',
      headers: { Authorization: authorization, 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const result = await response.json();
    if (!response.ok || result.status === 'fail' || (result.code && result.code !== 0))
      throw new Error(result.message || '排序操作失败');
    return result.data;
  }
  let overlay = null;
  async function open(config, trigger) {
    if (overlay) return;
    const host = el('div', 'dboard-machine-sort-overlay');
    overlay = host;
    const dialog = el('section', 'dboard-machine-sort-dialog');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-label', config.label + '拖动排序');
    const list = el('ol', 'dboard-machine-sort-list');
    const status = el('p', 'dboard-machine-sort-status', '正在读取…');
    status.setAttribute('role', 'status');
    const cancel = el('button', '', '取消');
    const save = el('button', 'primary', '保存排序');
    cancel.type = save.type = 'button';
    save.disabled = true;
    let saving = false;
    const renumber = el('input'); renumber.type='checkbox';
    const renumberLabel=el('label','dboard-sort-renumber');
    renumberLabel.style.cssText='display:flex;align-items:center;gap:8px;margin:12px 0';
    renumberLabel.append(renumber,document.createTextNode('按当前排序从 1 重新分配显示 ID'));
    const preview=()=>{for(const [index,row]of [...list.children].entries()){
      if(config.resource==='route')row.querySelector('small').textContent='显示 ID: '+(renumber.checked?index+1:row.dataset.displayId)+' · 原始 ID: '+row.dataset.sortId;
    }};
    renumber.onchange=preview;
    const close = () => {
      if (saving) return;
      host.remove(); if (overlay === host) overlay = null;
      if (trigger.isConnected) trigger.focus();
    };
    const actions = el('div', 'dboard-machine-sort-actions');
    actions.append(cancel, save);
    dialog.append(el('h2', '', config.label + '拖动排序'),
      el('p', '', '拖动左侧把手调整顺序，也可使用上下按钮。保存后列表按此顺序显示。'),
      list, status, actions);
    if(config.resource==='route')dialog.insertBefore(renumberLabel,list);
    host.append(dialog); document.body.append(host);
    cancel.addEventListener('click', close);
    host.addEventListener('click', e => { if (e.target === host) close(); });
    host.addEventListener('keydown', e => {
      if (e.key === 'Escape') close();
      if (e.key === 'Tab') {
        const controls = [...dialog.querySelectorAll('button:not(:disabled),input:not(:disabled)')];
        const first = controls[0], last = controls[controls.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
    cancel.focus();
    try {
      const entries = await request(config, 'fetch');
      if (!host.isConnected) return;
      if (!Array.isArray(entries)) throw new Error('列表格式不正确');
      if (!entries.length) { status.textContent = '暂无' + config.label; return; }
      status.textContent = '';
      for (const entry of entries) {
        const row = el('li', 'dboard-machine-sort-row');
        row.dataset.sortId = String(entry.id); row.dataset.displayId=String(entry.display_id??entry.id);
        const handle = el('button', 'dboard-machine-sort-handle', '⠿');
        handle.type = 'button'; handle.style.touchAction = 'none';
        handle.setAttribute('aria-label', '拖动' + entry.name);
        const label = el('span', 'dboard-machine-sort-label');
        label.append(el('span', 'dboard-machine-sort-name', entry.name), el('small', '', 'ID: ' + entry.id));
        const controls = el('span', 'dboard-machine-sort-controls');
        for (const [direction, text, symbol] of [[-1, '上移', '↑'], [1, '下移', '↓']]) {
          const button = el('button', '', symbol); button.type = 'button';
          button.setAttribute('aria-label', text + entry.name);
          button.addEventListener('click', () => {
            if (saving) return;
            const target = direction < 0 ? row.previousElementSibling : row.nextElementSibling;
            if (target) { list.insertBefore(row, direction < 0 ? target : target.nextSibling); preview(); }
          });
          controls.append(button);
        }
        row.append(handle, label, controls); list.append(row);
      }
      window.DBoardSortDrag.bind(list,{rowSelector:'li[data-sort-id]',disabled:()=>saving,onMove:preview}); preview();
      save.disabled = false;
      save.addEventListener('click', async () => {
        saving = true; renumber.disabled = save.disabled = cancel.disabled = true;
        save.textContent = '正在保存…'; status.textContent = '';
        try {
          await request(config, 'sort', { ids: [...list.children].map(row => Number(row.dataset.sortId)), ...(config.resource==='route'?{renumber:renumber.checked}:{}) });
          location.reload();
        } catch (error) {
          status.textContent = error.message || '保存失败';
          saving = false; renumber.disabled = save.disabled = cancel.disabled = false; save.textContent = '保存排序';
        }
      });
    } catch (error) { if (host.isConnected) status.textContent = error.message || '读取失败'; }
  }
  function mount() {
    const config = current();
    if (!config) return;
    const content = document.querySelector('#content') || document.querySelector('#root');
    if (!content || content.querySelector('.dboard-resource-sort-trigger')) return;
    const heading = content.querySelector('h1, h2');
    if (!heading) return;
    const button = el('button', 'dboard-resource-sort-trigger', '拖动排序');
    button.type = 'button';
    button.style.cssText = 'margin:0.75rem 0;padding:.5rem .85rem;border:1px solid hsl(var(--border));border-radius:.5rem;background:hsl(var(--background));color:hsl(var(--foreground))';
    button.addEventListener('click', () => open(config, button));
    heading.parentElement.append(button);
  }
  let pending = false;
  const root = document.querySelector('#root');
  if (root) new MutationObserver(() => {
    if (pending) return; pending = true;
    requestAnimationFrame(() => { pending = false; mount(); });
  }).observe(root, { childList: true, subtree: true });
  window.addEventListener('hashchange', mount); mount();
})();
