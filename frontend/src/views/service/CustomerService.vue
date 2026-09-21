<template>
  <div
    class="customer-service-container"
    :class="{ 'dark-theme': isDarkTheme }"
  >
    <!-- 顶部导航栏 -->
    <div class="service-header">
      <button class="back-button" @click="goBack">
        <IconArrowLeft :size="24" />
      </button>
      <h1 class="service-title">{{ t('service.title') }}</h1>

      <!-- 添加一个刷新按钮 -->
      <button class="refresh-button" @click="refreshChat">
        <IconRefresh :size="20" />
      </button>
    </div>

    <!-- 客服系统容器 -->
    <div class="service-content">
      <!-- Crisp客服系统会自动注入到页面中 -->
      <div v-if="serviceType === 'crisp'" class="service-crisp-container">
        <!-- 加载中提示，当聊天窗口加载时显示 -->
        <div v-if="loading" class="service-loading">
          <div class="loading-spinner"></div>
          <p>{{ t('service.loading') }}</p>
        </div>
      </div>

      <!-- 其他客服系统直接渲染HTML -->
      <div v-else-if="serviceType === 'other'" class="service-other-container">
        <!-- 提示用户等待 -->
        <div class="other-service-tips">{{ t('service.waitForIcon') }}</div>
        <!-- 客服系统容器 -->
        <div id="other-service-wrapper" ref="otherServiceContainer"></div>
      </div>

      <!-- 加载中提示 -->
      <div v-else class="service-loading">
        <div class="loading-spinner"></div>
        <p>{{ t('service.loading') }}</p>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted, onUnmounted, computed, watch, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import { useStore } from 'vuex';
import { useI18n } from 'vue-i18n';
import { CUSTOMER_SERVICE_CONFIG } from '@/utils/baseConfig';
import { getUserInfo, getCommConfig, getUserSubscribe } from '@/api/user';
import { formatDate } from '@/utils/formatters';
import { IconArrowLeft, IconRefresh } from '@tabler/icons-vue';
import { Crisp } from 'crisp-sdk-web';

if (typeof window !== 'undefined') {
  window.CRISP_INITIALIZED = window.CRISP_INITIALIZED || false;
}

