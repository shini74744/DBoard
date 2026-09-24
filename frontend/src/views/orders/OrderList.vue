<template>
  <div class="orders-container">
    <div class="orders-inner">
      <!-- 欢迎卡片 -->
      <div class="dashboard-card welcome-card">
        <div class="card-header">
          <h2 class="card-title">{{ $t('orders.welcome.title') || '订单列表' }}</h2>
        </div>
        <div class="card-body">
          <p>{{ $t('orders.welcome.description') || '查看并管理您的订单' }}</p>
        </div>
      </div>

      <!-- 加载状态 -->
      <div v-if="loading" class="orders-loading">
        <LoadingSpinner />
        <p>{{ headerTexts.loading }}</p>
      </div>

      <!-- 错误提示 -->
      <div v-else-if="error" class="orders-error">
        <IconAlertTriangle :size="48" class="error-icon" />
        <p>{{ error }}</p>
        <button class="retry-button" @click="fetchOrders">{{ $t('common.retry') || '重试' }}</button>
      </div>

      <!-- 订单列表 -->
      <div v-else-if="orders.length > 0" class="orders-content">
        <!-- 分页控制 -->
        <div class="pagination-container" v-if="totalPages > 1">
          <div class="pagination">
            <button
              class="page-button prev"
              @click="prevPage"
              :disabled="currentPage === 1"
              :class="{ 'disabled': currentPage === 1 }"
            >
              <IconChevronLeft :size="16" />
            </button>

            <div class="page-info">
              {{ $t('common.page') || '页' }} {{ currentPage }} / {{ totalPages }}
            </div>

            <button
              class="page-button next"
              @click="nextPage"
              :disabled="currentPage === totalPages"
              :class="{ 'disabled': currentPage === totalPages }"
            >
              <IconChevronRight :size="16" />
            </button>
          </div>
        </div>

        <!-- 移动端卡片视图 -->
        <div class="order-cards"
             v-if="isMobileView"
             @touchstart="handleTouchStart"
             @touchmove="handleTouchMove"
             @touchend="handleTouchEnd">
          <!-- 滑动提示 -->
          <div class="swipe-hint" v-if="orders.length > pageSize">
            <IconArrowLeft :size="16" class="swipe-icon" />
            <span>{{ $t('common.swipeHint') || '左右滑动切换' }}</span>
            <IconArrowRight :size="16" class="swipe-icon" />
          </div>

          <transition-group :name="slideDirection === 'right' ? 'page-switch-right' : 'page-switch'">
            <div v-for="order in paginatedOrders" :key="order.trade_no" class="order-card">
              <div class="order-card-header">
                <div class="order-number">
                  <span class="label">{{ headerTexts.tradeNo }}:</span>
                  <span class="value">{{ order.trade_no }}</span>
                </div>
                <div class="status-wrapper">
                  <span class="status-badge" :class="getStatusClass(order.status)">
                    {{ order.record_kind === 'activity' ? '已记录' : getStatusText(order.status) }}
                  </span>
                </div>
              </div>

              <div class="order-card-body">
                <div class="order-activity-heading">{{ orderActionLabel(order) }} · {{ order.plan?.name || '套餐' }}</div>
                <span v-if="orderPriceSource(order)" class="order-price-source">{{ orderPriceSource(order) }}</span>
                <p v-if="orderRenewalTarget(order)" class="order-renewal-target">{{ orderRenewalTarget(order) }}</p>
                <p v-if="order.activity_summary" class="order-activity-summary">{{ order.activity_summary }}</p>
                <div class="info-row">
                  <span class="label">{{ headerTexts.createdAt }}:</span>
                  <span class="value">{{ formatDate(order.created_at) }}</span>
                </div>
                <div class="info-row">
                  <span class="label">{{ headerTexts.cycle }}:</span>
                  <span class="value">{{ formatCycle(order.period) }}</span>
                </div>
                <div class="info-row">
                  <span class="label">{{ headerTexts.totalAmount }}:</span>
                  <span class="value amount">{{ order.record_kind === 'activity' ? '—' : formatAmount(orderAmount(order)) }}</span>
                </div>
              </div>

              <div class="order-card-footer">
                <button
                  class="action-button view-button"
                  @click="order.record_kind === 'activity' ? selectedActivity = order : viewOrderDetail(order.trade_no)"
                >
                  <IconEye :size="16" />
                  <span>{{ headerTexts.viewDetail }}</span>
                </button>
                <button
                  class="action-button cancel-button"
                  v-if="order.record_kind !== 'activity' && order.status === 0"
                  @click="showCancelConfirm(order.trade_no)"
                  :disabled="order.status !== 0"
                  :class="{ 'disabled': order.status !== 0 }"
                >
                  <IconX :size="16" />
                  <span>{{ headerTexts.cancel }}</span>
                </button>
              </div>
            </div>
          </transition-group>
        </div>

        <!-- 桌面端表格视图 -->
        <div class="order-table-container"
             v-else
             @touchstart="handleTouchStart"
             @touchmove="handleTouchMove"
             @touchend="handleTouchEnd">
          <table class="order-table">
            <thead>
              <tr>
                <th width="22%">{{ headerTexts.tradeNo }}</th>
                <th width="16%">{{ headerTexts.createdAt }}</th>
                <th width="10%">{{ headerTexts.cycle }}</th>
                <th width="14%">{{ headerTexts.totalAmount }}</th>
                <th width="14%">{{ headerTexts.statusLabel }}</th>
                <th width="24%">{{ headerTexts.actions }}</th>
              </tr>
            </thead>
            <tbody>
              <transition-group :name="slideDirection === 'right' ? 'page-switch-right' : 'page-switch'">
                <tr v-for="order in paginatedOrders" :key="order.trade_no">
                  <td class="trade-no">
                    <div>{{ order.trade_no }}</div>
                    <strong class="order-activity-heading">{{ orderActionLabel(order) }} · {{ order.plan?.name || '套餐' }}</strong>
                    <span v-if="orderPriceSource(order)" class="order-price-source">{{ orderPriceSource(order) }}</span>
                <p v-if="orderRenewalTarget(order)" class="order-renewal-target">{{ orderRenewalTarget(order) }}</p>
                <p v-if="order.activity_summary" class="order-activity-summary">{{ order.activity_summary }}</p>
                  </td>
                  <td>{{ formatDate(order.created_at) }}</td>
                  <td>{{ formatCycle(order.period) }}</td>
                  <td class="amount">{{ order.record_kind === 'activity' ? '—' : formatAmount(orderAmount(order)) }}</td>
                  <td>
                    <span class="status-badge" :class="getStatusClass(order.status)">
                      {{ order.record_kind === 'activity' ? '已记录' : getStatusText(order.status) }}
                    </span>
                  </td>
                  <td class="actions">
                    <button
                      class="action-button view-button"
                      @click="order.record_kind === 'activity' ? selectedActivity = order : viewOrderDetail(order.trade_no)"
                    >
                      <IconEye :size="16" />
                      <span>{{ headerTexts.viewDetail }}</span>
                    </button>
                    <button
                      class="action-button cancel-button"
                  v-if="order.record_kind !== 'activity' && order.status === 0"
                      @click="showCancelConfirm(order.trade_no)"
                      :disabled="order.status !== 0"
                      :class="{ 'disabled': order.status !== 0 }"
                    >
                      <IconX :size="16" />
                      <span>{{ headerTexts.cancel }}</span>
                    </button>
                  </td>
                </tr>
              </transition-group>
            </tbody>
          </table>
        </div>
      </div>

      <!-- 空状态 -->
      <div v-else class="orders-empty">
        <IconShoppingCart :size="48" class="empty-icon" />
        <p>{{ headerTexts.noOrders }}</p>
        <button class="shop-button" @click="$router.push('/shop')">
          <IconShoppingCart :size="16" />
          <span>{{ headerTexts.goShopping }}</span>
        </button>
      </div>

      <div v-if="selectedActivity" class="modal-overlay" @click="selectedActivity = null">
        <section class="modal-content" role="dialog" aria-modal="true" aria-label="套餐操作记录" @click.stop>
          <div class="modal-header"><h3>套餐操作记录</h3><button class="modal-close" @click="selectedActivity = null" aria-label="关闭"><IconX :size="20" /></button></div>
          <div class="modal-body">
            <strong>{{ selectedActivity.plan?.name }}</strong>
            <p>{{ formatDate(selectedActivity.created_at) }} · 管理员手动</p>
            <p class="order-activity-summary">{{ selectedActivity.activity_summary }}</p>
          </div>
        </section>
      </div>
      <!-- 取消订单确认弹窗 -->
      <transition name="modal-fade">
        <div class="modal-overlay" v-if="showConfirmModal" @click="closeConfirmModal">
          <div class="modal-content" @click.stop>
            <div class="modal-header">
              <h3>{{ headerTexts.cancelConfirmTitle }}</h3>
              <button class="modal-close" @click="closeConfirmModal">
                <IconX :size="20" />
              </button>
            </div>
            <div class="modal-body">
              <p>{{ headerTexts.cancelConfirmText }}</p>
              <div class="trade-no-info">
                <span>{{ headerTexts.tradeNo }}:</span>
                <span class="trade-no">{{ currentTradeNo }}</span>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn-cancel" @click="closeConfirmModal">
                {{ $t('common.cancel') || '取消' }}
              </button>
              <button class="btn-confirm" @click="confirmCancelOrder" :disabled="canceling">
                <span v-if="canceling" class="loader"></span>
                <span>{{ $t('common.confirm') || '确认' }}</span>
              </button>
            </div>
          </div>
        </div>
      </transition>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, inject, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import LoadingSpinner from '@/components/common/LoadingSpinner.vue';
