<template>

  <div class="more-container">

    <!-- 域名授权验证提示 - 如果不需要域名授权功能，移除此组件即可 -->





    <div class="more-inner">

      <!-- 欢迎卡片 -->

      <div class="dashboard-card welcome-card">

        <div class="card-header">

          <h2 class="card-title">{{ $t('more.title') }}</h2>

        </div>

        <div class="card-body">

          <p>{{ $t('more.description') }}</p>

        </div>

      </div>



      <!-- 功能导航卡片组 -->

      <div class="stats-grid">

        <div v-if="shouldShowShopCard" class="stats-card" @click="$router.push('/shop')">
          <div class="stats-icon"><IconShoppingCart :size="32" /></div>
          <div class="stats-info">
            <div class="stats-value">{{ $t('menu.shop') }}</div>
            <div class="stats-label">{{ $t('more.shopDescription') }}</div>
          </div>
          <div class="chevron-icon"><IconChevronRight :size="20" /></div>
        </div>

        <div v-if="shouldShowInviteCard" class="stats-card" @click="$router.push('/invite')">

          <div class="stats-icon">

            <IconUserPlus :size="32" />

          </div>

          <div class="stats-info">

            <div class="stats-value">{{ $t('invite.title') }}</div>

            <div class="stats-label">{{ $t('more.inviteDescription') }}</div>

          </div>

          <div class="chevron-icon">

            <IconChevronRight :size="20" />

          </div>

        </div>



        <div v-if="shouldShowDocsCard" class="stats-card" @click="$router.push('/docs')">

          <div class="stats-icon">

            <IconFileText :size="32" />

          </div>

          <div class="stats-info">

            <div class="stats-value">{{ $t('docs.title') }}</div>

            <div class="stats-label">{{ $t('more.viewHelp') }}</div>

          </div>

          <div class="chevron-icon">

            <IconChevronRight :size="20" />

          </div>

        </div>



        <div v-if="shouldShowNodesCard" class="stats-card" @click="$router.push('/nodes')">

          <div class="stats-icon">

            <IconServer :size="32" />

          </div>

          <div class="stats-info">

            <div class="stats-value">{{ $t('nodes.title') }}</div>

            <div class="stats-label">{{ $t('more.viewNodes') }}</div>

          </div>

          <div class="chevron-icon">

            <IconChevronRight :size="20" />

          </div>

        </div>



        <div v-if="shouldShowOrdersCard" class="stats-card" @click="$router.push('/orders')">

          <div class="stats-icon">

            <IconShoppingCart :size="32" />

          </div>

          <div class="stats-info">

            <div class="stats-value">{{ $t('orders.title') }}</div>

            <div class="stats-label">{{ $t('more.manageOrders') }}</div>

          </div>

          <div class="chevron-icon">

            <IconChevronRight :size="20" />

          </div>

        </div>



        <div v-if="shouldShowTicketsCard" class="stats-card" @click="navigateToTickets">

          <div class="stats-icon">

            <IconMessages :size="32" />

          </div>

          <div class="stats-info">

            <div class="stats-value">{{ $t('tickets.title') }}</div>

            <div class="stats-label">{{ $t('more.getTechnicalSupport') }}</div>

          </div>

          <div class="chevron-icon">

            <IconChevronRight :size="20" />

          </div>

        </div>



        <div class="stats-card" v-if="shouldShowTrafficCard && showTrafficLog" @click="$router.push('/trafficlog')">

          <div class="stats-icon">

            <IconChartBar :size="32" />

          </div>

          <div class="stats-info">

            <div class="stats-value">{{ $t('trafficLog.title') }}</div>

            <div class="stats-label">{{ $t('trafficLog.trafficLogDesc') }}</div>

          </div>

          <div class="chevron-icon">

            <IconChevronRight :size="20" />

          </div>

        </div>



        <!-- 充值选项，仅Xiao-V2board面板显示 -->

        <div v-if="shouldShowWalletCard && isXiaoPanel" class="stats-card" @click="$router.push('/wallet/deposit')">

          <div class="stats-icon">

            <IconWallet :size="32" />

          </div>

          <div class="stats-info">

            <div class="stats-value">{{ $t('wallet.deposit.title') }}</div>

            <div class="stats-label">{{ $t('wallet.balance.description') }}</div>

          </div>

          <div class="chevron-icon">

            <IconChevronRight :size="20" />

          </div>

        </div>



        <div v-if="shouldShowProfileCard" class="stats-card" @click="$router.push('/profile')">

          <div class="stats-icon">

            <IconUser :size="32" />

          </div>

          <div class="stats-info">

            <div class="stats-value">{{ $t('profile.title') }}</div>

            <div class="stats-label">{{ $t('more.manageProfile') }}</div>

          </div>

          <div class="chevron-icon">

            <IconChevronRight :size="20" />

          </div>

        </div>



        <!-- 自定义卡片 -->

        <template v-if="morePageConfig.enableCustomCards">

          <div

            v-for="card in morePageConfig.customCards"

            :key="card.id"

            class="stats-card"

            @click="handleCustomCardClick(card)"

          >

            <div class="stats-icon">

              <!-- 使用v-html渲染自定义SVG图标 -->

              <div v-if="card.svgIcon" class="custom-svg-icon" v-html="card.svgIcon"></div>

              <!-- 保留对旧版配置的兼容，如果有icon属性就使用动态组件 -->

              <component v-else-if="card.icon" :is="getIconComponent(card.icon)" :size="32" />

              <!-- 默认图标 -->

              <IconChevronRight v-else :size="32" />

            </div>

            <div class="stats-info">

              <div class="stats-value">{{ card.title }}</div>

              <div class="stats-label">{{ card.description }}</div>

            </div>

            <div class="chevron-icon">

              <IconChevronRight :size="20" />

            </div>

          </div>

        </template>

      </div>

    </div>

  </div>