export default {
  name: 'CustomerService',
  components: {
    IconArrowLeft,
    IconRefresh
  },
  setup() {
    const router = useRouter();
    const store = useStore();
    const { t } = useI18n();
    const userInfo = ref(null);
    const loading = ref(true);
    const otherServiceContainer = ref(null);
    const crispInitialized = ref(window.CRISP_INITIALIZED || false);
    const currencySymbol = ref('¥');
    const userSubscribe = ref(null);

    const serviceType = computed(() => {
      return CUSTOMER_SERVICE_CONFIG?.type || 'crisp';
    });

    const initCrisp = () => {
      if (serviceType.value !== 'crisp') return;

      if (window.CRISP_INITIALIZED) {
        try {
          Crisp.chat.show();
          Crisp.chat.open();
          loading.value = false;
          crispInitialized.value = true;
          return;
        } catch (error) {
          console.error('显示已初始化的Crisp失败:', error);
        }
      }

      try {
        const crispIdMatch = CUSTOMER_SERVICE_CONFIG?.customHtml?.match(/CRISP_WEBSITE_ID="([^"]*)"/);
        const websiteId = crispIdMatch ? crispIdMatch[1] : '';

        if (!websiteId) {
          console.error('无法从配置中提取Crisp ID');
          loading.value = false;
          return;
        }

        Crisp.configure(websiteId);

        const storedTheme = localStorage.getItem('theme');
        const currentTheme = storedTheme || store.getters.currentTheme || 'light';
        const isCurrentDarkTheme = currentTheme === 'dark';

        if (isCurrentDarkTheme) {
          Crisp.setColorTheme('dark');
        }

        if (store.getters.isLoggedIn) {
          let userEmail = '';

          if (userInfo.value) {
            if (typeof userInfo.value === 'object') {
              if (userInfo.value.email) {
                userEmail = userInfo.value.email;
              } else if (userInfo.value.data && userInfo.value.data.email) {
                userEmail = userInfo.value.data.email;
              }
            }
          }

          if (!userEmail && userSubscribe.value) {
            if (typeof userSubscribe.value === 'object') {
              if (userSubscribe.value.email) {
                userEmail = userSubscribe.value.email;
              } else if (userSubscribe.value.data && userSubscribe.value.data.email) {
                userEmail = userSubscribe.value.data.email;
              }
            }
          }

          if (!userEmail) {
            const storedUser = localStorage.getItem('user');
            if (storedUser) {
              try {
                const parsedUser = JSON.parse(storedUser);
                if (parsedUser && parsedUser.email) {
                  userEmail = parsedUser.email;
                }
              } catch (e) {
                console.error('解析localStorage用户数据失败:', e);
              }
            }
          }

          if (userEmail) {
            Crisp.user.setEmail(userEmail);
            const nickname = userEmail.split('@')[0];
            Crisp.user.setNickname(nickname);
          } else {
            console.warn('无法获取用户邮箱，跳过设置邮箱和昵称');
          }

          let planName = "未知套餐";
          if (userSubscribe.value && userSubscribe.value.plan && userSubscribe.value.plan.name) {
            planName = userSubscribe.value.plan.name;
          } else if (userSubscribe.value && userSubscribe.value.data && userSubscribe.value.data.plan && userSubscribe.value.data.plan.name) {
            planName = userSubscribe.value.data.plan.name;
          } else if (userInfo.value && userInfo.value.plan_name && userInfo.value.plan_name.trim() !== '') {
            planName = userInfo.value.plan_name;
          } else if (userInfo.value && userInfo.value.group && userInfo.value.group.name && userInfo.value.group.name.trim() !== '') {
            planName = userInfo.value.group.name;
          }

          let expireDate = "无限期";
          if (userSubscribe.value && userSubscribe.value.expired_at) {
            expireDate = formatDate(userSubscribe.value.expired_at);
          } else if (userSubscribe.value && userSubscribe.value.data && userSubscribe.value.data.expired_at) {
            expireDate = formatDate(userSubscribe.value.data.expired_at);
          } else if (userInfo.value && userInfo.value.expired_at) {
            expireDate = formatDate(userInfo.value.expired_at);
          } else if (userInfo.value && userInfo.value.data && userInfo.value.data.expired_at) {
            expireDate = formatDate(userInfo.value.data.expired_at);
          }

          let transferEnable = 0;
          let u = 0;
          let d = 0;

          if (userSubscribe.value) {
            if (typeof userSubscribe.value === 'object') {
              if (userSubscribe.value.transfer_enable !== undefined) {
                transferEnable = userSubscribe.value.transfer_enable || 0;
                u = userSubscribe.value.u || 0;
                d = userSubscribe.value.d || 0;
              } else if (userSubscribe.value.data && userSubscribe.value.data.transfer_enable !== undefined) {
                transferEnable = userSubscribe.value.data.transfer_enable || 0;
                u = userSubscribe.value.data.u || 0;
                d = userSubscribe.value.data.d || 0;
              }
            }
          }

          if (transferEnable === 0 && userInfo.value) {
            if (typeof userInfo.value === 'object') {
              if (userInfo.value.transfer_enable !== undefined) {
                transferEnable = userInfo.value.transfer_enable || 0;
                u = userInfo.value.u || 0;
                d = userInfo.value.d || 0;
              } else if (userInfo.value.data && userInfo.value.data.transfer_enable !== undefined) {
                transferEnable = userInfo.value.data.transfer_enable || 0;
                u = userInfo.value.data.u || 0;
                d = userInfo.value.data.d || 0;
              }
            }
          }

          const remainingBytes = transferEnable - (u + d);

          const remainingGB = (remainingBytes / (1024 * 1024 * 1024)).toFixed(2);

          let balance = 0;

          if (userInfo.value) {
            if (typeof userInfo.value === 'object') {
              if (userInfo.value.balance !== undefined) {
                balance = userInfo.value.balance || 0;
              } else if (userInfo.value.data && userInfo.value.data.balance !== undefined) {
                balance = userInfo.value.data.balance || 0;
              }
            }
          }

          const sessionData = {
            Email: userEmail,
            Plan: planName,
            Expires: expireDate,
            Traffic: remainingGB + ' GB',
            Balance: balance + ' ' + currencySymbol.value
          };

          Crisp.session.setData(sessionData);
        }

        Crisp.session.onLoaded(() => {
          loading.value = false;
          window.CRISP_INITIALIZED = true;
          crispInitialized.value = true;
        });

      } catch (error) {
        console.error('初始化Crisp客服系统失败:', error);
        loading.value = false;
      }
    };

    const clearOtherService = () => {
      const scripts = document.querySelectorAll('script[id^="zoho"], script[id*="chat"], script[id*="support"], script[id*="customer"]');
      scripts.forEach(script => {
        if (script && script.parentNode) {
          script.parentNode.removeChild(script);
        }
      });

      if (otherServiceContainer.value) {
        otherServiceContainer.value.innerHTML = '';
      }

      if (window.ZohoDeskAsap) {
        window.ZohoDeskAsap = undefined;
      }

      const possibleElements = document.querySelectorAll('[id*="chat"], [id*="support"], [id*="crisp"], [id*="zoho"], [id*="custom-chat"]');
      possibleElements.forEach(el => {
        if (el && el.id !== 'other-service-wrapper' && el.parentNode) {
          el.parentNode.removeChild(el);
        }
      });
    };

    const loadOtherService = () => {
      if (!CUSTOMER_SERVICE_CONFIG?.customHtml) return;

      clearOtherService();

      const container = document.getElementById('other-service-wrapper');
      if (!container) return;

      const div = document.createElement('div');
      div.className = 'custom-chat-container';

      document.body.appendChild(div);

      try {
        const scriptContent = CUSTOMER_SERVICE_CONFIG.customHtml;
        const scriptElement = document.createElement('script');

        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = scriptContent;
        const originalScript = tempDiv.querySelector('script');

        if (originalScript) {
          Array.from(originalScript.attributes).forEach(attr => {
            scriptElement.setAttribute(attr.name, attr.value);
          });

          scriptElement.textContent = originalScript.textContent || '';

          document.body.appendChild(scriptElement);
        } else {
          scriptElement.textContent = scriptContent;
          document.body.appendChild(scriptElement);
        }

        loading.value = false;
      } catch (error) {
        console.error('加载客服系统脚本失败:', error);
        loading.value = false;
      }
    };

    const isDarkTheme = computed(() => {
      const storedTheme = localStorage.getItem('theme');
      return storedTheme === 'dark';
    });

    const refreshChat = () => {
      if (serviceType.value === 'crisp') {
        loading.value = true;
        Crisp.session.reset();
        window.CRISP_INITIALIZED = false;
        crispInitialized.value = false;

        nextTick(() => {
          initCrisp();
        });
      } else {
        window.location.reload();
      }
    };

    watch(() => isDarkTheme.value, (newVal) => {
      if (serviceType.value === 'crisp' && crispInitialized.value) {
        Crisp.setColorTheme(newVal ? 'dark' : 'light');
      }
    });

    const fetchUserInfo = async () => {
      if (!store.getters.isLoggedIn || serviceType.value !== 'crisp') return;

      try {
        const [userInfoResponse, commConfigResponse, subscribeResponse] = await Promise.all([
          getUserInfo(),
          getCommConfig(),
          getUserSubscribe()
        ]);

        userInfo.value = userInfoResponse.data ? userInfoResponse.data : userInfoResponse;

        const commConfigData = commConfigResponse.data ? commConfigResponse.data : commConfigResponse;

        if (commConfigData && commConfigData.currency_symbol) {
          currencySymbol.value = commConfigData.currency_symbol;
        }

        userSubscribe.value = subscribeResponse.data ? subscribeResponse.data : subscribeResponse;

      } catch (error) {
        console.error('获取用户信息失败:', error);
      }
    };

    const goBack = () => {
      if (serviceType.value === 'crisp' && crispInitialized.value) {
        try {
          Crisp.chat.hide();
        } catch (error) {
          console.error('隐藏Crisp失败:', error);
        }
      } else {
        clearOtherService();
      }

      router.back();
    };

    onMounted(async () => {
      await fetchUserInfo();

      if (serviceType.value === 'other') {
        const hasReloaded = sessionStorage.getItem('cs_page_reloaded');
        if (!hasReloaded) {
          sessionStorage.setItem('cs_page_reloaded', 'true');
          window.location.reload();
          return;
        }
      } else {
        sessionStorage.removeItem('cs_page_reloaded');
      }

      if (serviceType.value === 'crisp') {
        initCrisp();
      } else {
        loadOtherService();
      }

      window.addEventListener('storage', (e) => {
        if (e.key === 'theme' && serviceType.value === 'crisp' && crispInitialized.value) {
          const newTheme = e.newValue;
          Crisp.setColorTheme(newTheme === 'dark' ? 'dark' : 'light');
        }
      });
    });

    onUnmounted(() => {
      window.removeEventListener('storage', () => {});

      sessionStorage.removeItem('cs_page_reloaded');

      if (serviceType.value === 'crisp' && crispInitialized.value) {
        try {
          Crisp.chat.hide();
        } catch (error) {
          console.error('隐藏Crisp失败:', error);
        }
      } else {
        clearOtherService();
      }
    });

    return {
      isDarkTheme,
      otherServiceContainer,
      refreshChat,
      goBack,
      serviceType,
      loading,
      t
    };
  }
};
</script>