import {
  IconAlertTriangle,
  IconShoppingCart,
  IconEye,
  IconX,
  IconChevronLeft,
  IconChevronRight,
  IconArrowLeft,
  IconArrowRight
} from '@tabler/icons-vue';
import { fetchOrderList, cancelOrder } from '@/api/orderlist';
import { orderActionLabel, orderPriceSource, orderAmount, orderRenewalTarget } from '@/utils/orderPresentation';
import { getCommConfig } from '@/api/shop';

const { t, locale } = useI18n();
const router = useRouter();
const $toast = inject('$toast');

const loading = ref(true);
const error = ref('');
const orders = ref([]);
const selectedActivity = ref(null);
const showConfirmModal = ref(false);
const currentTradeNo = ref('');
const canceling = ref(false);
const isMobileView = ref(false);
const slideDirection = ref('left');
const currencySymbol = ref('¥');

const checkMobileView = () => {
  isMobileView.value = window.innerWidth < 768;
};

window.addEventListener('resize', checkMobileView);

const currentPage = ref(1);
const pageSize = 10;

const totalPages = computed(() => {
  return Math.ceil(orders.value.length / pageSize);
});

const paginatedOrders = computed(() => {
  const startIndex = (currentPage.value - 1) * pageSize;
  const endIndex = startIndex + pageSize;
  return orders.value.slice(startIndex, endIndex);
});