</template>



<script setup name="MoreOptions">

import {

  IconFileText,

  IconShoppingCart,

  IconUser,

  IconDevices,

  IconSettings,

  IconTicket,

  IconLogout,

  IconBrandTelegram,

  IconBrandGithub,

  IconBrandDiscord,

  IconBrandTwitter,

  IconMailForward,

  IconChevronRight,

  IconServer,

  IconMessages,

  IconChartBar,

  IconWallet,

  IconUserPlus

} from '@tabler/icons-vue';

import { useI18n } from 'vue-i18n';

import { useRouter } from 'vue-router';

import { ref, onMounted, onUnmounted, computed } from 'vue';

import DomainAuthAlert from '@/components/common/DomainAuthAlert.vue';
import { useNavigationPreferences } from '@/composables/useNavigationPreferences';



import { TRAFFICLOG_CONFIG, isXiaoV2board, MORE_PAGE_CONFIG, NAVIGATION_CONFIG } from '@/utils/baseConfig';



const { t } = useI18n();

const router = useRouter();



const isSmallScreen = ref(false);







const showTrafficLog = ref(false);

const isXiaoPanel = isXiaoV2board();



const morePageConfig = MORE_PAGE_CONFIG;

const thirdNavItem = NAVIGATION_CONFIG?.thirdNavItem || 'invite';

const fourthNavItem = NAVIGATION_CONFIG?.fourthNavItem || '';
const { hiddenItems, loadNavigationPreferences } = useNavigationPreferences();
const isHiddenByTopNav = (key) => (key === thirdNavItem || key === fourthNavItem) && !hiddenItems.value.includes(key);
const shouldShowShopCard = computed(() => hiddenItems.value.includes('shop'));



const shouldShowInviteCard = computed(() => !isHiddenByTopNav('invite'));

const shouldShowDocsCard = computed(() => !isHiddenByTopNav('docs'));

const shouldShowNodesCard = computed(() => !isHiddenByTopNav('nodes'));

const shouldShowOrdersCard = computed(() => !isHiddenByTopNav('orders'));

const shouldShowTicketsCard = computed(() => !isHiddenByTopNav('tickets'));

const shouldShowTrafficCard = computed(() => !isHiddenByTopNav('traffic'));

const shouldShowWalletCard = computed(() => !isHiddenByTopNav('wallet'));

const shouldShowProfileCard = computed(() => !isHiddenByTopNav('profile'));


const checkScreenSize = () => {

  isSmallScreen.value = window.innerWidth < 905;

};