<style lang="scss" scoped>
.customer-service-container {
  display: flex;
  flex-direction: column;
  height: 100vh;
  width: 100%;
  background-color: var(--background-color);
  color: var(--text-color);

  &.dark-theme {
    --background-color: #171A1D;
    --card-background: rgba(30, 30, 30, 0.8);
    --text-color: rgba(255, 255, 255, 0.9);
    --secondary-text-color: rgba(255, 255, 255, 0.6);
    --border-color: rgba(255, 255, 255, 0.1);
  }

  &:not(.dark-theme) {
    --background-color: #f5f7fa;
    --card-background: #ffffff;
    --text-color: #333333;
    --secondary-text-color: #666666;
    --border-color: #e8e8e8;
  }
}

.service-header {
  display: flex;
  align-items: center;
  padding: 20px;
  position: relative;
  z-index: 10;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
  background-color: var(--card-background);
}

.back-button, .refresh-button {
  background: none;
  border: none;
  color: var(--theme-color);
  cursor: pointer;
  padding: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.refresh-button {
  margin-left: auto;
}

.service-title {
  margin: 0 0 0 12px;
  font-size: 18px;
  font-weight: 600;
  color: var(--text-color);
  flex-grow: 1;
}

.service-content {
  flex: 1;
  overflow: hidden;
  position: relative;
  width: 100%;
  height: calc(100vh - 70px);
}

.service-crisp-container {
  width: 100%;
  height: 100%;
  position: relative;
}

.service-other-container {
  width: 100%;
  height: 100%;
  overflow: auto;
  position: relative;

  .other-service-tips {
    padding: 16px;
    text-align: center;
    color: var(--secondary-text-color);
    font-size: 16px;
    margin-top: 40px;
  }
}

.service-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;

  .loading-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid var(--theme-color);
    border-top: 3px solid transparent;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 16px;
  }

  p {
    color: var(--text-color);
    font-size: 16px;
  }
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}


@media (max-width: 768px) {
  .service-header {
    padding: 16px;
  }

  .service-title {
    font-size: 16px;
  }

  .service-content {
    height: calc(100vh - 60px);
  }
}
</style>
