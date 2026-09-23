<template>
  <div class="package-grid">
    <article v-for="item in subscriptions" :key="item.id" class="package-card"
             :class="{'package-selected': item.id === selectedId}" :aria-current="item.id === selectedId ? 'true' : undefined"
             tabindex="0" @click="$emit('select', item)"
             @keydown.enter.self.prevent="$emit('select', item)"
             @keydown.space.self.prevent="$emit('select', item)">
      <h2 class="package-title">{{ $t('dashboard.subscriptionInfo') }} <IconCircleCheck v-if="item.id === selectedId" :size="18" class="selected-mark"/></h2>
      <div class="package-info">
        <div class="package-field">
          <span class="package-label">{{ $t('dashboard.planName') }}</span>
          <strong>{{ item.display_name || item.plan_name || $t('dashboard.noSubscription') }}</strong>
        </div>
        <div class="package-field">
          <span class="package-label">{{ $t('dashboard.expiryDate') }}</span>
          <strong :class="{'package-expired': isExpired(item)}">{{ Number(item.expired_at) > 0 ? formatDate(item.expired_at) : $t('dashboard.permanent') }}</strong>
        </div>
        <div class="package-field">
          <span class="package-label">{{ $t('dashboard.planTraffic') }}
            <button type="button" class="package-detail" @click="$emit('details', item)">{{ $t('dashboard.viewDetails') }}</button>
          </span>
          <strong>{{ formatTraffic(item.transfer_enable) }}</strong>
        </div>
      </div>
      <div class="package-usage">
        <span>{{ $t('dashboard.usedTraffic') }} {{ formatTraffic(usedTraffic(item)) }}</span>
        <span>{{ $t('dashboard.remainingTraffic') }} {{ formatTraffic(Math.max(0, Number(item.remaining) || 0)) }}</span>
      </div>
      <div class="package-actions">
        <button type="button" class="package-button package-import" @click="$emit('import', item)"><IconShare :size="16"/>{{ $t('dashboard.importSubscription') }}</button>
        <button type="button" class="package-button" @click="$emit('renew', item)"><IconShoppingCart :size="16"/>{{ $t('dashboard.renewPlan') }}</button>
        <button type="button" class="package-button" @click="$emit('support', item)"><IconMessage :size="16"/>{{ $t('dashboard.ticketSupport') }}</button>
      </div>
    </article>
  </div>
</template>

<script setup>
import {IconCircleCheck, IconMessage, IconShare, IconShoppingCart} from '@tabler/icons-vue';
const props = defineProps({
  subscriptions: {type: Array, required: true},
  formatTraffic: {type: Function, required: true},
  formatDate: {type: Function, required: true},
  now: {type: Number, required: true},
  selectedId: {type: Number, default: null}
});
defineEmits(['details', 'import', 'renew', 'support', 'select']);
const usedTraffic = item => Math.max(0, (Number(item.u) || 0) + (Number(item.d) || 0));
const isExpired = item => Number(item.expired_at) > 0 && Number(item.expired_at) * 1000 <= props.now;
</script>

<style scoped>
.package-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 540px), 1fr)); gap: 20px; margin-bottom: 24px; }
.package-card { cursor: pointer; min-width: 0; padding: 24px 30px; border: 1px solid var(--border-color); border-radius: 16px; background: var(--card-bg-color); box-shadow: 0 2px 10px rgba(0,0,0,.05); color: var(--text-color); }
.package-selected { border-color: var(--theme-color); box-shadow: 0 0 0 2px rgba(var(--theme-color-rgb), .12), 0 2px 10px rgba(0,0,0,.05); }
.package-card:focus-visible { outline: 2px solid var(--theme-color); outline-offset: 3px; }
.package-title { display: flex; align-items: center; gap: 8px; margin: 0 0 22px; font-size: 20px; font-weight: 600; }
.selected-mark { margin-left: auto; color: var(--theme-color); flex: none; }
.package-title small { margin-left: 8px; font-size: 12px; font-weight: 400; color: var(--secondary-text-color); }
.package-info { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px 30px; align-items: start; }
.package-field { min-width: 0; display: flex; flex-direction: column; gap: 8px; }
.package-label { color: var(--secondary-text-color); font-size: 14px; }
.package-field strong { font-size: 18px; font-weight: 600; overflow-wrap: anywhere; }
.package-field strong.package-expired { color: #a81f1f; }
.package-detail { padding: 0 0 0 5px; border: 0; background: none; color: var(--theme-color); font: inherit; cursor: pointer; }
.package-detail:hover { text-decoration: underline; }
.package-detail:focus-visible, .package-button:focus-visible { outline: 2px solid var(--theme-color); outline-offset: 2px; }
.package-usage { display: flex; flex-wrap: wrap; gap: 6px 20px; margin-top: 12px; color: var(--secondary-text-color); font-size: 13px; }
.package-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
.package-button { display: inline-flex; align-items: center; justify-content: center; gap: 9px; min-height: 42px; padding: 8px 14px; border: 1px solid var(--border-color); border-radius: 9px; background: transparent; color: var(--text-color); font: inherit; font-size: 14px; font-weight: 600; white-space: nowrap; cursor: pointer; }
.package-button:hover { border-color: var(--theme-color); color: var(--theme-color); }
.package-import { background: var(--theme-color); border-color: var(--theme-color); color: white; }
.package-import:hover { color: white; background: var(--primary-color-hover, var(--theme-color)); }
@media (max-width: 1150px) { .package-grid { grid-template-columns: minmax(0, 1fr); } }
@media (max-width: 640px) {
  .package-card { padding: 20px; }
  .package-info { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
  .package-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .package-button { padding: 8px; font-size: 13px; }
}
@media (max-width: 360px) { .package-info, .package-actions { grid-template-columns: minmax(0, 1fr); } }
</style>
