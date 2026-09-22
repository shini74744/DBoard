import { ref } from 'vue';
import { getUserInfo, updateRemindSettings } from '@/api/user';
import { NAVIGATION_CONFIG } from '@/utils/baseConfig';

const allowed = new Set(['shop', 'invite', 'docs', 'tickets', 'nodes', 'orders', 'traffic', 'wallet', 'profile']);
const hiddenItems = ref([]);
let pendingLoad = null;
let hasLoaded = false;

const normalize = value => Array.isArray(value)
  ? [...new Set(value.filter(item => typeof item === 'string' && allowed.has(item)))]
  : [];

export const optionalNavItems = () => [...new Set([
  'shop',
  NAVIGATION_CONFIG?.thirdNavItem || 'invite',
  NAVIGATION_CONFIG?.fourthNavItem || '',
].filter(item => allowed.has(item)))];

export async function loadNavigationPreferences(force = false) {
  if (hasLoaded && !force) return hiddenItems.value;
  if (pendingLoad) return pendingLoad;
  pendingLoad = getUserInfo().then(response => {
    hiddenItems.value = normalize(response?.data?.navigation_hidden);
    hasLoaded = true;
    return hiddenItems.value;
  }).finally(() => { pendingLoad = null; });
  return pendingLoad;
}

export async function saveNavigationPreferences(items) {
  const next = normalize(items);
  const response = await updateRemindSettings({ navigation_hidden: JSON.stringify(next) });
  if (response?.data !== true) throw new Error(response?.message || 'Save failed');
  hiddenItems.value = next;
  hasLoaded = true;
  return next;
}

export function resetNavigationPreferences() {
  hiddenItems.value = [];
  hasLoaded = false;
  pendingLoad = null;
}

export function useNavigationPreferences() {
  return { hiddenItems, loadNavigationPreferences, saveNavigationPreferences };
}
