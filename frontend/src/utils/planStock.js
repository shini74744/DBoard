// Display mode never changes admission or renewal rules.
export function getPlanStock(plan, threshold = 5) {
  const remaining = plan.capacity_display_mode === 'remaining';
  const value = remaining ? plan.capacity_remaining : plan.capacity_limit;
  const count = value == null ? null : Math.max(0, Number(value) || 0);
  const state = count === null || count >= threshold ? 'plenty' : count > 0 ? 'warning' : 'sold_out';
  return {
    count,
    className: state === 'sold_out' ? 'stock-danger' : `stock-${state}`,
    textKey: `shop.plan.stock.${remaining ? (count === null ? 'unlimited' : 'remaining') : state}`
  };
}
