(() => {
  const items = [
    ['store', '商店宣传'],
    ['notice', '通知中心'],
    ['binding', '机器人绑定'],
    ['user-notice', '用户须知'],
  ];
  const promoFields = [
    ['hero_title', '页面标题'], ['hero_description', '页面介绍'],
    ['global_nodes_title', '宣传卡片 1 · 标题'], ['global_nodes_description', '宣传卡片 1 · 说明'],
    ['speed_title', '宣传卡片 2 · 标题'], ['speed_description', '宣传卡片 2 · 说明'],
    ['streaming_title', '宣传卡片 3 · 标题'], ['streaming_description', '宣传卡片 3 · 说明'],
    ['devices_title', '宣传卡片 4 · 标题'], ['devices_description', '宣传卡片 4 · 说明'],
    ['popup_title', '弹窗标题'], ['popup_content', '弹窗正文'],
  ];
  const api = () => '/api/v2/' + String(window.settings.secure_path || '').replace(/^\/+|\/+$/g, '') + '/';
  const token = () => {
    try { return JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || '{}').value; }
    catch { return null; }
  };
  async function request(path, body) {
    const authorization = token();
    if (!authorization) throw new Error('请先登录管理后台');
    const response = await fetch(api() + path, {
      method: body === undefined ? 'GET' : 'POST',
      headers: { Authorization: authorization, 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const result = await response.json();
    if (!response.ok || (result.code && result.code !== 0)) {
      const firstError = result.errors && Object.values(result.errors).flat()[0];
      throw new Error(firstError || result.message || '操作失败');
    }
    return result.data;
  }
  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const panel = el('section', 'dboard-marketing-panel');
  panel.id = 'dboard-marketing-panel';
  panel.hidden = true;
  document.body.append(panel);
  let page = '';
  let openedUrl = '';
  let backgroundScroll = null;
  function lockBackgroundScroll() {
    if (backgroundScroll !== null) return;
    backgroundScroll = [window.scrollX, window.scrollY];
    window.scrollTo(0, 0);
    document.documentElement.classList.add('dboard-marketing-open');
  }
  function unlockBackgroundScroll(restorePosition) {
    document.documentElement.classList.remove('dboard-marketing-open');
    const position = backgroundScroll;
    backgroundScroll = null;
    if (restorePosition && position) window.scrollTo(...position);
  }
  const pageQueryKey = 'dboard_page';
  const pageFromUrl = () => {
    const key = new URL(location.href).searchParams.get(pageQueryKey);
    return items.some(([value]) => value === key) ? key : '';
  };
  function setPageUrl(key) {
    const url = new URL(location.href);
    if (key) url.searchParams.set(pageQueryKey, key);
    else url.searchParams.delete(pageQueryKey);
    if (url.href !== location.href) history.replaceState(history.state, '', url.href);
  }
  let selectedUsers = new Set();
  let observedContent = null;
  let nativeHeading = null;
  let nativeHeadingVisibility = '';
  function syncNativeHeading() {
    if (panel.hidden || nativeHeading?.isConnected) return;
    const root = document.getElementById('root');
    if (!root) return;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let textNode;
    while ((textNode = walker.nextNode())) {
      if (textNode.textContent.trim() !== '仪表盘') continue;
      const element = textNode.parentElement;
      if (!element || element.getBoundingClientRect().top > 110) continue;
      nativeHeading = element;
      nativeHeadingVisibility = element.style.visibility;
      element.style.visibility = 'hidden';
      break;
    }
  }
  const layoutObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(syncPanelPosition) : null;
  function syncPanelPosition() {
    const content = document.getElementById('content');
    if (content !== observedContent) {
      layoutObserver?.disconnect();
      observedContent = content;
      if (content) layoutObserver?.observe(content);
    }
    panel.style.left = content ? `${Math.max(0, Math.round(content.getBoundingClientRect().left))}px` : '0px';
  }
  window.addEventListener('resize', syncPanelPosition);
  function close() {
    const restorePosition = location.href === openedUrl;
    panel.hidden = true;
    page = '';
    panel.replaceChildren();
    unlockBackgroundScroll(restorePosition);
    if (nativeHeading?.isConnected) nativeHeading.style.visibility = nativeHeadingVisibility;
    nativeHeading = null;
    if (pageFromUrl()) setPageUrl('');
  }
  function closeOnNavigation() {
    if (!panel.hidden && location.href !== openedUrl) close();
  }
  document.addEventListener('click', event => {
    if (!panel.hidden && !panel.contains(event.target) && !event.target.closest('.dboard-custom-menu-link')) close();
  }, true);
  window.addEventListener('popstate', () => { closeOnNavigation(); restorePageFromUrl(); });
  window.addEventListener('hashchange', () => { closeOnNavigation(); restorePageFromUrl(); });
  function shell(title, description) {
    openedUrl = location.href;
    panel.replaceChildren();
    const head = el('header', 'dboard-marketing-header');
    const titleWrap = el('div');
    titleWrap.append(el('h1', '', title), el('p', '', description));
    const closeButton = el('button', 'dboard-marketing-close', '关闭');
    closeButton.type = 'button'; closeButton.addEventListener('click', close);
    head.append(titleWrap, closeButton);
    panel.append(head);
    syncPanelPosition();
    panel.hidden = false;
    lockBackgroundScroll();
    syncNativeHeading();
    return panel;
  }
  async function openStore() {
    page = 'store';
    shell('商店宣传', '修改商店标题、四张宣传卡片和商店弹窗文案。');
    const status = el('output', 'dboard-marketing-status', '正在加载…');
    panel.append(status);
    try {
      const data = await request('shop-promotion/fetch');
      if (page !== 'store') return;
      const form = el('form', 'dboard-marketing-form');
      for (const [heading, fields] of [
        ['商店顶部', promoFields.slice(0, 2)],
        ['宣传卡片（从左到右）', promoFields.slice(2, 10)],
        ['商店弹窗', promoFields.slice(10)],
      ]) {
        form.append(el('h2', 'dboard-marketing-section-title', heading));
        const grid = el('div', 'dboard-marketing-grid');
        for (const [key, label] of fields) {
          const field = el('label', 'dboard-marketing-field');
          field.append(el('span', '', label));
          const input = key === 'popup_content' ? el('textarea') : el('input');
          input.name = key; input.value = data[key] ?? '';
          input.maxLength = key === 'popup_content' ? 3000 : 500;
          if (key === 'popup_content') input.rows = 4;
          field.append(input); grid.append(field);
        }
        form.append(grid);
      }
      const options = el('div', 'dboard-marketing-grid');
      const enabled = el('label', 'dboard-marketing-check');
      const enabledInput = el('input'); enabledInput.type = 'checkbox'; enabledInput.name = 'popup_enabled'; enabledInput.checked = !!data.popup_enabled;
      enabled.append(enabledInput, document.createTextNode('启用商店弹窗'));
      options.append(enabled);
      for (const [key, label, max] of [
        ['popup_cooldown_hours', '弹窗冷却时间（小时，0 为每次进入都显示）', 720],
        ['popup_close_wait_seconds', '弹窗关闭等待（秒）', 60],
      ]) {
        const field = el('label', 'dboard-marketing-field');
        field.append(el('span', '', label));
        const input = el('input'); input.type = 'number'; input.name = key;
        input.min = '0'; input.max = String(max); input.value = String(data[key] ?? 0);
        field.append(input); options.append(field);
      }
      form.append(options);
      const save = el('button', 'dboard-marketing-primary', '保存商店文案');
      save.type = 'submit'; form.append(save);
      form.addEventListener('submit', async event => {
        event.preventDefault(); save.disabled = true; status.textContent = '正在保存…';
        const payload = {};
        for (const [key] of promoFields) payload[key] = form.elements[key].value.trim();
        payload.popup_enabled = enabledInput.checked;
        payload.popup_cooldown_hours = Number(form.elements.popup_cooldown_hours.value);
        payload.popup_close_wait_seconds = Number(form.elements.popup_close_wait_seconds.value);
        try { await request('shop-promotion/save', payload); status.textContent = '已保存，用户刷新商店页面后生效。'; }
        catch (error) { status.textContent = error.message; }
        finally { save.disabled = false; }
      });
      panel.append(form); status.textContent = '';
    } catch (error) { status.textContent = error.message; }
  }
  const dateText = seconds => seconds ? new Date(Number(seconds) * 1000).toLocaleString() : '—';
  async function openNotice() {
    page = 'notice'; selectedUsers = new Set();
    shell('通知中心', '发送给已绑定 Telegram、允许 TG 通知且未封禁的用户。定时发送仅支持全局通知。');
    const status = el('output', 'dboard-marketing-status', '正在加载…');
    panel.append(status);
    try {
      const options = await request('notification-center/options');
      if (page !== 'notice') return;
      const form = el('form', 'dboard-marketing-form');
      const audienceField = el('label', 'dboard-marketing-field');
      audienceField.append(el('span', '', '接收对象'));
      const audience = el('select'); audience.name = 'audience';
      for (const [value, label] of [['global', '全局：所有符合条件的用户'], ['plan', '已购买指定套餐的用户'], ['users', '手动选择用户']]) {
        const option = el('option', '', label); option.value = value; audience.append(option);
      }
      audienceField.append(audience); form.append(audienceField);
      const planField = el('label', 'dboard-marketing-field');
      planField.append(el('span', '', '套餐'));
      const planSelect = el('select'); planSelect.name = 'plan_id';
      planSelect.append(el('option', '', '请选择套餐'));
      for (const plan of options.plans || []) {
        const option = el('option', '', `${plan.name}（ID ${plan.id}）`); option.value = String(plan.id); planSelect.append(option);
      }
      planField.append(planSelect); form.append(planField);
      const userArea = el('div', 'dboard-marketing-user-area');
      const userLabel = el('label', 'dboard-marketing-field');
      userLabel.append(el('span', '', '搜索用户邮箱或 ID'));
      const search = el('input'); search.placeholder = '输入邮箱或用户 ID'; userLabel.append(search);
      const searchButton = el('button', '', '搜索'); searchButton.type = 'button';
      const results = el('div', 'dboard-marketing-results');
      const selection = el('p', '', '已选择 0 人');
      userArea.append(userLabel, searchButton, results, selection); form.append(userArea);
      searchButton.addEventListener('click', async () => {
        results.textContent = '正在搜索…';
        try {
          const data = await request('notification-center/options?search=' + encodeURIComponent(search.value.trim()));
          results.replaceChildren();
          for (const user of data.users || []) {
            const row = el('label', 'dboard-marketing-check');
            const input = el('input'); input.type = 'checkbox'; input.value = String(user.id);
            input.checked = selectedUsers.has(user.id); input.disabled = !user.eligible;
            input.addEventListener('change', () => {
              if (input.checked) selectedUsers.add(user.id); else selectedUsers.delete(user.id);
              selection.textContent = `已选择 ${selectedUsers.size} 人`;
            });
            row.append(input, document.createTextNode(`${user.email}（ID ${user.id}）${user.eligible ? '' : ' · 未绑定 TG 或已关闭通知'}`));
            results.append(row);
          }
          if (!results.childElementCount) results.textContent = '没有找到用户';
        } catch (error) { results.textContent = error.message; }
      });
      const messageField = el('label', 'dboard-marketing-field');
      messageField.append(el('span', '', 'TG 通知内容（纯文本，最多 3000 字）'));
      const message = el('textarea'); message.name = 'message'; message.rows = 6; message.maxLength = 3000;
      messageField.append(message); form.append(messageField);
      const scheduleField = el('label', 'dboard-marketing-field');
      scheduleField.append(el('span', '', '定时发送（留空则尽快发送；仅全局通知可设置）'));
      const scheduled = el('input'); scheduled.type = 'datetime-local'; scheduled.name = 'scheduled_at';
      scheduleField.append(scheduled); form.append(scheduleField);
      const preview = el('button', '', '查看可接收人数'); preview.type = 'button';
      const create = el('button', 'dboard-marketing-primary', '创建通知'); create.type = 'submit';
      const buttons = el('div', 'dboard-marketing-actions'); buttons.append(preview, create); form.append(buttons);
      function updateAudience() {
        planField.hidden = audience.value !== 'plan';
        userArea.hidden = audience.value !== 'users';
        scheduleField.hidden = audience.value !== 'global';
        if (audience.value !== 'global') scheduled.value = '';
      }
      audience.addEventListener('change', updateAudience); updateAudience();
      function payload() {
        return {
          audience: audience.value,
          plan_id: audience.value === 'plan' ? Number(planSelect.value) || null : null,
          user_ids: audience.value === 'users' ? [...selectedUsers] : [],
        };
      }
      preview.addEventListener('click', async () => {
        try {
          const data = await request('notification-center/preview', payload());
          status.textContent = `当前符合条件的接收者：${data.eligible_count} 人。`;
        } catch (error) { status.textContent = error.message; }
      });
      form.addEventListener('submit', async event => {
        event.preventDefault();
        const body = { ...payload(), message: message.value.trim() };
        if (scheduled.value) body.scheduled_at = Math.floor(new Date(scheduled.value).getTime() / 1000);
        if (!body.message) { status.textContent = '请填写通知内容'; return; }
        let count;
        try { count = (await request('notification-center/preview', body)).eligible_count; }
        catch (error) { status.textContent = error.message; return; }
        if (!count) { status.textContent = '没有符合条件的接收者，未创建通知'; return; }
        const when = body.scheduled_at ? dateText(body.scheduled_at) : '尽快';
        if (!window.confirm(`确认向 ${count} 位符合条件的用户发送 TG 通知？发送时间：${when}。`)) return;
        create.disabled = true; status.textContent = '正在创建通知…';
        try {
          await request('notification-center/create', body);
          status.textContent = '通知已创建。发送任务每分钟检查一次。';
          message.value = ''; scheduled.value = '';
          await loadHistory();
        } catch (error) { status.textContent = error.message; }
        finally { create.disabled = false; }
      });
      const history = el('section', 'dboard-marketing-history');
      history.append(el('h2', '', '最近通知'));
      panel.append(form, history); status.textContent = options.telegram_ready ? '' : 'Telegram 机器人未启用或令牌未配置，暂时无法创建通知。';
      async function loadHistory() {
        const list = await request('notification-center/fetch');
        history.replaceChildren(el('h2', '', '最近通知'));
        for (const item of list || []) {
          const row = el('div', 'dboard-marketing-history-row');
          const scope = item.audience === 'global' ? '全局' : item.audience === 'plan' ? `套餐 ID ${item.plan_id}` : '手选用户';
          row.append(el('strong', '', `#${item.id} · ${scope} · ${item.status}`),
            el('span', '', `计划：${dateText(item.scheduled_at)} · 已入队：${item.queued_count}`),
            el('p', '', item.message),
            ...(item.last_error ? [el('small', '', item.last_error)] : []));
          if (item.status === 'pending') {
            const cancel = el('button', '', '取消'); cancel.type = 'button';
            cancel.addEventListener('click', async () => {
              if (!window.confirm(`取消通知 #${item.id}？`)) return;
              try { await request('notification-center/cancel', { id: item.id }); await loadHistory(); }
              catch (error) { status.textContent = error.message; }
            });
            row.append(cancel);
          }
          history.append(row);
        }
      }
      await loadHistory();
    } catch (error) { status.textContent = error.message; }
  }
  function openBinding() {
    if (!window.DBoardTelegramBinding) return;
    page = 'binding';
    window.DBoardTelegramBinding.open({ panel, shell, request, el, isCurrent: () => page === 'binding' });
  }
  function openPage(key) {
    if (key === 'store') openStore();
    else if (key === 'notice') openNotice();
    else if (key === 'binding') openBinding();
    else if (key === 'user-notice') {
      page = key;
      window.DBoardUserNotice.open({ panel, shell, request, el, isCurrent: () => page === key });
    }
  }
  function restorePageFromUrl() {
    const key = pageFromUrl();
    if (!key || !panel.hidden || !document.getElementById(`dboard-menu-${key}`)) return;
    if (key === 'binding' && !window.DBoardTelegramBinding) return;
    openPage(key);
  }
  function mountMenu() {
    const anchors = [...document.querySelectorAll('#root a, #root button')];
    const knowledge = anchors.find(node => ['知识库管理', 'Knowledge Management'].includes(node.textContent.trim()));
    if (!knowledge) return;
    const base = knowledge.closest('li') || knowledge.parentElement;
    if (!base || document.getElementById('dboard-menu-store')) return;
    let cursor = base;
    for (const [key, label] of items) {
      const clone = base.cloneNode(true);
      clone.id = `dboard-menu-${key}`;
      clone.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'));
      const control = clone.matches('a,button') ? clone : clone.querySelector('a,button');
      if (!control) continue;
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 24 24');
      svg.setAttribute('fill', 'none');
      svg.setAttribute('stroke', 'currentColor');
      svg.setAttribute('stroke-width', '1.8');
      svg.setAttribute('stroke-linecap', 'round');
      svg.setAttribute('stroke-linejoin', 'round');
      svg.setAttribute('aria-hidden', 'true');
      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', key === 'store'
        ? 'M3 3h2l2.4 11h10.8l2-8H6 M8 20h.01 M18 20h.01'
        : key === 'user-notice' ? 'M5 3h14v18H5z M8 7h8 M8 11h8 M8 15h5'
        : key === 'notice' ? 'M18 8a6 6 0 0 0-12 0c0 7-3 8-3 9h18c0-1-3-2-3-9 M10 21h4'
        : 'M8 12a4 4 0 0 1 4-4h7v8h-7a4 4 0 0 1-4-4z M8 12H5a2 2 0 0 0 0 4h2 M14 8V5a2 2 0 0 0-4 0v3 M15 12h.01');
      svg.append(path);
      control.classList.add('dboard-custom-menu-link');
      control.replaceChildren(svg, el('span', '', label));
      if (control.tagName === 'A') control.setAttribute('href', '#');
      else { control.type = 'button'; }
      const activate = event => {
        event.preventDefault(); event.stopPropagation();
        // These custom links bypass the router, so close the native mobile
        // navigation explicitly before showing the page underneath it.
        const navigationToggle = document.querySelector('button[aria-controls="sidebar-menu"][aria-expanded="true"]');
        if (navigationToggle?.getClientRects().length) navigationToggle.click();
        setPageUrl(key);
        openPage(key);
      };
      control.addEventListener('click', activate);
      cursor.after(clone); cursor = clone;
    }
  }
  let pending = false;
  const observer = new MutationObserver(() => {
    closeOnNavigation();
    if (pending) return;
    pending = true;
    requestAnimationFrame(() => { pending = false; closeOnNavigation(); mountMenu(); restorePageFromUrl(); syncNativeHeading(); });
  });
  const root = document.getElementById('root');
  if (root) observer.observe(root, { childList: true, subtree: true });
  mountMenu();
  restorePageFromUrl();
  window.addEventListener('load', restorePageFromUrl, { once: true });
})();