const navigateToTickets = () => {

  if (isSmallScreen.value) {

    router.push('/mobile/tickets');

  } else {

    router.push('/tickets');

  }

};



const handleCustomCardClick = (card) => {

  if (card.url) {

    const cardTitle = card.title || getLocaleTitle(card.id);

    console.log(`Clicked on card: ${cardTitle}`);



    if (card.openInNewTab) {

      window.open(card.url, '_blank');

    } else {

      window.location.href = card.url;

    }

  }

};



const getIconComponent = (iconName) => {

  const iconMap = {

    IconFileText, IconShoppingCart, IconUser, IconDevices,

    IconSettings, IconTicket, IconLogout, IconBrandTelegram,

    IconBrandGithub, IconBrandDiscord, IconBrandTwitter,

    IconMailForward, IconChevronRight, IconServer,

    IconMessages, IconChartBar, IconWallet

  };



  return iconMap[iconName] || IconChevronRight;
};



const getLocaleTitle = (key) => {

  return t(`more.${key}`, key);

};



onMounted(async () => {
  loadNavigationPreferences().catch(error => console.error('Failed to load navigation settings:', error));




  showTrafficLog.value = TRAFFICLOG_CONFIG.enableTrafficLog;



  checkScreenSize();

  window.addEventListener('resize', checkScreenSize);

});



onUnmounted(() => {

  window.removeEventListener('resize', checkScreenSize);

});

</script>



<style lang="scss" scoped>

.more-container {

  padding: 20px;

  display: flex;

  justify-content: center;



  .more-inner {

    width: 100%;

    max-width: 1200px;

  }



  .welcome-card {

    margin-bottom: 24px;

  }



  .dashboard-card {

    background-color: var(--card-bg-color);

    border-radius: 12px;

    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);

    padding: 20px;

    margin-bottom: 24px;

    border: 1px solid var(--border-color);

    transition: all 0.3s ease;



    &:hover {

      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);

      border-color: rgba(var(--theme-color-rgb), 0.3);

    }



    .card-header {

      display: flex;

      justify-content: space-between;

      align-items: center;

      margin-bottom: 15px;



      .card-title {

        font-size: 18px;

        font-weight: 600;

        margin: 0;

      }

    }

  }



  .stats-grid {

    display: grid;

    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));

    gap: 20px;

    margin-bottom: 24px;



    @media (min-width: 768px) {

      grid-template-columns: repeat(2, 1fr);

    }



    @media (min-width: 992px) {

      grid-template-columns: repeat(3, 1fr);

    }



    .stats-card {

      background-color: var(--card-bg-color);

      border-radius: 12px;

      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);

      padding: 20px;

      display: flex;

      align-items: center;

      border: 1px solid var(--border-color);

      transition: all 0.3s ease;

      cursor: pointer;



      &:hover {

        border-color: rgba(var(--theme-color-rgb), 0.3);

        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);

        transform: translateY(-2px);

      }



      .stats-icon {

        display: flex;

        align-items: center;

        justify-content: center;

        width: 60px;

        height: 60px;

        background-color: rgba(var(--theme-color-rgb), 0.1);

        border-radius: 12px;

        margin-right: 15px;

        color: var(--theme-color);

      }



      .stats-info {

        flex: 1;



        .stats-value {

          font-size: 18px;

          font-weight: 600;

          color: var(--text-color);

          margin-bottom: 5px;

        }



        .stats-label {

          font-size: 14px;

          color: var(--secondary-text-color);

        }

      }



      .chevron-icon {

        color: var(--theme-color);

        opacity: 0.5;

        transition: all 0.3s ease;

      }



      &:hover {

        .chevron-icon {

          transform: translateX(3px);

          opacity: 1;

        }

      }

    }

  }

}





@media (max-width: 768px) {

  .more-container {

    padding: 15px;

    padding-bottom: 80px;



    .stats-grid {

      grid-template-columns: 1fr;

    }

  }

}





.custom-svg-icon {

  width: 32px;

  height: 32px;

  display: flex;

  align-items: center;

  justify-content: center;



  :deep(svg) {

    width: 32px;

    height: 32px;

    color: currentColor;

  }

}

</style>
