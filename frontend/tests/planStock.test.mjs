import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
const source = await readFile(new URL('../src/utils/planStock.js', import.meta.url), 'utf8');
const { getPlanStock } = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
for (const mode of ['status', 'remaining']) {
  const plan = { capacity_limit: 10, capacity_display_mode: mode, capacity_remaining: 0 };
  assert.equal(getPlanStock(plan).count, 0);
  assert.equal(getPlanStock(plan).className, 'stock-danger');
  plan.capacity_remaining = 1;
  assert.equal(getPlanStock(plan).className, 'stock-warning');
  plan.capacity_remaining = 7;
  assert.equal(getPlanStock(plan).className, 'stock-plenty');
  plan.capacity_remaining = null;
  assert.equal(getPlanStock(plan).count, null);
}
assert.equal(getPlanStock({ capacity_limit: 5 }).count, 5);
assert.equal(getPlanStock({ capacity_limit: 'Sold out' }).className, 'stock-danger');
console.log('Stock badges: both modes follow remaining stock; legacy and unlimited cases passed.');
