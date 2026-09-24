import { ref, reactive, onBeforeUnmount } from 'vue';
import { getWebsiteConfig } from '@/api/auth';
import { AUTH_CONFIG } from '@/utils/baseConfig';
import { shouldShowAuthPopup } from '@/utils/authPopupState';

let pendingConfig;
function fetchConfig() {
  if (!pendingConfig) pendingConfig = getWebsiteConfig().finally(() => { pendingConfig = null; });
  return pendingConfig;
}

export function useAuthPopup() {
  const showAuthPopup = ref(false);
  const authPopupConfig = reactive({ title: '', content: '', cooldownHours: 0, closeWaitSeconds: 0 });
  let disposed = false;
  let opening = false;
  onBeforeUnmount(() => { disposed = true; });
  async function openAuthPopup() {
    if (opening || disposed || showAuthPopup.value) return;
    opening = true;
    let config = AUTH_CONFIG.popup || {};
    try {
      const response = await fetchConfig();
      const remote = response.data?.user_notice;
      if (remote && typeof remote === 'object') config = {
        enabled: remote.enabled === true || remote.enabled === 1,
        title: remote.title, content: remote.content,
        cooldownHours: remote.cooldown_hours, closeWaitSeconds: remote.close_wait_seconds,
      };
    } catch { /* Keep compatibility with panels that do not expose this setting. */ }
    finally { opening = false; }
    if (disposed) return;
    Object.assign(authPopupConfig, {
      title: config.title ?? '', content: config.content ?? '',
      cooldownHours: Number(config.cooldownHours ?? 0),
      closeWaitSeconds: Number(config.closeWaitSeconds ?? 0),
    });
    showAuthPopup.value = shouldShowAuthPopup({ ...authPopupConfig, enabled: config.enabled });
  }
  return { showAuthPopup, authPopupConfig, openAuthPopup };
}
