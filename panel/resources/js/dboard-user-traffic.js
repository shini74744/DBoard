(() => {
  const GB = 1073741824;
  const format = bytes => (Number(bytes || 0) / GB).toFixed(2) + ' GB';
  const token = () => {
    try { return JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || '{}').value; }
    catch { return null; }
  };
  const api = () => '/api/v2/' + String(window.settings.secure_path || '').replace(/^\/+|\/+$/g, '') + '/';
  const overlay = document.createElement('div');
  overlay.className = 'dboard-traffic-overlay';
  overlay.hidden = true;
  overlay.innerHTML = '<section class="dboard-traffic-dialog" role="dialog" aria-modal="true" aria-label="流量明细"></section>';
  document.body.append(overlay);
  const dialog = overlay.firstElementChild;
  const close = () => { overlay.hidden = true; dialog.replaceChildren(); };
  overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !overlay.hidden) close(); });
  const node = (tag, content) => {
    const element = document.createElement(tag);
    element.textContent = content;
    return element;
  };
  function row(label, value) {
    const item = node('div', ''); item.className = 'dboard-traffic-row';
    item.append(node('span', label), node('strong', value));
    return item;
  }
  function bonusGroup(label, amount, entries, bucket) {
    const group = node('div', '');
    group.append(row(label, '+' + format(amount)));
    for (const entry of entries.filter(item => item.bucket === bucket)) {
      const detail = node('div', ''); detail.className = 'dboard-traffic-entry';
      const reason = entry.reason + (entry.expires_at
        ? ' · 到期：' + new Date(Number(entry.expires_at) * 1000).toLocaleString('zh-CN', { hour12: false }) : '');
      detail.append(node('span', reason), node('strong', '+' + format(entry.amount_bytes)));
      group.append(detail);
    }
    return group;
  }
  function locateCell(target) {
    const cell = target.closest?.('#root table tbody td');
    if (!cell) return null;
    const table = cell.closest('table');
    const headers = [...table.querySelectorAll('thead th')];
    const trafficIndex = headers.findIndex(header => header.textContent.trim() === '总流量');
    if (trafficIndex < 0 || cell.cellIndex !== trafficIndex) return null;
    const id = Number(cell.parentElement.cells[1]?.textContent.trim());
    if(!Number.isSafeInteger(id)||id<=0)return null;
    const selected=target.closest?.('[data-dboard-package-id]');
    return {id,packageId:Number(selected?.dataset.dboardPackageId)||null,name:selected?.dataset.dboardPackageName||'',
      multiple:cell.querySelectorAll('[data-dboard-package-id]').length>1};
  }
  async function open(selection) {
    const {id,packageId,name,multiple}=selection;
    if(multiple&&!packageId){window.DBoardMultiSubscriptionAdmin?.showPackages?.(id);return}
    overlay.hidden = false;
    dialog.replaceChildren();
    const header = node('header', '');
    header.append(node('h2', packageId ? '流量明细 · '+name+' #'+packageId : '流量明细 · 用户 #'+id));
    const closeButton = node('button', '关闭');
    closeButton.type = 'button'; closeButton.addEventListener('click', close);
    header.append(closeButton); dialog.append(header);
    const content = node('div', '正在加载…');
    dialog.append(content);
    try {
      const response = await fetch(api() + 'user/traffic-breakdown?id=' + id + (packageId ? '&subscription_user_id='+packageId : ''), {
        headers: { Authorization: token() || '' },
      });
      const payload = await response.json();
      if (!response.ok || (payload.code && payload.code !== 0)) throw new Error(payload.message || '读取失败');
      if (overlay.hidden) return;
      const data = payload.data;
      const entries = data.entries || [];
      content.replaceChildren(
        row('套餐基础流量', format(data.base_bytes)),
        bonusGroup('限时加送', data.timed_bonus_bytes, entries, 'timed'),
        ...(Number(data.cycle_bonus_bytes) > 0 ? [bonusGroup('本周期加送', data.cycle_bonus_bytes, entries, 'cycle')] : []),
        ...(Number(data.permanent_bonus_bytes) > 0 ? [bonusGroup('持续加送', data.permanent_bonus_bytes, entries, 'permanent')] : []),
        bonusGroup('礼品卡加送', data.gift_card_bonus_bytes, entries, 'gift_card'),
        row('当前总额度', format(data.total_bytes)),
        row('已用流量', format(data.used_bytes)),
        row('剩余流量', format(data.remaining_bytes)),
      );
      if (data.status !== 'active') content.append(node('p', data.status === 'no_plan'
        ? '尚无套餐；已记录的赠送在开通套餐后生效。'
        : '套餐已过期；到期前获得的赠送额度不会在断档后恢复。'));
    } catch (error) { content.textContent = error.message; }
  }
  document.addEventListener('click', event => {
    const id = locateCell(event.target);
    if (!id) return;
    event.preventDefault(); event.stopPropagation();
    open(id);
  }, true);
  document.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const id = locateCell(event.target);
    if (!id) return;
    event.preventDefault(); open(id);
  }, true);
  let queued = false;
  function markCells() {
    queued = false;
    for (const table of document.querySelectorAll('#root table')) {
      const headers = [...table.querySelectorAll('thead th')];
      const index = headers.findIndex(header => header.textContent.trim() === '总流量');
      if (index < 0) continue;
      for (const row of table.querySelectorAll('tbody tr')) {
        const cell = row.cells[index];
        if (!cell || cell.classList.contains('dboard-traffic-cell')) continue;
        cell.classList.add('dboard-traffic-cell');
        if(!cell.querySelector('[data-dboard-package-id]')){cell.tabIndex = 0;cell.setAttribute('role', 'button');}
        cell.setAttribute('title', '点击查看流量明细');
      }
    }
  }
  const root = document.getElementById('root');
  if (root) new MutationObserver(() => {
    if (!queued) { queued = true; requestAnimationFrame(markCells); }
  }).observe(root, { childList: true, subtree: true });
  markCells();
})();
