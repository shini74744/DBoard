<template>
  <section class="purchase-choice" aria-labelledby="purchase-choice-title">
    <div class="choice-heading">
      <h3 id="purchase-choice-title">购买方式</h3>
      <span v-if="loading">正在读取已持有套餐…</span>
      <button v-else type="button" class="owned-toggle" :aria-expanded="showOwned" aria-controls="owned-package-list" @click="showOwned = !showOwned">
        <span>已持有 {{ ownedCount }} 份套餐</span><IconChevronDown :size="16" :class="{ expanded: showOwned }" aria-hidden="true" />
      </button>
    </div>
    <div v-if="showOwned && !loading" id="owned-package-list" class="owned-packages" aria-label="已持有的套餐">
      <div v-if="!ownedPackages.length" class="owned-empty">您还没有持有套餐，可在下方选择新开一份。</div>
      <ul v-else class="owned-grid">
        <li v-for="item in ownedPackages" :key="item.id" class="owned-card">
          <div class="owned-card-heading"><strong>{{ ownedName(item) }}</strong><span v-if="isExpired(item)" class="owned-expired">已到期</span></div>
          <p class="owned-expiry">{{ item.expired_at == null ? '长期有效' : '到期：' + expiryText(item.expired_at) }}</p>
          <div class="owned-traffic"><span>剩余流量 <b>{{ formatTraffic(remainingTraffic(item)) }}</b></span><span>总流量 {{ formatTraffic(item.transfer_enable) }}</span></div>
        </li>
      </ul>
    </div>
    <div class="choice-options" role="radiogroup" aria-label="购买方式">
      <label class="choice-option" :class="{ selected: action === 'add' }">
        <input type="radio" name="purchase-action" value="add" :checked="action === 'add'" :disabled="loading || busy" @change="$emit('action', 'add')">
        <span class="choice-copy"><strong>新开一份</strong><small>独立开通，可与已有套餐同时使用</small></span>
        <IconPlus :size="22" class="choice-symbol" aria-hidden="true" />
      </label>
      <label class="choice-option" :class="{ selected: action === 'renew', unavailable: !matches.length }">
        <input type="radio" name="purchase-action" value="renew" :checked="action === 'renew'" :disabled="loading || busy || !matches.length" @change="$emit('action', 'renew')">
        <span class="choice-copy"><strong>续费同款</strong><small>{{ matches.length ? '为已持有的同款套餐续费' : '您暂未持有这款套餐' }}</small></span>
        <IconClock :size="22" class="choice-symbol" aria-hidden="true" />
      </label>
    </div>
    <div v-if="action === 'renew' && !loading" class="renewal-selection">
      <label for="renewal-package">选择要续费的套餐</label>
      <select id="renewal-package" :value="targetId || ''" :disabled="busy" @change="$emit('target', Number($event.target.value))">
        <option value="" disabled>请选择具体套餐</option>
        <option v-for="(item, index) in matches" :key="item.id" :value="item.id">{{ packageLabel(item, index) }}</option>
      </select>
      <p v-if="selectedExpiry" class="selected-expiry">当前到期：{{ selectedExpiry }}</p>
      <p>{{ hint }}</p>
    </div>
    <p class="price-source-hint">{{ priceHint }}</p>
    <div v-if="error" class="choice-error" role="alert"><span>{{ error }}</span><button type="button" :disabled="loading || busy" @click="$emit('retry')">重新读取</button></div>
  </section>