const touchStartX = ref(0);
const touchEndX = ref(0);
const minSwipeDistance = 50;

const handleTouchStart = (e) => {
  touchStartX.value = e.touches[0].clientX;
};

const handleTouchMove = (e) => {
  touchEndX.value = e.touches[0].clientX;
};

const handleTouchEnd = () => {
  const swipeDistance = touchEndX.value - touchStartX.value;

  if (Math.abs(swipeDistance) > minSwipeDistance) {
    if (swipeDistance > 0) {
      slideDirection.value = 'right';
      prevPage();
    } else {
      slideDirection.value = 'left';
      nextPage();
    }
  }

  touchStartX.value = 0;
  touchEndX.value = 0;
};

const nextPage = () => {
  if (currentPage.value < totalPages.value) {
    slideDirection.value = 'left';
    currentPage.value++;
  }
};

const prevPage = () => {
  if (currentPage.value > 1) {
    slideDirection.value = 'right';
    currentPage.value--;
  }
};

const fetchOrders = async () => {
  loading.value = true;
  error.value = '';

  try {
    const result = await fetchOrderList();

    if (result && result.data) {
      orders.value = result.data;
      currentPage.value = 1;
    } else {
      orders.value = [];
    }
  } catch (err) {
    console.error('Failed to fetch orders:', err);
    error.value = err && err.message ? err.message : t('common.networkError') || '网络错误';

    if ($toast) {
      $toast.error(error.value);
    }
  } finally {
    loading.value = false;
  }
};

