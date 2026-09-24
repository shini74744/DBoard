(() => {
  const active = () => {
    const route = location.hash.startsWith('#/')
      ? location.hash.slice(1).split(/[?#]/, 1)[0]
      : location.pathname;
    return /\/server\/route\/?$/.test(route);
  };
  const endpoint = action => {
    const base = String(window.settings?.base_url || '').replace(/\/$/, '');
    const path = String(window.settings?.secure_path || '').replace(/^\/+|\/+$/g, '');
    return base + '/api/v2/' + path + '/server/route/' + action;
  };
  const token = () => {
    try { return JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || '{}').value; }
    catch { return null; }
  };
  let saving = false;
  async function save(table) {
    const visibleIds = [...table.querySelectorAll('tbody tr[data-outbound-id]')]
      .map(row => Number(row.dataset.outboundId));
    const headers = { Authorization: token(), 'Content-Type': 'application/json' };
    const listResponse = await fetch(endpoint('fetch'), { headers });
    const listBody = await listResponse.json();
    if (!listResponse.ok || !Array.isArray(listBody.data)) throw new Error('无法读取完整出站列表');
    const allIds = listBody.data.map(item => Number(item.id));
    const allSet = new Set(allIds);
    const visibleSet = new Set(visibleIds);
    if (!visibleIds.length || visibleSet.size !== visibleIds.length ||
        !visibleIds.every(id => Number.isSafeInteger(id) && allSet.has(id))) {
      throw new Error('出站列表已变化，请刷新后重试');
    }
    let next = 0;
    const ids = allIds.map(id => visibleSet.has(id) ? visibleIds[next++] : id);
    const response = await fetch(endpoint('sort'), {
      method: 'POST', headers, body: JSON.stringify({ ids }),
    });
    const body = await response.json();
    if (!response.ok || body.status !== 'success') throw new Error(body.message || '排序保存失败');
  }
  async function persist(table) {
    saving = true;
    const hint = table.parentElement.querySelector('.dboard-sort-hint');
    if (hint) hint.textContent = '正在保存出站顺序…';
    try { await save(table); location.reload(); }
    catch (error) { alert(error.message || '排序保存失败'); location.reload(); }
  }
  function move(row, direction, table) {
    if (saving) return;
    const target = direction < 0 ? row.previousElementSibling : row.nextElementSibling;
    if (!target) return;
    row.parentElement.insertBefore(row, direction < 0 ? target : target.nextSibling);
    persist(table);
  }
  function mount() {
    if (!active() || saving) return;
    const table = [...document.querySelectorAll('table')].find(t =>
      t.querySelector('thead th')?.textContent.trim().toLowerCase() === 'id' &&
      t.querySelector('tbody tr td:nth-child(7)'));
    if (!table) return;
    for (const row of table.querySelectorAll('tbody tr')) {
      const cell = row.cells[0];
      const badge = cell?.firstElementChild;
      const id = Number(row.dataset.outboundId);
      if (!Number.isSafeInteger(id) || id < 1) continue;
      row.dataset.outboundId = String(id);
      if (!row.dataset.dboardSortReady) {
        row.dataset.dboardSortReady = 'true';
      }
      if (cell.querySelector('.dboard-outbound-controls')) continue;
      const controls = document.createElement('span');
      controls.className = 'dboard-outbound-controls';
      const handle = document.createElement('button');
      handle.type = 'button';
      handle.className = 'dboard-outbound-handle';
      handle.textContent = '⠿';
      handle.title = '拖动调整出站顺序';
      handle.setAttribute('aria-label', '拖动调整出站顺序');
      controls.append(handle);
      for (const [direction, label, symbol] of [[-1, '上移出站', '↑'], [1, '下移出站', '↓']]) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'dboard-outbound-move';
        button.textContent = symbol;
        button.title = label;
        button.setAttribute('aria-label', label);
        button.addEventListener('click', () => move(row, direction, table));
        controls.append(button);
      }
      cell.prepend(controls);
    }
    if (!table.dataset.dboardPointerSort) {
      table.dataset.dboardPointerSort='true';
      window.DBoardSortDrag.bind(table.tBodies[0],{rowSelector:'tr[data-outbound-id]',handleSelector:'.dboard-outbound-handle',disabled:()=>saving,onEnd:()=>persist(table)});
    }
    if (!table.parentElement.querySelector('.dboard-sort-hint')) {
      const hint = document.createElement('p');
      hint.className = 'dboard-sort-hint';
      hint.textContent = '拖动 ID 左侧的把手调整出站顺序；手机上可使用上下箭头。';
      table.parentElement.insertBefore(hint, table);
    }
  }
  let pending = false;
  const root = document.getElementById('root');
  if (root) new MutationObserver(() => {
    if (pending) return;
    pending = true;
    requestAnimationFrame(() => { pending = false; mount(); });
  }).observe(root, { childList: true, subtree: true });
  window.addEventListener('hashchange', mount);
  window.addEventListener('popstate', mount);
  mount();
})();
