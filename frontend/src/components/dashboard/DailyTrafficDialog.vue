<template>
  <div class="daily-backdrop" @click.self="emit('close')">
    <section class="daily-dialog" role="dialog" aria-modal="true" :aria-label="$t('dashboard.dailyUsage')">
      <header class="daily-header">
        <div><p>{{ $t('dashboard.last30Days') }}</p><h2>{{ $t('dashboard.dailyUsage') }}</h2></div>
        <button type="button" :aria-label="$t('common.close')" @click="emit('close')">×</button>
      </header>
      <p class="daily-note">{{ $t('dashboard.dailyUsageHint') }}</p>
      <div v-if="loading" class="daily-state" role="status">{{ $t('dashboard.loadingDailyUsage') }}</div>
      <div v-else-if="error" class="daily-state" role="alert">
        <span>{{ error }}</span><button type="button" @click="load">{{ $t('dashboard.retry') }}</button>
      </div>
      <template v-else>
        <div class="daily-summary">
          <div><span>{{ $t('dashboard.totalUsed') }}</span><strong>{{ formatBytes(total) }}</strong></div>
          <div><span>{{ $t('dashboard.upload') }}</span><strong>{{ formatBytes(upload) }}</strong></div>
          <div><span>{{ $t('dashboard.download') }}</span><strong>{{ formatBytes(download) }}</strong></div>
        </div>
        <div class="daily-list">
          <div v-for="day in days" :key="day.date" class="daily-row">
            <div class="daily-row-head"><time :datetime="day.date">{{ day.date }}</time><strong>{{ formatBytes(day.total) }}</strong></div>
            <div class="daily-track" :aria-label="day.date + ' ' + formatBytes(day.total)">
              <div :style="{ width: Math.max(0, Math.min(100, day.total / maxDaily * 100)) + '%' }"></div>
            </div>
            <small>{{ $t('dashboard.upload') }} {{ formatBytes(day.u) }} · {{ $t('dashboard.download') }} {{ formatBytes(day.d) }}</small>
          </div>
        </div>
      </template>
    </section>
  </div>
</template>

<script setup>
import {computed, onBeforeUnmount, onMounted, ref} from 'vue';
import {useI18n} from 'vue-i18n';
import {getDailyTraffic} from '@/api/trafficLog';

const emit = defineEmits(['close']);
const {t} = useI18n();
const days = ref([]);
const loading = ref(true);
const error = ref('');
const total = computed(() => days.value.reduce((sum, day) => sum + Number(day.total || 0), 0));
const upload = computed(() => days.value.reduce((sum, day) => sum + Number(day.u || 0), 0));
const download = computed(() => days.value.reduce((sum, day) => sum + Number(day.d || 0), 0));
const maxDaily = computed(() => Math.max(1, ...days.value.map(day => Number(day.total || 0))));
let previousOverflow = '';

function formatBytes(bytes) {
  const value = Math.max(0, Number(bytes) || 0);
  if (value < 1024) return Math.round(value) + ' B';
  const units = ['KB', 'MB', 'GB', 'TB'];
  let amount = value / 1024;
  let unit = units[0];
  for (const next of units.slice(1)) {
    if (amount < 1024) break;
    amount /= 1024;
    unit = next;
  }
  return amount.toFixed(amount >= 10 ? 1 : 2) + ' ' + unit;
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const response = await getDailyTraffic(30);
    if (!Array.isArray(response.data)) throw new Error('Invalid daily usage response');
    days.value = response.data;
  } catch (_) {
    error.value = t('dashboard.dailyUsageError');
  } finally {
    loading.value = false;
  }
}

function onKeydown(event) {
  if (event.key === 'Escape') emit('close');
}

onMounted(() => {
  previousOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  window.addEventListener('keydown', onKeydown);
  load();
});
onBeforeUnmount(() => {
  document.body.style.overflow = previousOverflow;
  window.removeEventListener('keydown', onKeydown);
});
</script>

<style scoped>
.daily-backdrop { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 16px; background: rgba(11, 18, 32, .68); }
.daily-dialog { width: min(100%, 620px); max-height: min(86svh, 820px); display: flex; flex-direction: column; overflow: hidden; border: 1px solid var(--border-color); border-radius: 20px; background: var(--card-background); color: var(--text-color); box-shadow: 0 28px 80px rgba(0, 0, 0, .28); }
.daily-header { display: flex; align-items: start; justify-content: space-between; gap: 16px; padding: 24px 24px 8px; }
.daily-header h2 { margin: 0; font-size: 22px; line-height: 1.25; }
.daily-header p { margin: 0 0 6px; color: var(--theme-color); font-size: 12px; font-weight: 700; letter-spacing: .06em; }
.daily-header button { width: 36px; height: 36px; flex: none; border: 1px solid var(--border-color); border-radius: 10px; background: transparent; color: var(--text-color); cursor: pointer; font-size: 25px; line-height: 1; }
.daily-header button:hover { background: rgba(var(--theme-color-rgb), .1); }
.daily-note { margin: 0; padding: 0 24px 18px; color: var(--secondary-text-color); font-size: 13px; }
.daily-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; padding: 0 24px 18px; }
.daily-summary > div { display: grid; gap: 5px; min-width: 0; padding: 12px; border: 1px solid var(--border-color); border-radius: 12px; background: rgba(var(--theme-color-rgb), .055); }
.daily-summary span, .daily-row small { color: var(--secondary-text-color); font-size: 12px; }
.daily-summary strong { overflow-wrap: anywhere; font-size: 16px; }
.daily-list { overflow-y: auto; min-height: 0; padding: 0 24px 24px; }
.daily-row { padding: 12px 0; border-top: 1px solid var(--border-color); }
.daily-row-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; font-size: 13px; }
.daily-row-head strong { font-size: 14px; }
.daily-track { height: 7px; margin: 9px 0 6px; overflow: hidden; border-radius: 99px; background: rgba(var(--theme-color-rgb), .1); }
.daily-track > div { height: 100%; border-radius: inherit; background: var(--theme-color); }
.daily-state { display: grid; place-items: center; gap: 12px; min-height: 190px; padding: 24px; text-align: center; color: var(--secondary-text-color); }
.daily-state button { border: 0; border-radius: 8px; padding: 8px 18px; background: var(--theme-color); color: #fff; cursor: pointer; }
:global(body.dark-theme) .daily-state button { color: #142027; }
@media (max-width: 480px) { .daily-header { padding: 18px 16px 8px; } .daily-note { padding: 0 16px 14px; } .daily-summary { gap: 6px; padding: 0 16px 14px; } .daily-summary > div { padding: 8px; } .daily-summary strong { font-size: 13px; } .daily-list { padding: 0 16px 16px; } }
</style>
