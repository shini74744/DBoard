<template>
  <div class="user-avatar-container" ref="avatarContainer">
    <div class="avatar-wrapper" @click="toggleDropdown">
      <img
        v-if="avatarUrl"
        :src="avatarUrl"
        alt="User Avatar"
        class="avatar-image"
      />
      <div v-else class="avatar-placeholder">
        <IconUser class="user-icon" />
      </div>
    </div>

    <transition name="fade">
      <div
        class="dropdown-menu"
        v-if="isDropdownOpen"
      >
        <div class="menu-item" @click="navigateTo('/profile')">
          <IconUser class="menu-icon" />
          <span>{{ $t('common.userCenter') }}</span>
        </div>
        <div class="menu-item" v-if="isXiaoV2board" @click="navigateTo('/wallet/deposit')">
          <IconWallet class="menu-icon" />
          <span>{{ $t('common.myWallet') }}</span>
        </div>
        <div class="menu-item" @click="openNavigationSettings">
          <IconSettings class="menu-icon" />
          <span>{{ $t('common.navigationSettings') }}</span>
        </div>
        <div class="menu-item" @click="navigateTo('/profile?openPasswordModal=true')">
          <IconLock class="menu-icon" />
          <span>{{ $t('common.changePassword') }}</span>
        </div>
        <div class="divider"></div>
        <div class="menu-item" @click="logout">
          <IconLogout class="menu-icon" />
          <span>{{ $t('common.logoutText') }}</span>
        </div>
      </div>
    </transition>
  </div>
  <Teleport to="body">
    <div v-if="showNavigationSettings" class="navigation-settings-overlay" @click.self="closeNavigationSettings">
      <section class="navigation-settings-dialog" role="dialog" aria-modal="true" :aria-label="$t('common.navigationSettings')">
        <div class="navigation-settings-header">
          <h2>{{ $t('common.navigationSettings') }}</h2>
          <button type="button" class="navigation-settings-close" :aria-label="$t('common.cancel')" @click="closeNavigationSettings">×</button>
        </div>
        <p class="navigation-settings-hint">{{ $t('common.navigationSettingsHint') }}</p>
        <div v-if="navigationLoading" class="navigation-settings-state">{{ $t('common.loading') }}</div>
        <div v-else class="navigation-settings-list">
          <label v-for="key in navigationOptions" :key="key" class="navigation-settings-row">
            <span>{{ $t(`menu.${key}`) }}</span>
            <input type="checkbox" :checked="!navigationDraft.includes(key)" :disabled="navigationSaving"
              @change="toggleNavigationItem(key, $event.target.checked)" />
          </label>
        </div>
        <p v-if="navigationError" class="navigation-settings-error" role="alert">{{ navigationError }}</p>
        <div class="navigation-settings-actions">
          <button type="button" class="navigation-settings-cancel" @click="closeNavigationSettings">{{ $t('common.cancel') }}</button>
          <button type="button" class="navigation-settings-save" :disabled="navigationLoading || navigationSaving" @click="saveNavigationSettings">
            {{ navigationSaving ? $t('common.loading') : $t('common.save') }}
          </button>
        </div>
      </section>
    </div>
  </Teleport>
</template>

<script>
import { ref, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useToast } from '@/composables/useToast';
import { isXiaoV2board } from '@/utils/baseConfig';
import IconUser from '@/components/icons/IconUser.vue';
import IconLogout from '@/components/icons/IconLogout.vue';
import IconWallet from '@/components/icons/IconWallet.vue';
import IconLock from '@/components/icons/IconLock.vue';
import { IconSettings } from '@tabler/icons-vue';
import { useNavigationPreferences, optionalNavItems, resetNavigationPreferences } from '@/composables/useNavigationPreferences';

