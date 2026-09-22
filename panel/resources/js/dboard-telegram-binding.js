(() => {
  const GB = 1073741824;
  const formatGB = bytes => (Number(bytes || 0) / GB).toFixed(2) + ' GB';
  const telegramLabel = user => (user.telegram_username ? '@' + user.telegram_username
    : user.telegram_username_synced_at ? '无公开用户名' : '正在同步…') + ' · ID ' + user.telegram_id;
  const date = value => new Date(Number(value) * 1000).toLocaleString('zh-CN', { hour12: false });
  const newRequestId = () => {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
  };
  const period = user => !user.plan ? '未开通' : user.expired_at === null
    ? '永久' : user.remaining_days > 0
      ? user.remaining_days + ' 天 · ' + date(user.expired_at)
      : '已到期 · ' + date(user.expired_at);

  window.DBoardTelegramBinding = {
    async open({ panel, shell, request, el, isCurrent }) {
      shell('机器人绑定', '查看已绑定用户，设置绑定赠送，并给指定用户补发流量。');
      const status = el('output', 'dboard-marketing-status');
      panel.append(status);

      const settingsForm = el('form', 'dboard-marketing-form');
      settingsForm.append(el('h2', 'dboard-marketing-section-title', '绑定时自动赠送'));
      const settingsHint = el('p', 'dboard-binding-hint',
        '首次保存配置后，以保存时间区分新老用户；之后发生的绑定才会自动赠送，每个账号最多一次。填 0 可关闭自动赠送。赠送有效期从绑定时开始，按所选天数到期，独立于套餐流量重置周期。');
      settingsForm.append(settingsHint);
      const settingsGrid = el('div', 'dboard-marketing-grid dboard-binding-settings-grid');
      const newUserGroup = el('section', 'dboard-binding-reward-group');
      newUserGroup.append(el('h3', '', '新注册用户'));
      const existingUserGroup = el('section', 'dboard-binding-reward-group');
      existingUserGroup.append(el('h3', '', '已有用户'));
      settingsGrid.append(newUserGroup, existingUserGroup);
      const settingsInputs = {};
      function inputField(parent, label, name, type = 'text') {
        const field = el('label', 'dboard-marketing-field');
        const control = el('input');
        control.name = name; control.type = type;
        if (type === 'number') {
          const days = name.endsWith('_days');
          control.min = days ? '1' : name === 'amount_gb' ? '0.01' : '0';
          control.max = days ? '3650' : '10000';
          control.step = days ? '1' : '0.01';
          control.required = true;
        }
        field.append(el('span', '', label), control);
        parent.append(field);
        return control;
      }
      function durationField(parent, label, name) {
        const field = el('label', 'dboard-marketing-field');
        const select = el('select');
        const presets = ['1', '3', '7', '15', '30', '60', '90', '180', '365'];
        for (const value of presets) {
          const option = el('option', '', value + ' 天');
          option.value = value; select.append(option);
        }
        const other = el('option', '', '自定义天数');
        other.value = 'custom'; select.append(other);
        const custom = el('input');
        custom.type = 'number'; custom.min = '1'; custom.max = '3650'; custom.step = '1';
        custom.placeholder = '输入 1–3650 天';
        const sync = () => { custom.hidden = select.value !== 'custom'; custom.required = !custom.hidden; };
        select.addEventListener('change', sync);
        field.append(el('span', '', label), select, custom);
        parent.append(field);
        const control = {
          get value() { return select.value === 'custom' ? custom.value : select.value; },
          set value(value) {
            const days = String(value || 30);
            select.value = presets.includes(days) ? days : 'custom';
            custom.value = days; sync();
          },
        };
        control.value = 30;
        return control;
      }
      settingsInputs.new_gb = inputField(newUserGroup, '绑定赠送流量（GB）', 'new_gb', 'number');
      settingsInputs.new_days = durationField(newUserGroup, '赠送多久（从绑定时起）', 'new_days');
      settingsInputs.existing_gb = inputField(existingUserGroup, '绑定赠送流量（GB）', 'existing_gb', 'number');
      settingsInputs.existing_days = durationField(existingUserGroup, '赠送多久（从绑定时起）', 'existing_days');
      settingsForm.append(settingsGrid);
      const settingsSave = el('button', 'dboard-marketing-primary', '保存赠送规则');
      settingsSave.type = 'submit'; settingsForm.append(settingsSave);
      panel.append(settingsForm);

      const grantForm = el('form', 'dboard-marketing-form dboard-binding-grant');
      grantForm.hidden = true;
      const grantTitle = el('h2', 'dboard-marketing-section-title', '手动补发流量');
      const grantHint = el('p', 'dboard-binding-hint');
      const grantGrid = el('div', 'dboard-marketing-grid');
      const amountInput = inputField(grantGrid, '补发流量（GB）', 'amount_gb', 'number');
      const durationInput = durationField(grantGrid, '赠送有效期', 'duration_days');
      const reasonInput = inputField(grantGrid, '加送理由或备注', 'reason');
      reasonInput.maxLength = 255;
      reasonInput.placeholder = '如：活动奖励、客服补偿';
      amountInput.required = true;
      const grantButton = el('button', 'dboard-marketing-primary', '确认补发并通知 TG');
      grantButton.type = 'submit';
      grantForm.append(grantTitle, grantHint, grantGrid, grantButton);
      panel.append(grantForm);

      const listCard = el('section', 'dboard-marketing-form');
      listCard.append(el('h2', 'dboard-marketing-section-title', '用户列表'));
      const toolbar = el('div', 'dboard-binding-toolbar');
      const search = el('input');
      search.placeholder = '搜索 TG 用户名、网站邮箱或用户 ID';
      search.setAttribute('aria-label', '搜索用户');
      const bindingFilter = el('select');
      bindingFilter.setAttribute('aria-label', '绑定状态');
      for (const [value, label] of [['telegram', '已绑定 TG'], ['email_only', '仅邮箱账号'], ['all', '全部用户']]) {
        const option = el('option', '', label); option.value = value; bindingFilter.append(option);
      }
      const planFilter = el('select');
      planFilter.setAttribute('aria-label', '套餐筛选');
      for (const [value, label] of [['all', '全部套餐'], ['none', '未开通套餐']]) {
        const option = el('option', '', label); option.value = value; planFilter.append(option);
      }
      const searchButton = el('button', '', '搜索');
      searchButton.type = 'button';
      const count = el('span', 'dboard-binding-count');
      toolbar.append(bindingFilter, planFilter, search, searchButton, count);
      listCard.append(toolbar);
      const scroll = el('div', 'dboard-binding-table-scroll');
      const table = el('table', 'dboard-binding-table');
      const head = el('thead');
      const headerRow = el('tr');
      for (const label of ['TG 用户名', '网站', '邮箱', '套餐', '剩余周期', '剩余流量', '赠送额度', '操作']) {
        headerRow.append(el('th', '', label));
      }
      head.append(headerRow);
      const body = el('tbody');
      table.append(head, body);
      scroll.append(table);
      listCard.append(scroll);
      const pager = el('div', 'dboard-binding-pager');
      const previous = el('button', '', '上一页');
      const pageLabel = el('span');
      const next = el('button', '', '下一页');
      for (const button of [previous, next]) button.type = 'button';
      pager.append(previous, pageLabel, next);
      listCard.append(pager);
      panel.append(listCard);

      const historyCard = el('section', 'dboard-marketing-history');
      historyCard.append(el('h2', '', '最近赠送记录'));
      const historyList = el('div');
      historyCard.append(historyList);
      panel.append(historyCard);

      let currentPage = 1;
      let currentSearch = '';
      let selectedUser = null;
      let plansLoaded = false;
      let requestId = null;
      let initialized = false;
      let telegramReady = false;

      settingsForm.addEventListener('submit', async event => {
        event.preventDefault();
        settingsSave.disabled = true; status.textContent = '正在保存赠送规则…';
        try {
          const payload = Object.fromEntries(Object.entries(settingsInputs)
            .map(([key, control]) => [key, Number(control.value)]));
          const saved = await request('telegram-binding/settings', payload);
          settingsHint.textContent = '已保存。' + date(saved.start_at)
            + ' 起注册的账号按新用户规则赠送；此前注册的账号按已有用户规则赠送。仅之后发生的绑定自动获赠，每个账号最多一次；有效期从绑定时开始。';
          status.textContent = '赠送规则已保存。';
        } catch (error) { status.textContent = error.message; }
        finally { settingsSave.disabled = false; }
      });

      grantForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (!selectedUser || !telegramReady) return;
        const gb = Number(amountInput.value);
        const days = Number(durationInput.value);
        if (!Number.isFinite(gb) || gb < 0.01 || gb > 10000
          || !Number.isInteger(days) || days < 1 || days > 3650) return;
        if (!window.confirm('确认给 ' + selectedUser.email + ' 补发 ' + gb
          + ' GB 流量，有效期 ' + days + ' 天（从现在开始）？')) return;
        requestId ||= newRequestId();
        grantButton.disabled = true; status.textContent = '正在补发…';
        try {
          const result = await request('telegram-binding/grant', {
            user_id: selectedUser.id, amount_gb: gb, duration_days: days,
            reason: reasonInput.value.trim(), request_id: requestId,
          });
          requestId = null;
          status.textContent = result.awarded
            ? '补发成功，将于 ' + date(result.expires_at) + ' 到期。' + (result.pending_plan ? '套餐尚未生效；有效期已开始计算，开通后可使用。' : '') + (result.notification_queued ? 'TG 通知已排队发送。' : 'TG 通知未能排队，请检查机器人。')
            : '该请求已处理，未重复增加流量。';
          await Promise.all([loadUsers(), loadHistory()]);
        } catch (error) { status.textContent = error.message; }
        finally { grantButton.disabled = false; }
      });

      async function refreshUsername(user, cell) {
        try {
          const result = await request('telegram-binding/refresh-username', { user_id: user.id });
          if (!isCurrent()) return;
          user.telegram_username = result.telegram_username;
          user.telegram_username_synced_at = Math.floor(Date.now() / 1000);
          cell.textContent = telegramLabel(user);
        } catch {
          if (isCurrent()) cell.textContent = '未同步 · ID ' + user.telegram_id;
        }
      }

      async function loadUsers() {
        const query = 'telegram-binding/fetch?page=' + currentPage
          + '&search=' + encodeURIComponent(currentSearch)
          + '&binding=' + encodeURIComponent(bindingFilter.value)
          + '&plan_id=' + encodeURIComponent(planFilter.value);
        const data = await request(query);
        if (!isCurrent()) return;
        telegramReady = data.telegram_ready;
        if (!plansLoaded) {
          for (const plan of data.plans || []) {
            const option = el('option', '', plan.name); option.value = String(plan.id);
            planFilter.append(option);
          }
          plansLoaded = true;
        }
        if (!initialized) {
          for (const [key, control] of Object.entries(settingsInputs)) {
            control.value = data.settings[key] ?? (key.endsWith('_gb') ? 0 : 30);
          }
          if (data.settings.start_at) {
            settingsHint.textContent = date(data.settings.start_at)
              + ' 起注册的账号按新用户规则赠送；此前注册的账号按已有用户规则赠送。仅之后发生的绑定自动获赠，每个账号最多一次；有效期从绑定时开始。';
          }
          initialized = true;
        }
        count.textContent = '共 ' + data.total + ' 位';
        pageLabel.textContent = data.page + ' / ' + data.last_page;
        previous.disabled = data.page <= 1;
        next.disabled = data.page >= data.last_page;
        body.replaceChildren();
        const pendingSync = [];
        for (const user of data.items) {
          const row = el('tr');
          const username = el('td', '', user.telegram_id ? telegramLabel(user) : '未绑定 TG');
          row.append(username);
          row.append(el('td', '', data.site || '未配置'));
          row.append(el('td', '', user.email));
          row.append(el('td', '', user.plan || '未开通'));
          row.append(el('td', '', period(user)));
          row.append(el('td', '', formatGB(user.remaining_bytes)));
          const bonusSummary = ['限时 ' + formatGB(user.timed_bonus_bytes)];
          if (Number(user.cycle_bonus_bytes) > 0) bonusSummary.push('本周期 ' + formatGB(user.cycle_bonus_bytes));
          if (Number(user.permanent_bonus_bytes) > 0) bonusSummary.push('持续加送 ' + formatGB(user.permanent_bonus_bytes));
          row.append(el('td', '', bonusSummary.join(' / ')));
          const actions = el('td', 'dboard-binding-actions');
          const select = el('button', '', '补发');
          select.type = 'button'; select.disabled = !telegramReady || !user.telegram_id;
          select.addEventListener('click', () => {
            selectedUser = user; requestId = null; reasonInput.value = '';
            grantHint.textContent = '用户：' + user.email + '（TG ID ' + user.telegram_id + '）';
            grantForm.hidden = false;
            grantForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
          });
          const sync = el('button', '', '同步用户名');
          sync.type = 'button'; sync.disabled = !telegramReady || !user.telegram_id;
          sync.addEventListener('click', () => refreshUsername(user, username));
          actions.append(select, sync);
          row.append(actions);
          body.append(row);
          if (user.telegram_id && !user.telegram_username_synced_at && telegramReady) pendingSync.push([user, username]);
        }
        if (!data.items.length) {
          const empty = el('td', '', '当前筛选下暂无用户');
          empty.colSpan = 8;
          const row = el('tr'); row.append(empty); body.append(row);
        }
        if (!telegramReady) status.textContent = 'Telegram 机器人未启用或令牌未配置，暂不能补发和同步用户名。';
        let cursor = 0;
        const workers = Array.from({ length: Math.min(3, pendingSync.length) }, async () => {
          while (cursor < pendingSync.length && isCurrent()) {
            const [user, cell] = pendingSync[cursor++];
            await refreshUsername(user, cell);
          }
        });
        void Promise.all(workers);
      }

      async function loadHistory() {
        const grants = await request('telegram-binding/history');
        if (!isCurrent()) return;
        historyList.replaceChildren();
        if (!grants.length) historyList.append(el('p', 'dboard-binding-hint', '暂无赠送记录'));
        for (const grant of grants) {
          const row = el('div', 'dboard-marketing-history-row');
          row.append(el('strong', '', grant.email || '用户 #' + grant.user_id));
          const validity = grant.mode === 'timed'
            ? grant.duration_days + ' 天 · ' + (grant.revoked_at ? '已结束' : '至 ' + date(grant.expires_at))
            : grant.mode === 'permanent' ? '持续加送' : '旧规则：当前周期';
          row.append(el('span', '', formatGB(grant.amount_bytes) + ' · ' + validity + ' · '
            + (grant.source === 'bind' ? '绑定自动赠送' : '管理员补发') + ' · ' + date(grant.created_at)));
          row.append(el('small', '', grant.reason || '未填写备注'));
          historyList.append(row);
        }
      }

      for (const filter of [bindingFilter, planFilter]) filter.addEventListener('change', () => {
        currentPage = 1; selectedUser = null; grantForm.hidden = true;
        loadUsers().catch(error => { status.textContent = error.message; });
      });
      searchButton.addEventListener('click', () => {
        currentSearch = search.value.trim(); currentPage = 1;
        selectedUser = null; grantForm.hidden = true;
        loadUsers().catch(error => { status.textContent = error.message; });
      });
      search.addEventListener('keydown', event => {
        if (event.key === 'Enter') { event.preventDefault(); searchButton.click(); }
      });
      previous.addEventListener('click', () => {
        if (currentPage > 1) currentPage--;
        loadUsers().catch(error => { status.textContent = error.message; });
      });
      next.addEventListener('click', () => {
        currentPage++;
        loadUsers().catch(error => { status.textContent = error.message; });
      });
      try {
        await Promise.all([loadUsers(), loadHistory()]);
      } catch (error) {
        if (isCurrent()) status.textContent = error.message;
      }
    },
  };
})();