const formatDate = (timestamp) => {
  if (!timestamp) return '--';
  const date = new Date(timestamp * 1000);
  return date.toLocaleString(locale.value === 'zh-CN' ? 'zh-CN' : 'en-US', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit'
  });
};

const formatCycle = (cycle) => {
  if (!cycle) return '--';

  const cycleMap = {
    'month_price': t('shop.plan.price_options.month') || '月付',
    'quarter_price': t('shop.plan.price_options.quarter') || '季度',
    'half_year_price': t('shop.plan.price_options.half_year') || '半年',
    'year_price': t('shop.plan.price_options.year') || '一年',
    'two_year_price': t('shop.plan.price_options.two_year') || '两年',
    'three_year_price': t('shop.plan.price_options.three_year') || '三年',
    'onetime_price': t('shop.plan.price_options.onetime') || '一次性',
    'reset_price': t('shop.plan.price_options.reset_price') || '重置流量包',
    'deposit': t('shop.plan.price_options.deposit') || '充值'
  };

  return cycleMap[cycle] || cycle;
};

const formatAmount = (amount) => {
  if (amount === null || amount === undefined) return '--';
  return currencySymbol.value + (amount / 100).toFixed(2);
};

const statusTextMap = computed(() => {
  return {
    0: t('orders.status.pending', '待支付'),
    1: t('orders.status.processing', '开通中'),
    2: t('orders.status.cancelled', '已取消'),
    3: t('orders.status.completed', '已完成'),
    4: t('orders.status.discounted', '已折抵'),
    unknown: t('orders.status.unknown', '未知状态')
  };
});

const getStatusText = (status) => {
  return statusTextMap.value[status] || `${statusTextMap.value.unknown} (${status})`;
};

const getStatusClass = (status) => {
  const statusClassMap = {
    0: 'status-pending',
    1: 'status-processing',
    2: 'status-cancelled',
    3: 'status-completed',
    4: 'status-discounted'
  };

  return statusClassMap[status] || 'status-unknown';
};

const viewOrderDetail = (tradeNo) => {
  router.push({
    path: '/payment',
    query: { trade_no: tradeNo, from: 'orders' }
  });
};

const showCancelConfirm = (tradeNo) => {
  currentTradeNo.value = tradeNo;
  showConfirmModal.value = true;
};

const closeConfirmModal = () => {
  showConfirmModal.value = false;
};

const confirmCancelOrder = async () => {
  if (!currentTradeNo.value || canceling.value) return;

  canceling.value = true;

  try {
    await cancelOrder(currentTradeNo.value);

    if ($toast && typeof $toast.success === 'function') {
      $toast.success(t('orders.cancelSuccess') || '订单已取消');
    }

    closeConfirmModal();

    fetchOrders();
  } catch (err) {
    console.error('取消订单失败, 错误对象:', err);

    let errorMessage = t('orders.cancelFailed') || '取消订单失败';

    try {
      if (err) {
        if (typeof err === 'string') {
          errorMessage = err;
        } else if (err instanceof Error) {
          errorMessage = err.message || errorMessage;
        } else if (typeof err.message === 'string') {
          errorMessage = err.message;
        }
      }
    } catch (e) {
      console.error('错误处理过程中出现问题:', e);
    }

    if ($toast && typeof $toast.error === 'function') {
      $toast.error(errorMessage);
    } else {
      alert(errorMessage);
    }

    closeConfirmModal();
  } finally {
    canceling.value = false;
  }
};

const headerTexts = computed(() => {
  return {
    tradeNo: t('orders.tradeNo', '订单号'),
    createdAt: t('orders.createdAt', '创建时间'),
    cycle: t('orders.cycle', '周期'),
    totalAmount: t('orders.totalAmount', '金额'),
    statusLabel: t('orders.statusLabel', '状态') || '状态',
    actions: t('orders.actions', '操作'),
    viewDetail: t('orders.viewDetail', '查看详情'),
    cancel: t('orders.cancel', '取消订单'),
    noOrders: t('orders.noOrders', '暂无订单'),
    goShopping: t('orders.goShopping', '去购买套餐'),
    loading: t('orders.loading', '正在加载订单...'),
    cancelConfirmTitle: t('orders.cancelConfirmTitle', '确认取消订单'),
    cancelConfirmText: t('orders.cancelConfirmText', '您确定要取消此订单吗？此操作无法撤销。')
  };
});

