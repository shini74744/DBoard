// Blank prices are unavailable; an explicit zero remains a purchasable price.
export const isAvailablePlanPrice = (value) =>
  (typeof value === 'number' || (typeof value === 'string' && value.trim() !== '')) &&
  Number.isFinite(Number(value)) && Number(value) >= 0;