export default {
  name: 'UserAvatar',
  components: {
    IconUser,
    IconLogout,
    IconWallet,
    IconLock,
    IconSettings
  },
  props: {
    username: {
      type: String,
      default: ''
    },
    avatarUrl: {
      type: String,
      default: ''
    }
  },
  setup() {
    const router = useRouter();
    const { t } = useI18n();
    const { showToast } = useToast();
    const isDropdownOpen = ref(false);
    const avatarContainer = ref(null);
    const showNavigationSettings = ref(false);
    const navigationLoading = ref(false);
    const navigationSaving = ref(false);
    const navigationError = ref('');
    const navigationDraft = ref([]);
    const navigationOptions = optionalNavItems();
    const { hiddenItems, loadNavigationPreferences, saveNavigationPreferences } = useNavigationPreferences();

    const openNavigationSettings = async () => {
      isDropdownOpen.value = false;
      showNavigationSettings.value = true;
      navigationLoading.value = true;
      navigationError.value = '';
      try {
        await loadNavigationPreferences(true);
        navigationDraft.value = [...hiddenItems.value];
      } catch (error) {
        navigationError.value = t('common.navigationSettingsLoadFailed');
      } finally {
        navigationLoading.value = false;
      }
    };

    const closeNavigationSettings = () => {
      if (!navigationSaving.value) showNavigationSettings.value = false;
    };

    const toggleNavigationItem = (key, visible) => {
      navigationDraft.value = visible
        ? navigationDraft.value.filter(item => item !== key)
        : [...new Set([...navigationDraft.value, key])];
    };

    const saveNavigationSettings = async () => {
      navigationSaving.value = true;
      navigationError.value = '';
      try {
        await saveNavigationPreferences(navigationDraft.value);
        showNavigationSettings.value = false;
        showToast(t('common.navigationSettingsSaved'), 'success', 3000);
      } catch (error) {
        navigationError.value = t('common.navigationSettingsSaveFailed');
      } finally {
        navigationSaving.value = false;
      }
    };

    const toggleDropdown = () => {
      isDropdownOpen.value = !isDropdownOpen.value;
    };

    const navigateTo = (path) => {
      isDropdownOpen.value = false;
      router.push(path);
    };

    const logout = async () => {
      try {
        localStorage.removeItem('token');
        resetNavigationPreferences();
        isDropdownOpen.value = false;

        showToast(t('auth.logoutSuccess'), 'success', 3000);

        setTimeout(() => {
          router.push('/login');
        }, 500);
      } catch (error) {
        console.error('退出登录失败:', error);
        showToast(t('auth.logoutFailed'), 'error');
      }
    };

    const handleClickOutside = (event) => {
      if (avatarContainer.value && !avatarContainer.value.contains(event.target)) {
        isDropdownOpen.value = false;
      }
    };

    onMounted(() => {
      document.addEventListener('click', handleClickOutside);
    });

    onUnmounted(() => {
      document.removeEventListener('click', handleClickOutside);
    });

    return {
      isDropdownOpen,
      showNavigationSettings, navigationLoading, navigationSaving, navigationError, navigationDraft, navigationOptions,
      openNavigationSettings, closeNavigationSettings, toggleNavigationItem, saveNavigationSettings,
      toggleDropdown,
      navigateTo,
      logout,
      avatarContainer,
      isXiaoV2board: isXiaoV2board()
    };
  }
};
</script>

<style lang="scss" scoped>
.user-avatar-container {
  position: relative;
}

.avatar-wrapper {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  cursor: pointer;
  overflow: hidden;
  background-color: rgba(var(--theme-color-rgb), 0.1);
  border: 1px solid rgba(var(--theme-color-rgb), 0.3);
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;

  &:hover {
    box-shadow: 0 0 0 3px rgba(var(--theme-color-rgb), 0.15);
    transform: translateY(-2px);
  }

  .avatar-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .avatar-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;

    .user-icon {
      width: 20px;
      height: 20px;
      color: var(--theme-color);
    }
  }
}

.dropdown-menu {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  width: 180px;
  background: rgba(var(--card-background-rgb), 0.9);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-radius: 12px;
  box-shadow: 0 4px 20px var(--shadow-color);
  border: 1px solid var(--border-color);
  overflow: hidden;
  z-index: 100;
  animation: dropdownFadeIn 0.2s ease;

  .menu-item {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    cursor: pointer;
    transition: all 0.3s ease;

    .menu-icon {
      width: 18px;
      height: 18px;
      margin-right: 10px;
      color: var(--text-color);
      transition: color 0.3s ease;
    }

    span {
      font-size: 14px;
      color: var(--text-color);
      transition: color 0.3s ease;
    }

    &:hover {
      background-color: rgba(var(--primary-color-rgb), 0.1);
      color: var(--primary-color);

      .menu-icon, span {
        color: var(--primary-color);
      }
    }

    &:last-child {
      .menu-icon, span {
        transition: color 0.5s ease;
      }

      &:hover {
        background-color: rgba(245, 108, 108, 0.05);
        transition: background-color 0.5s ease;

        .menu-icon, span {
          color: #f56c6c;
          transition: color 0.5s ease;
        }
      }
    }
  }

  .divider {
    height: 1px;
    background-color: var(--border-color);
    margin: 4px 0;
  }
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease, transform 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

@keyframes dropdownFadeIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.navigation-settings-overlay {
  position: fixed;
  inset: 0;
  z-index: 10000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(15, 23, 42, .55);
}
.navigation-settings-dialog {
  width: min(100%, 420px);
  max-height: calc(100svh - 32px);
  overflow-y: auto;
  padding: 22px;
  border: 1px solid var(--border-color);
  border-radius: 18px;
  background: var(--card-background, #fff);
  color: var(--text-color);
  box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
}
.navigation-settings-header, .navigation-settings-row, .navigation-settings-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.navigation-settings-header h2 { margin: 0; font-size: 20px; }
.navigation-settings-close { border: 0; background: transparent; color: inherit; font-size: 28px; cursor: pointer; }
.navigation-settings-hint { margin: 10px 0 18px; color: var(--text-secondary, var(--text-color)); font-size: 14px; }
.navigation-settings-list { display: grid; gap: 10px; }
.navigation-settings-row { padding: 12px 14px; border: 1px solid var(--border-color); border-radius: 10px; cursor: pointer; }
.navigation-settings-row input { width: 18px; height: 18px; accent-color: var(--theme-color); }
.navigation-settings-state { padding: 18px 0; }
.navigation-settings-error { color: #dc2626; font-size: 14px; }
.navigation-settings-actions { justify-content: flex-end; margin-top: 20px; }
.navigation-settings-actions button { padding: 9px 18px; border-radius: 9px; cursor: pointer; }
.navigation-settings-cancel { border: 1px solid var(--border-color); background: transparent; color: inherit; }
.navigation-settings-save { border: 0; background: var(--theme-color); color: #fff; }
.navigation-settings-save:disabled { opacity: .6; cursor: not-allowed; }
</style>