const fetchCurrencySymbol = async () => {
  try {
    const response = await getCommConfig();
    if (response && response.data && response.data.currency_symbol) {
      currencySymbol.value = response.data.currency_symbol;
    }
  } catch (error) {
    console.error('获取货币符号失败:', error);
  }
};

onMounted(() => {
  fetchCurrencySymbol();
  fetchOrders();
  checkMobileView();
});

watch(locale, () => {
  if (orders.value.length > 0) {
    orders.value = [...orders.value];
  }
});
</script>

<style lang="scss" scoped>
.orders-container {
  padding: 1.25rem;
  padding-bottom: calc(1.25rem + 64px);

  @media (min-width: 768px) {
    padding: 2rem;
    padding-bottom: 3rem;
  }
}

.orders-inner {
  max-width: 1200px;
  margin: 0 auto;
}


.dashboard-card {
  background-color: var(--card-bg);
  border-radius: 12px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
  padding: 20px;
  margin-bottom: 24px;
  border: 1px solid var(--border-color);
  transition: all 0.3s ease;
  position: relative;

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

  .card-body {
    p {
      color: var(--text-muted);
      margin: 0;
      line-height: 1.5;
    }
  }
}

.welcome-card {
  margin-bottom: 24px;
}


.orders-content {
  width: 100%;
  margin: 0 auto;
}

.order-table-container {
  overflow-x: auto;
  background-color: var(--card-bg);
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
  border: 1px solid var(--border-color);
  transition: all 0.3s ease;

  &:hover {
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    border-color: rgba(var(--theme-color-rgb), 0.3);
  }
}

.order-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed;

  th, td {
    padding: 1rem;
    text-align: left;
    word-break: break-all;
    overflow: hidden;
  }

  th {
    background-color: rgba(var(--theme-color-rgb), 0.05);
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--text-color);
    position: sticky;
    top: 0;
    z-index: 10;

    &:first-child {
      border-top-left-radius: 12px;
    }

    &:last-child {
      border-top-right-radius: 12px;
    }
  }

  tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: all 0.2s ease;

    &:hover {
      background-color: rgba(var(--theme-color-rgb), 0.05);
    }

    &:last-child {
      border-bottom: none;
    }
  }

  .trade-no {
    font-size: 0.9rem;
    color: var(--text-color);
  }

  .amount {
    font-weight: 600;
    color: #f44336;
  }

  .status-badge {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    border-radius: 5px;
    font-size: 0.85rem;
    font-weight: 500;

    &.status-pending {
      background-color: rgba(255, 152, 0, 0.1);
      color: #ff9800;
    }

    &.status-processing {
      background-color: rgba(33, 150, 243, 0.1);
      color: #2196f3;
    }

    &.status-cancelled {
      background-color: rgba(244, 67, 54, 0.1);
      color: #f44336;
    }

    &.status-completed {
      background-color: rgba(76, 175, 80, 0.1);
      color: #4caf50;
    }

    &.status-discounted {
      background-color: rgba(156, 39, 176, 0.1);
      color: #9c27b0;
    }

    &.status-unknown {
      background-color: rgba(158, 158, 158, 0.1);
      color: #9e9e9e;
    }
  }

  .actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;

    .action-button {
      display: flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.4rem 0.75rem;
      border-radius: 6px;
      font-size: 0.85rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.3s ease;
      border: none;

      &.view-button {
        background-color: rgba(var(--theme-color-rgb), 0.1);
        color: var(--theme-color);

        &:hover:not(.disabled) {
          background-color: rgba(var(--theme-color-rgb), 0.2);
          transform: translateY(-2px);
        }
      }

      &.cancel-button {
        background-color: rgba(244, 67, 54, 0.1);
        color: #f44336;

        &:hover:not(.disabled) {
          background-color: rgba(244, 67, 54, 0.2);
          transform: translateY(-2px);
        }
      }

      &.disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
      }
    }
  }
}


