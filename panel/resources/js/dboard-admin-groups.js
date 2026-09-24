(() => {
  const endpoints = {
    machine: { list: 'server/machine/fetch', title: '服务器' },
    node: { list: 'server/manage/getNodes', title: '节点' },
  };
  const root = document.getElementById('root');
  if (!root) return;
  const state = {
    kind: null, items: [], groups: [], selected: 'all', error: '', loading: false,
    header: null, native: null, bar: null, results: null, modal: null, loadId: 0,
  };
  const bridge = window.DBoardAdminGroups = {
    version: 0,
    selected: { machine: 'all', node: 'all' },
    cache: { machine: null, node: null },
    filter(kind, rows) {
      const selected = this.selected[kind];
      if (!selected || selected === 'all') return rows;
      const cached = this.cache[kind];
      if (cached?.rows === rows && cached.selected === selected && cached.version === this.version) {
        return cached.result;
      }
      const groups = new Map((state.kind === kind ? state.items : [])
        .map(item => [String(item.id), groupName(item)]));
      const result = rows.filter(item => {
        const name = groups.get(String(item.id)) ?? groupName(item);
        return selected === 'ungrouped' ? !name : name === selected.slice(6);
      });
      this.cache[kind] = { rows, selected, version: this.version, result };
      return result;
    },
  };
  function notifyGroup() {
    if (!state.kind) return;
    bridge.selected[state.kind] = state.selected;
    bridge.version++;
    window.dispatchEvent(new Event('dboard-admin-group-change'));
  }
  const el = (tag, className, value) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (value !== undefined) node.textContent = value;
    return node;
  };
  const kindAtUrl = () => {
    const route = location.hash.startsWith('#/')
      ? location.hash.slice(1).split(/[?#]/, 1)[0]
      : location.pathname;
    return /\/server\/machine\/?$/.test(route) ? 'machine'
      : /\/server\/manage\/?$/.test(route) ? 'node' : null;
  };
  const apiPrefix = () => String(window.settings?.base_url || '/').replace(/\/?$/, '/')
    + 'api/v2/' + String(window.settings?.secure_path || '').replace(/^\/+|\/+$/g, '') + '/';
  const authToken = () => {
    try {
      const stored = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || '{}');
      return stored.expire == null || stored.expire > Date.now() ? stored.value : null;
    } catch { return null; }
  };
  async function request(path, body) {
    const authorization = authToken();
    if (!authorization) throw new Error('请先登录管理后台');
    const response = await fetch(apiPrefix() + path, {
      method: body === undefined ? 'GET' : 'POST',
      headers: { Authorization: authorization, 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const result = await response.json();
    if (!response.ok || (result.code && result.code !== 0)) {
      const first = result.errors && Object.values(result.errors).flat()[0];
      throw new Error(first || result.message || '操作失败');
    }
    return result.data;
  }
  function reset() {
    state.loadId++;
    if (state.kind) {
      bridge.selected[state.kind] = 'all';
      bridge.version++;
      window.dispatchEvent(new Event('dboard-admin-group-change'));
    }
    if (state.native) state.native.hidden = false;
    state.bar?.remove();
    state.results?.remove();
    state.modal?.remove();
    state.items = [];
    state.groups = [];
    state.selected = 'all';
    state.error = '';
    state.loading = false;
    state.header = state.native = state.bar = state.results = state.modal = null;
  }
  async function load() {
    if (!state.kind || state.loading) return;
    const kind = state.kind;
    const loadId = ++state.loadId;
    state.loading = true;
    render();
    try {
      const [items, groups] = await Promise.all([
        request(endpoints[kind].list),
        request('server/admin-group/fetch?kind=' + kind),
      ]);
      if (state.kind !== kind || state.loadId !== loadId) return;
      const previousAssignments = assignmentKey(state.items);
      state.items = Array.isArray(items) ? items : [];
      state.groups = Array.isArray(groups) ? groups : [];
      state.error = '';
      if (previousAssignments !== assignmentKey(state.items)) notifyGroup();
    } catch (error) {
      if (state.kind === kind && state.loadId === loadId) state.error = error.message;
    } finally {
      if (state.kind === kind && state.loadId === loadId) {
        state.loading = false;
        render();
      }
    }
  }
  function assignmentKey(items) {
    return JSON.stringify(items.map(item => [String(item.id), groupName(item)]).sort((a, b) => a[0].localeCompare(b[0])));
  }
  function groupName(item) {
    return String(item.admin_group || '').trim();
  }
  function groupCounts() {
    const counts = new Map();
    for (const item of state.items) {
      const name = groupName(item);
      counts.set(name, (counts.get(name) || 0) + 1);
    }
    return counts;
  }
  function selectGroup(key) {
    state.selected = key;
    notifyGroup();
    render();
  }
  function renderBar() {
    if (!state.bar) return;
    const counts = groupCounts();
    const names = new Set([...state.groups.map(group => group.name), ...counts.keys()].filter(Boolean));
    if (state.selected.startsWith('group:') && !names.has(state.selected.slice(6))) {
      state.selected = 'all';
      notifyGroup();
    }
    const title = el('strong', '', '管理分组');
    const chips = el('div', 'dboard-admin-group-chips');
    const options = [
      ['all', '全部', state.items.length],
      ['ungrouped', '未分组', counts.get('') || 0],
      ...[...names].sort((a, b) => a.localeCompare(b, 'zh-CN'))
        .map(name => ['group:' + name, name, counts.get(name) || 0]),
    ];
    for (const [key, label, count] of options) {
      const button = el('button', 'dboard-admin-group-chip' + (state.selected === key ? ' active' : ''), label);
      button.type = 'button';
      button.title = label + '（' + count + '）';
      button.setAttribute('aria-pressed', String(state.selected === key));
      button.append(el('span', 'dboard-admin-group-count', String(count)));
      button.addEventListener('click', () => selectGroup(key));
      chips.append(button);
    }
    const manage = el('button', 'dboard-admin-group-manage', '管理分组');
    manage.type = 'button';
    manage.disabled = state.loading;
    manage.addEventListener('click', () => openManager());
    const head = el('div', 'dboard-admin-group-head');
    head.append(title, chips, manage);
    if (state.kind === 'machine') {
      const sort = el('button', 'dboard-admin-group-manage', '拖动排序');
      sort.type = 'button';
      sort.addEventListener('click', () => window.DBoardMachineSort?.open());
      head.append(sort);
    }
    // Keep the same controls during polling so focus and horizontal scroll survive.
    const renderKey = JSON.stringify([state.kind, state.selected, options]);
    if (state.bar.dataset.renderKey !== renderKey) {
      const scrollLeft = state.bar.querySelector('.dboard-admin-group-chips')?.scrollLeft || 0;
      const status = el('p', 'dboard-admin-group-status');
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      state.bar.replaceChildren(head, status);
      chips.scrollLeft = scrollLeft;
      state.bar.dataset.renderKey = renderKey;
    }
    state.bar.setAttribute('aria-busy', String(state.loading));
    state.bar.querySelector('.dboard-admin-group-manage').disabled = state.loading;
    const status = state.bar.querySelector('.dboard-admin-group-status');
    const message = state.error || (state.loading && !state.items.length ? '正在读取分组…' : '');
    if (status.textContent !== message) status.textContent = message;
    status.title = message;
    status.style.visibility = message ? 'visible' : 'hidden';
  }
  function renderResults() {
    if (state.native) state.native.hidden = false;
    if (state.results) {
      state.results.hidden = true;
      state.results.replaceChildren();
    }
  }
  function render() {
    renderBar();
    renderResults();
  }
  function closeManager() {
    state.modal?.remove();
    state.modal = null;
  }
  function openManager() {
    if (!state.kind || state.loading) return;
    closeManager();
    const kind = state.kind;
    const api = 'server/admin-group/';
    let selectedId = state.groups.find(group => 'group:' + group.name === state.selected)?.id
      ?? state.groups[0]?.id ?? null;
    let draft = new Set();
    const currentGroup = () => state.groups.find(group => String(group.id) === String(selectedId));
    const overlay = el('div', 'dboard-admin-group-overlay');
    const dialog = el('section', 'dboard-admin-group-dialog');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    const head = el('div', 'dboard-admin-group-dialog-head');
    head.append(el('h2', '', '管理' + endpoints[kind].title + '分组'));
    const close = el('button', '', '关闭');
    close.type = 'button';
    close.addEventListener('click', closeManager);
    head.append(close);
    const hint = el('p', 'dboard-admin-group-meta', '先创建分组，再勾选要加入的' + endpoints[kind].title + '并保存。每项只属于一个后台分组。');
    const status = el('p', 'dboard-admin-group-status');
    status.setAttribute('role', 'status');

    const createForm = el('form', 'dboard-admin-group-create');
    const createInput = el('input', 'dboard-admin-group-search');
    createInput.type = 'text';
    createInput.maxLength = 64;
    createInput.placeholder = '输入新分组名称';
    createInput.setAttribute('aria-label', '新分组名称');
    const createButton = el('button', '', '创建分组');
    createButton.type = 'submit';
    createForm.append(createInput, createButton);

    const picker = el('div', 'dboard-admin-group-picker');
    const pickerLabel = el('label', '', '选择分组');
    const groupSelect = el('select', 'dboard-admin-group-search');
    pickerLabel.append(groupSelect);
    picker.append(pickerLabel);
    const editForm = el('form', 'dboard-admin-group-edit');
    const renameInput = el('input', 'dboard-admin-group-search');
    renameInput.type = 'text';
    renameInput.maxLength = 64;
    renameInput.setAttribute('aria-label', '修改分组名称');
    const renameButton = el('button', '', '重命名');
    renameButton.type = 'submit';
    const dropButton = el('button', 'dboard-admin-group-danger', '删除分组');
    dropButton.type = 'button';
    editForm.append(renameInput, renameButton, dropButton);

    const search = el('input', 'dboard-admin-group-search');
    search.type = 'search';
    search.placeholder = '搜索' + endpoints[kind].title + '名称或 ID';
    const memberTools = el('div', 'dboard-admin-group-member-tools');
    const count = el('span', 'dboard-admin-group-meta');
    const selectVisible = el('button', '', '全选搜索结果');
    selectVisible.type = 'button';
    const clearVisible = el('button', '', '清除搜索结果');
    clearVisible.type = 'button';
    memberTools.append(count, selectVisible, clearVisible);
    const list = el('div', 'dboard-admin-group-editor-list');
    const saveMembers = el('button', 'dboard-admin-group-save', '保存成员');
    saveMembers.type = 'button';

    const visibleItems = () => {
      const query = search.value.trim().toLowerCase();
      return state.items.filter(item => !query ||
        [item.name, item.id, item.code, item.notes].some(value => String(value ?? '').toLowerCase().includes(query)));
    };
    const drawRows = () => {
      list.replaceChildren();
      const group = currentGroup();
      const items = group ? visibleItems() : [];
      count.textContent = group ? '已选 ' + draft.size + ' / ' + state.items.length + ' 项' : '请先创建或选择分组';
      editForm.hidden = search.hidden = memberTools.hidden = list.hidden = saveMembers.hidden = !group;
      if (!group) return;
      for (const item of items) {
        const row = el('label', 'dboard-admin-group-select-row');
        const box = el('input');
        box.type = 'checkbox';
        box.checked = draft.has(String(item.id));
        box.setAttribute('aria-label', '将' + (item.name || item.id) + '加入' + group.name);
        box.addEventListener('change', () => {
          if (box.checked) draft.add(String(item.id));
          else draft.delete(String(item.id));
          count.textContent = '已选 ' + draft.size + ' / ' + state.items.length + ' 项';
        });
        const details = el('span', 'dboard-admin-group-item');
        details.append(el('strong', '', item.name || '未命名'));
        const oldGroup = groupName(item);
        details.append(el('small', '', 'ID ' + item.id + (oldGroup && oldGroup !== group.name ? ' · 当前在“' + oldGroup + '”' : '')));
        row.append(box, details);
        list.append(row);
      }
      if (!items.length) list.append(el('p', 'dboard-admin-group-empty', '没有匹配的项目'));
    };
    const drawGroupSelector = () => {
      groupSelect.replaceChildren();
      const empty = el('option', '', state.groups.length ? '请选择分组' : '还没有分组，请先创建');
      empty.value = '';
      groupSelect.append(empty);
      for (const group of [...state.groups].sort((a, b) => a.name.localeCompare(b.name, 'zh-CN'))) {
        const option = el('option', '', group.name);
        option.value = String(group.id);
        groupSelect.append(option);
      }
      groupSelect.value = currentGroup() ? String(selectedId) : '';
      renameInput.value = currentGroup()?.name || '';
      drawRows();
    };
    const chooseGroup = id => {
      selectedId = id;
      const group = currentGroup();
      draft = new Set(state.items.filter(item => groupName(item) === group?.name)
        .map(item => String(item.id)));
      search.value = '';
      drawGroupSelector();
    };
    groupSelect.addEventListener('change', () => chooseGroup(groupSelect.value || null));
    search.addEventListener('input', drawRows);
    selectVisible.addEventListener('click', () => {
      for (const item of visibleItems()) draft.add(String(item.id));
      drawRows();
    });
    clearVisible.addEventListener('click', () => {
      for (const item of visibleItems()) draft.delete(String(item.id));
      drawRows();
    });

    createForm.addEventListener('submit', async event => {
      event.preventDefault();
      const name = createInput.value.trim();
      if (!name) return void (status.textContent = '请输入分组名称');
      createButton.disabled = true;
      status.textContent = '正在创建分组…';
      try {
        const group = await request(api + 'save', { kind, name });
        state.groups.push(group);
        createInput.value = '';
        chooseGroup(group.id);
        render();
        status.textContent = '分组“' + group.name + '”已创建，请勾选成员并保存';
      } catch (error) {
        status.textContent = error.message;
      } finally {
        createButton.disabled = false;
      }
    });
    editForm.addEventListener('submit', async event => {
      event.preventDefault();
      const group = currentGroup();
      if (!group) return;
      const name = renameInput.value.trim();
      if (!name) return void (status.textContent = '请输入分组名称');
      renameButton.disabled = true;
      try {
        const oldName = group.name;
        const updated = await request(api + 'save', { id: group.id, kind, name });
        for (const item of state.items) if (groupName(item) === oldName) item.admin_group = updated.name;
        group.name = updated.name;
        if (state.selected === 'group:' + oldName) state.selected = 'group:' + updated.name;
        notifyGroup();
        render();
        drawGroupSelector();
        status.textContent = '分组已重命名';
      } catch (error) {
        status.textContent = error.message;
      } finally {
        renameButton.disabled = false;
      }
    });
    saveMembers.addEventListener('click', async () => {
      const group = currentGroup();
      if (!group) return;
      saveMembers.disabled = true;
      status.textContent = '正在保存成员…';
      try {
        const ids = [...draft].map(Number);
        await request(api + 'syncMembers', { id: group.id, item_ids: ids });
        for (const item of state.items) {
          if (draft.has(String(item.id))) item.admin_group = group.name;
          else if (groupName(item) === group.name) item.admin_group = null;
        }
        notifyGroup();
        render();
        drawRows();
        status.textContent = '已保存 ' + draft.size + ' 个成员';
      } catch (error) {
        status.textContent = error.message;
      } finally {
        saveMembers.disabled = false;
      }
    });
    dropButton.addEventListener('click', async () => {
      const group = currentGroup();
      if (!group || !window.confirm('删除分组“' + group.name + '”？组内项目会变为未分组。')) return;
      dropButton.disabled = true;
      try {
        await request(api + 'drop', { id: group.id });
        for (const item of state.items) if (groupName(item) === group.name) item.admin_group = null;
        state.groups = state.groups.filter(item => item.id !== group.id);
        if (state.selected === 'group:' + group.name) state.selected = 'all';
        notifyGroup();
        render();
        chooseGroup(state.groups[0]?.id ?? null);
        status.textContent = '分组已删除';
      } catch (error) {
        status.textContent = error.message;
        dropButton.disabled = false;
      }
    });

    dialog.append(head, hint, createForm, picker, editForm, search, memberTools, list, saveMembers, status);
    overlay.append(dialog);
    overlay.addEventListener('click', event => { if (event.target === overlay) closeManager(); });
    overlay.addEventListener('keydown', event => { if (event.key === 'Escape') closeManager(); });
    document.body.append(overlay);
    state.modal = overlay;
    chooseGroup(selectedId);
    createInput.focus();
  }
  function mount() {
    const kind = kindAtUrl();
    if (kind !== state.kind) {
      reset();
      state.kind = kind;
      if (kind) load();
    }
    if (!kind) return;
    const heading = root.querySelector('h2.text-2xl.font-bold.tracking-tight');
    const header = heading?.parentElement?.parentElement;
    if (!header || !root.contains(header)) return;
    if (state.header === header && state.bar?.isConnected && state.results?.isConnected) {
      const native = state.results.nextElementSibling;
      if (native && native !== state.native) {
        state.native = native;
        native.setAttribute('data-dboard-group-native', '');
        renderResults();
      }
      return;
    }
    if (state.native) state.native.hidden = false;
    state.bar?.remove();
    state.results?.remove();
    state.header = header;
    state.native = header.nextElementSibling;
    state.native?.setAttribute('data-dboard-group-native', '');
    state.bar = el('div', 'dboard-admin-group-bar');
    state.results = el('section', 'dboard-admin-group-results');
    header.after(state.bar, state.results);
    render();
  }
  let scheduled = false;
  const observer = new MutationObserver(() => {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => { scheduled = false; mount(); });
  });
  observer.observe(root, { childList: true, subtree: true });
  window.addEventListener('popstate', mount);
  window.addEventListener('hashchange', mount);
  window.addEventListener('focus', () => { if (state.kind) load(); });
  setInterval(() => { if (state.kind) load(); }, 30000);
  mount();
})();
