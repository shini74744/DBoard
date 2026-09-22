(() => {
  const fields = [
    ['telegram_user_notify_node_name', '节点名称变动通知'],
    ['telegram_user_notify_node_rate', '节点倍率变动通知'],
    ['telegram_user_notify_node_new', '新增节点通知'],
    ['telegram_user_notify_node_offline', '节点失联通知'],
    ['telegram_user_notify_manual_reset', '管理员手动重置流量通知'],
    ['telegram_user_notify_traffic_low', '流量不足提醒（TG）'],
    ['telegram_user_notify_device_over_limit', '在线设备超限提醒'],
    ['telegram_user_notify_subscription_sharing', '订阅链接疑似共享提醒'],
  ];
  const route = () => `${location.pathname}${location.hash}`.includes('/config/system/telegram');
  const api = () => '/api/v2/' + String(window.settings.secure_path || '').replace(/^\/+|\/+$/g, '') + '/config';
  const auth = () => {
    try { return JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || '{}').value; }
    catch { return null; }
  };
  async function request(path, options = {}) {
    const token = auth();
    if (!token) throw new Error('请先登录管理后台');
    const response = await fetch(api() + path, {
      ...options,
      headers: { Authorization: token, 'Content-Type': 'application/json' },
    });
    const body = await response.json();
    if (!response.ok || body.code && body.code !== 0) throw new Error(body.message || '保存失败');
    return body.data;
  }
  const card = document.createElement('section');
  card.id = 'dboard-telegram-user-settings';
  card.className = 'dboard-admin-card';
  card.innerHTML = `<h4>用户 Telegram 通知</h4>
    <p>开启后向已绑定 Telegram 且在用户中心允许通知的用户发送消息。</p>
    <div class="dboard-admin-fields"></div>
    <p>流量按已用比例提醒；在线设备按节点上报的不同 IP 统计；订阅共享按 24 小时内获取订阅的不同公网 IP 判断。每类提醒每人最多每 24 小时一次。</p>
    <label class="dboard-admin-number">流量提醒线（已用 %） <input name="telegram_traffic_warn_percent" type="number" min="50" max="99"></label>
    <label class="dboard-admin-number">订阅共享 IP 上限（24 小时） <input name="telegram_subscribe_ip_limit" type="number" min="2" max="50"></label>
    <label class="dboard-admin-number">失联最多提醒次数 <input name="telegram_node_offline_reminders" type="number" min="1" max="10"></label>
    <label class="dboard-admin-number">失联提醒间隔（分钟） <input name="telegram_node_offline_interval" type="number" min="5" max="1440"></label>
    <output role="status"></output>`;
  const list = card.querySelector('.dboard-admin-fields');
  for (const [key, label] of fields) {
    const row = document.createElement('label');
    row.className = 'dboard-admin-toggle';
    const input = document.createElement('input');
    input.type = 'checkbox'; input.name = key;
    row.append(input, document.createTextNode(label));
    list.append(row);
  }
  let loading = false;
  async function load() {
    if (loading) return;
    loading = true;
    const status = card.querySelector('output');
    status.textContent = '正在读取通知设置…';
    try {
      const data = await request('/userTelegram');
      for (const [key] of fields) card.querySelector(`[name="${key}"]`).checked = Boolean(data[key]);
      for (const key of ['telegram_traffic_warn_percent', 'telegram_subscribe_ip_limit', 'telegram_node_offline_reminders', 'telegram_node_offline_interval'])
        card.querySelector(`[name="${key}"]`).value = data[key];
      status.textContent = '';
    } catch (error) { status.textContent = error.message; }
    finally { loading = false; }
  }
  card.addEventListener('change', async event => {
    const input = event.target;
    if (!input.name || loading) return;
    const status = card.querySelector('output');
    const value = input.type === 'checkbox' ? input.checked : Number(input.value);
    input.disabled = true;
    status.textContent = '保存中…';
    try {
      await request('/userTelegram', { method: 'POST', body: JSON.stringify({ [input.name]: value }) });
      status.textContent = '已保存';
    } catch (error) {
      status.textContent = error.message;
      await load();
    } finally { input.disabled = false; }
  });
  function mount() {
    if (!route()) { card.remove(); return; }
    const adminHeading = [...document.querySelectorAll('h4')]
      .find(heading => heading.textContent.trim() === '管理员通知');
    const adminCard = adminHeading?.closest('.rounded-lg.border.p-4');
    if (!adminCard) return;
    if (card.previousElementSibling === adminCard) return;
    adminCard.after(card);
    load();
  }
  let pending = false;
  const observer = new MutationObserver(() => {
    if (pending) return;
    pending = true;
    requestAnimationFrame(() => { pending = false; mount(); });
  });
  observer.observe(document.getElementById('root'), { childList: true, subtree: true });
  window.addEventListener('popstate', mount);
  window.addEventListener('hashchange', mount);
  mount();
})();