.orders-loading,
.orders-error,
.orders-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 3rem 1rem;
  text-align: center;

  p {
    margin-top: 1rem;
    color: var(--text-muted);
    font-size: 1.1rem;
  }

  .error-icon,
  .empty-icon {
    color: var(--text-muted);
    opacity: 0.7;
  }
}

.retry-button,
.shop-button {
  margin-top: 1.5rem;
  height: 40px;
  min-width: 120px;
  padding: 0 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border-radius: 8px;
  background-color: rgba(var(--theme-color-rgb), 0.85);
  color: white;
  font-weight: 500;
  font-size: 14px;
  border: 1px solid rgba(var(--theme-color-rgb), 0.3);
  box-shadow: 0 8px 20px rgba(var(--theme-color-rgb), 0.25);
  cursor: pointer;
  transition: all 0.3s ease;
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);

  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(var(--theme-color-rgb), 0.35);
    background-color: rgba(var(--theme-color-rgb), 0.95);
  }

  &:active {
    transform: translateY(0);
    box-shadow: 0 5px 15px rgba(var(--theme-color-rgb), 0.3);
  }
}


.modal-fade-enter-active {
  animation: fade-in 0.2s ease-out;
}

.modal-fade-leave-active {
  animation: fade-out 0.2s ease-in;
}

@keyframes fade-in {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes fade-out {
  from { opacity: 1; }
  to { opacity: 0; }
}

.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 1000;
  backdrop-filter: blur(5px);
  -webkit-backdrop-filter: blur(5px);
}

.modal-content {
  background-color: rgba(var(--card-background-rgb, 255, 255, 255), 1);
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
  width: 90%;
  max-width: 480px;
  overflow: hidden;
  display: flex;
  flex-direction: column;

  @media (prefers-color-scheme: dark) {
    background-color: rgba(var(--card-background-rgb, 30, 30, 30), 1);
  }
}

.modal-header {
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--border-color);
  display: flex;
  justify-content: space-between;
  align-items: center;

  h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--text-color);
  }

  .modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 0.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.3s ease;

    &:hover {
      background-color: rgba(var(--theme-color-rgb), 0.1);
      color: var(--theme-color);
    }
  }
}

.modal-body {
  padding: 1.5rem;

  p {
    margin: 0 0 1.5rem;
    color: var(--text-color);
  }

  .trade-no-info {
    background-color: rgba(var(--theme-color-rgb), 0.05);
    padding: 1rem;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 0.5rem;

    .trade-no {
      font-weight: 600;
      color: var(--theme-color);
    }
  }
}

.modal-footer {
  padding: 1rem 1.5rem;
  border-top: 1px solid var(--border-color);
  display: flex;
  justify-content: flex-end;
  gap: 1rem;

  button {
    padding: 0.6rem 1.25rem;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;

    &.btn-cancel {
      background-color: transparent;
      border: 1px solid var(--border-color);
      color: var(--text-color);

      &:hover {
        background-color: rgba(0, 0, 0, 0.05);
      }
    }

    &.btn-confirm {
      background-color: #f44336;
      border: none;
      color: white;
      display: flex;
      align-items: center;
      gap: 0.5rem;

      &:hover {
        background-color: #d32f2f;
      }

      &:disabled {
        opacity: 0.7;
        cursor: not-allowed;
      }

      .loader {
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        border-top-color: white;
        animation: spin 1s linear infinite;
      }
    }
  }
}

@keyframes spin {
  to { transform: rotate(360deg); }
}


@media (max-width: 768px) {
  .order-table {
    th, td {
      padding: 0.75rem 0.5rem;
    }

    .actions {
      .action-button {
        padding: 0.3rem 0.5rem;
        font-size: 0.75rem;

        span {
          display: none;
        }
      }
    }
  }
}


.pagination-container {
  margin-bottom: 1rem;
  display: flex;
  justify-content: flex-end;
}

.pagination {
  display: flex;
  align-items: center;
  background-color: var(--card-bg);
  border-radius: 8px;
  padding: 0.5rem 0.75rem;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
  border: 1px solid var(--border-color);

  .page-button {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    background-color: transparent;
    border: none;
    color: var(--text-color);
    cursor: pointer;
    transition: all 0.3s ease;

    &:hover:not(.disabled) {
      background-color: rgba(var(--theme-color-rgb), 0.1);
      color: var(--theme-color);
    }

    &.disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
  }

  .page-info {
    margin: 0 1rem;
    font-size: 0.9rem;
    color: var(--text-color);
  }
}