</template>
<script>
import { IconPlus, IconClock, IconChevronDown } from '@tabler/icons-vue';
export default {
  components: { IconPlus, IconClock, IconChevronDown },
  props: { action: String, targetId: Number, matches: { type: Array, default: () => [] }, ownedCount: Number, ownedPackages: { type: Array, default: () => [] }, loading: Boolean, busy: Boolean, error: String, hint: String, priceHint: String },
  emits: ['action', 'target', 'retry'],
  data: () => ({ showOwned: false }),
  computed: {
    selectedExpiry() {
      const item = this.matches.find(item => Number(item.id) === this.targetId);
      if (!item) return '';
      return item.expired_at == null ? '长期有效' : new Date(Number(item.expired_at) * 1000).toLocaleString('zh-CN', {hour12: false});
    }
  },
  methods: {
    ownedName(item) {
      const same = this.ownedPackages.filter(p => Number(p.plan_id) === Number(item.plan_id));
      return (item.plan_name || '当前套餐') + (same.length > 1 ? ` · 第 ${same.findIndex(p => p.id === item.id) + 1} 份` : '');
    },
    isExpired(item) { return item.expired_at != null && Number(item.expired_at) * 1000 <= Date.now(); },
    expiryText(timestamp) { return new Date(Number(timestamp) * 1000).toLocaleString('zh-CN', {hour12: false}); },
    remainingTraffic(item) {
      if (item.remaining != null) return Math.max(0, Number(item.remaining));
      return item.transfer_enable == null ? null : Math.max(0, Number(item.transfer_enable) - Number(item.u || 0) - Number(item.d || 0));
    },
    formatTraffic(bytes) {
      if (bytes == null || !Number.isFinite(Number(bytes))) return '—';
      const value = Math.max(0, Number(bytes));
      if (!value) return '0 B';
      const units = ['B', 'KB', 'MB', 'GB', 'TB'];
      const index = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
      return `${Number((value / 1024 ** index).toFixed(index > 1 ? 2 : 0))} ${units[index]}`;
    },
    packageLabel(item, index) {
      const expiry = item.expired_at == null ? '长期有效' : `${Number(item.expired_at) * 1000 < Date.now() ? '已到期' : '到期'} ${new Date(Number(item.expired_at) * 1000).toLocaleDateString('zh-CN')}`;
      return `${item.plan_name || '当前套餐'}${this.matches.length > 1 ? ` · 第 ${index + 1} 份` : ''} · ${expiry}`;
    }
  }
};
</script>
<style scoped>
.purchase-choice { padding: 22px 24px; margin-bottom: 24px; border: 1px solid var(--border-color, #e0e6ef); border-radius: 18px; background: var(--card-bg-color, #fff); color: var(--text-color, #252b36); }
.choice-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
.choice-heading h3 { margin: 0; font-size: 17px; }
.choice-heading > span { font-size: 13px; color: var(--secondary-text-color, #697386); }
.owned-toggle { display: inline-flex; align-items: center; gap: 6px; padding: 7px 9px; margin-right: -9px; color: var(--theme-color, #355cc2); background: transparent; border: 0; border-radius: 7px; font: inherit; font-size: 13px; cursor: pointer; }
.owned-toggle:hover { background: rgba(var(--theme-color-rgb, 53,92,194), .07); }.owned-toggle:focus-visible { outline: 2px solid var(--theme-color, #355cc2); outline-offset: 2px; }.owned-toggle svg { flex-shrink: 0; transition: transform .15s; }.owned-toggle svg.expanded { transform: rotate(180deg); }
.owned-packages { margin: 0 0 18px; padding: 2px; max-height: 340px; overflow-y: auto; overscroll-behavior: contain; }
.owned-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; list-style: none; margin: 0; padding: 0; }
.owned-card { padding: 14px; border-radius: 10px; background: rgba(var(--theme-color-rgb, 53,92,194), .035); border: 1px solid var(--border-color, #e0e6ef); min-width: 0; }
.owned-card-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }.owned-card-heading strong { font-size: 14px; line-height: 1.6; overflow-wrap: anywhere; }.owned-expired { flex-shrink: 0; font-size: 11px; color: var(--secondary-text-color, #697386); }
.owned-expiry { margin: 7px 0 10px; font-size: 12px; line-height: 1.6; color: var(--secondary-text-color, #697386); overflow-wrap: anywhere; }.owned-traffic { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 12px; line-height: 1.6; color: var(--secondary-text-color, #697386); }.owned-traffic b { color: var(--text-color, #252b36); font-weight: 600; }.owned-empty { padding: 15px; font-size: 13px; color: var(--secondary-text-color, #697386); }
.choice-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.choice-option { display: flex; align-items: center; gap: 12px; padding: 17px; border: 1px solid var(--border-color, #e0e6ef); border-radius: 12px; cursor: pointer; transition: border-color .15s, background .15s; min-width: 0; }
.choice-option.selected { border-color: var(--theme-color, #355cc2); background: rgba(var(--theme-color-rgb, 53,92,194), .06); }
.choice-option:focus-within { outline: 2px solid var(--theme-color, #355cc2); outline-offset: 2px; }
.choice-option input { margin: 0; width: 16px; height: 16px; flex-shrink: 0; accent-color: var(--theme-color, #355cc2); }
.choice-copy { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 0; }
.choice-copy strong { font-size: 15px; }.choice-copy small { font-size: 12px; line-height: 1.6; color: var(--secondary-text-color, #697386); }
.choice-symbol { color: var(--theme-color, #355cc2); flex-shrink: 0; }.unavailable { opacity: .6; cursor: default; }
.renewal-selection { margin-top: 18px; }.renewal-selection > label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; }
.renewal-selection select { width: 100%; box-sizing: border-box; min-width: 0; border-radius: 9px; border: 1px solid var(--border-color, #e0e6ef); padding: 12px; color: inherit; background: var(--card-bg-color, #fff); font: inherit; font-size: 14px; }
.renewal-selection p { font-size: 12px; line-height: 1.7; margin: 9px 0 0; color: var(--secondary-text-color, #697386); }
.price-source-hint { margin: 15px 0 0; padding-top: 12px; border-top: 1px solid var(--border-color, #e0e6ef); font-size: 12px; line-height: 1.7; color: var(--secondary-text-color, #697386); }
.renewal-selection .selected-expiry { color: var(--text-color, #252b36); overflow-wrap: anywhere; }
.choice-error { display: flex; align-items: center; gap: 12px; margin-top: 14px; color: #c2410c; font-size: 13px; line-height: 1.5; }.choice-error button { flex-shrink: 0; background: transparent; color: inherit; padding: 5px 8px; border: 1px solid currentColor; border-radius: 6px; cursor: pointer; }
@media (max-width: 600px) { .owned-grid { grid-template-columns: 1fr; }.owned-toggle { font-size: 12px; }.choice-heading { flex-wrap: wrap; } .purchase-choice { padding: 17px; border-radius: 14px; }.choice-options { grid-template-columns: 1fr; gap: 10px; }.choice-option { padding: 14px; }.choice-heading { align-items: baseline; }.choice-heading > span { font-size: 12px; } }
</style>
