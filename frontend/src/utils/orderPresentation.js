export function orderActionLabel(order) {
  if (order.is_admin_created) return '管理员手动';
  if (order.period === 'deposit') return '余额充值';
  if (['reset_price', 'reset_traffic'].includes(order.period)) return '重置流量';
  if (order.subscription_action === 'add' || Number(order.type) === 5) return '新开一份';
  if (order.subscription_action === 'renew' || Number(order.type) === 2) return '续费同款';
  return ({1: '新购套餐', 3: '变更套餐', 4: '重置流量'})[Number(order.type)] || '订单';
}
export function orderPriceSource(order) {
  if (order.record_kind === 'activity' || order.is_admin_created) return '';
  return ({shop: '商店标价', package: '原套餐续费价'})[order.purchase_source] || '';
}
export function orderAmount(order) {
  return Number(order.total_amount || 0) + Number(order.balance_amount || 0);
}
export function orderOriginalPrice(order) {
  if (order.quoted_price != null) return Number(order.quoted_price);
  // Legacy orders have no quote snapshot. Reconstruct from their recorded amounts,
  // never from today's catalog price (which may have changed since purchase).
  return Math.max(0, orderAmount(order) + Number(order.discount_amount || 0)
    + Number(order.surplus_amount || 0) - Number(order.surplus_credit || 0));
}

export function orderRenewalTarget(order) {
  if (order.subscription_action !== 'renew' || !order.purchase_source) return '';
  if (order.subscription_expired_at_before == null) return '续费目标：原长期有效套餐';
  return '续费前到期：' + new Date(Number(order.subscription_expired_at_before) * 1000).toLocaleString('zh-CN', {hour12: false});
}