.page-switch-enter-active,
.page-switch-leave-active {
  transition: all 0.3s ease;
}

.page-switch-enter-from {
  opacity: 0;
  transform: translateX(30px);
}

.page-switch-leave-to {
  opacity: 0;
  transform: translateX(-30px);
}

.page-switch-right-enter-active,
.page-switch-right-leave-active {
  transition: all 0.3s ease;
}

.page-switch-right-enter-from {
  opacity: 0;
  transform: translateX(-30px);
}

.page-switch-right-leave-to {
  opacity: 0;
  transform: translateX(30px);
}


@media (max-width: 768px) {
  .order-table-container {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    cursor: grab;

    &:active {
      cursor: grabbing;
    }
  }
}


.order-cards {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.order-card {
  background-color: var(--card-bg);
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
  border: 1px solid var(--border-color);
  transition: all 0.3s ease;
  overflow: hidden;

  &:hover {
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    border-color: rgba(var(--theme-color-rgb), 0.3);
  }
}

.order-card-header {
  padding: 1rem;
  border-bottom: 1px solid var(--border-color);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background-color: rgba(var(--theme-color-rgb), 0.03);

  .order-number {
    display: flex;
    flex-direction: column;

    .label {
      font-size: 0.8rem;
      color: var(--text-muted);
      margin-bottom: 0.25rem;
    }

    .value {
      font-size: 0.9rem;
      font-weight: 500;
      word-break: break-all;
    }
  }
}

.order-card-body {
  padding: 0.75rem 1rem;

  .info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(var(--border-color-rgb), 0.5);

    &:last-child {
      border-bottom: none;
    }

    .label {
      color: var(--text-muted);
      font-size: 0.9rem;
    }

    .value {
      font-size: 0.9rem;
      text-align: right;

      &.amount {
        font-weight: 600;
        color: #f44336;
      }
    }
  }
}

.order-card-footer {
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--border-color);
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  background-color: rgba(var(--theme-color-rgb), 0.02);

  .action-button {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;

    &.view-button {
      background-color: rgba(var(--theme-color-rgb), 0.1);
      color: var(--theme-color);

      &:hover:not(.disabled) {
        background-color: rgba(var(--theme-color-rgb), 0.2);
        transform: translateY(-2px);
      }
    }

    &.cancel-button {
      background-color: rgba(244, 67, 54, 0.1);
      color: #f44336;

      &:hover:not(.disabled) {
        background-color: rgba(244, 67, 54, 0.2);
        transform: translateY(-2px);
      }
    }

    &.disabled {
      opacity: 0.5;
      cursor: not-allowed;
      pointer-events: none;
    }
  }
}


.swipe-hint {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.5rem;
  margin-bottom: 0.75rem;
  background-color: rgba(var(--theme-color-rgb), 0.05);
  border-radius: 8px;
  font-size: 0.85rem;
  color: var(--text-muted);

  .swipe-icon {
    color: var(--theme-color);
    animation: swipe-animation 1.5s infinite alternate;
  }

  @keyframes swipe-animation {
    0% {
      transform: translateX(0);
    }
    100% {
      transform: translateX(3px);
    }
  }
}
</style>

<style scoped>
.order-activity-heading{display:block;font-size:13px;line-height:1.6;margin-top:5px;font-weight:600;color:var(--text-color)}
.order-activity-summary{margin:6px 0 0;font-size:13px;line-height:1.65;white-space:normal;overflow-wrap:anywhere;color:var(--secondary-text-color)}
.order-table .trade-no{min-width:230px;max-width:450px;white-space:normal;overflow-wrap:anywhere}
.order-card .order-activity-heading{margin-bottom:5px}
</style>

<style scoped>
.order-renewal-target { margin: 7px 0 0; font-size: 11px; line-height: 1.6; white-space: normal; color: var(--secondary-text-color); }
.order-price-source { display: inline-block; margin-top: 6px; padding: 2px 8px; border-radius: 6px; font-size: 11px; line-height: 1.6; color: var(--theme-color, #355cc2); background: rgba(var(--theme-color-rgb, 53,92,194), .08); }
</style>
