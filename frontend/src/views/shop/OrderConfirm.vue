<template>

  <div class="order-confirm-container">

    <div class="order-confirm-inner">

      <!-- 页面标题 -->

      <div class="dashboard-card welcome-card">

        <div class="card-header">

          <h2 class="card-title">{{ $t('order.title') }}</h2>

        </div>

        <div class="card-body">

          <p>{{ $t('order.description') }}</p>


        </div>

      </div>





      <!-- 内容主体 -->

      <div class="content-wrapper">

        <!-- 左侧内容：套餐信息和周期选择 -->

        <div class="left-column">

          <!-- 套餐信息卡片 - 骨架屏 -->

          <div class="plan-card glassmorphism" v-if="loading.plan">

            <div class="skeleton-card">

              <div class="skeleton-header"></div>

              <div class="skeleton-body">

                <div class="skeleton-title"></div>

                <div class="skeleton-features">

                  <div class="skeleton-feature" v-for="j in 4" :key="'feature-'+j"></div>

                </div>

              </div>

            </div>

          </div>



          <!-- 套餐信息卡片 - 实际内容 -->

          <div class="plan-card glassmorphism" v-else-if="plan">

            <div class="card-header">

              <h3 class="card-title">{{ plan.name }}</h3>

              <div class="card-badge" :class="getStockBadgeClass(plan)">

                <IconBox :size="16" class="badge-icon" />

                <span>{{ getPlanStockText(plan) }}</span>

              </div>

            </div>



            <div class="card-body">

              <!-- 套餐详细信息 -->

              <div class="plan-features">

                <!-- JSON格式内容 -->

                <template v-if="isJsonContent(plan.content)">

                  <div

                    class="feature-item"

                    v-for="(feature, index) in parseJsonContent(plan.content)"

                    :key="index"

                  >

                    <IconCheck v-if="feature.support" class="feature-icon enabled" />

                    <IconX v-else class="feature-icon disabled" />

                    <span :class="{ 'disabled-text': !feature.support }">{{ feature.feature }}</span>

                  </div>

                </template>



                <!-- HTML格式内容 -->

                <PlanDescription v-else :content="plan.content" />

              </div>

            </div>

          </div>



          <!-- 周期选择 -->

          <div class="section-wrapper" v-if="!loading.plan">

            <div class="section-title">

              <span>{{ $t('order.select_period') }}</span>

            </div>



            <div class="period-selection">

              <!-- 周期卡片 -->

              <div class="period-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; width: 100%;">

                <div

                  v-for="(price, type) in availablePrices"

                  :key="type"

                  class="period-card"

                  :class="{ 'active': selectedPriceType === type }"

                  @click="selectPriceType(type)"

                >

                  <div class="period-card-inner">

                    <div class="period-type">{{ $t(`shop.plan.price_options.${getPriceTypeKey(type)}`) }}</div>

                    <div class="period-price">

                      <span class="currency">{{ currencySymbol }}</span>

                      <span class="amount">{{ (price / 100).toFixed(2) }}</span>

                    </div>

                  </div>

                </div>

              </div>

            </div>

          </div>



          <!-- 周期选择骨架屏 -->

          <div class="section-wrapper" v-else>

            <div class="section-title">

              <span>{{ $t('order.select_period') }}</span>

            </div>



            <div class="period-selection">

              <div class="skeleton-period-cards">

                <div class="skeleton-period-card" v-for="i in 2" :key="'skeleton-period-'+i"></div>

              </div>

            </div>

          </div>

        </div>



        <!-- 右侧内容：订单信息 -->

        <div class="right-column">

          <!-- 优惠码 -->

          <div class="section-wrapper">

            <div class="section-title">

              <span>{{ $t('order.coupon') }}</span>

            </div>



            <div class="coupon-input">

              <input

                type="text"

                v-model="couponCode"

                :disabled="loading.plan || couponApplied"

                :placeholder="$t('order.enter_coupon')"

                class="coupon-field"

                :class="{ 'applied': couponApplied }"

              />

              <button

                class="btn-verify"

                @click="verifyCoupon"

                :disabled="!couponCode || verifying || loading.plan || couponApplied"

                :class="{ 'applied': couponApplied }"

              >

                <IconDiscount2 v-if="!verifying && !couponApplied" />

                <IconCheck v-else-if="couponApplied" />

                <span v-else-if="verifying" class="loader"></span>

                <span>{{ couponApplied ? $t('order.coupon_applied') : $t('order.verify_coupon') }}</span>

              </button>

              <button

                v-if="couponApplied"

                class="btn-remove-coupon"

                @click="removeCoupon"

              >

                <IconX :size="16" />

                <span>{{ $t('order.remove_coupon') }}</span>

              </button>

            </div>

          </div>



          <!-- 订单摘要 -->

          <div class="section-wrapper order-summary-section">

            <div class="section-title">

              <span>{{ $t('order.order_summary') }}</span>

            </div>



            <div class="order-summary glassmorphism">

              <!-- 骨架屏 -->

              <div v-if="loading.plan">

                <div class="summary-row skeleton">

                  <div class="summary-label skeleton-text"></div>

                  <div class="summary-value skeleton-text"></div>

                </div>

                <div class="summary-divider"></div>

                <div class="summary-row skeleton total">

                  <div class="summary-label skeleton-text"></div>

                  <div class="summary-value skeleton-text"></div>

                </div>

              </div>



              <!-- 实际内容 -->

              <div v-else>
                <div class="summary-row"><div class="summary-label">价格依据</div><div class="summary-value">{{ priceSourceLabel }}</div></div>
                <div class="summary-row"><div class="summary-label">购买方式</div><div class="summary-value">{{ purchaseActionLabel }}</div></div>
                <div v-if="purchaseAction === 'renew'" class="summary-row"><div class="summary-label">续费套餐</div><div class="summary-value purchase-target-name">{{ renewalTargetName }}</div></div>
                <div v-if="quoteError" class="purchase-quote-error" role="alert">{{ quoteError }} <button type="button" @click="retryPurchaseData" :disabled="loading.plan">重新读取价格</button></div>

                <div class="summary-row">

                  <div class="summary-label">{{ $t('order.subtotal') }}</div>

                  <div class="summary-value">{{ quoteError ? '—' : currencySymbol + (originalPrice / 100).toFixed(2) }}</div>

                </div>



                <div class="summary-row" v-if="discountAmount > 0">

                  <div class="summary-label">{{ $t('order.discount') }}

                    <span v-if="couponInfo" class="coupon-name">({{ couponInfo.name }})</span>

                  </div>

                  <div class="summary-value discount">-{{ currencySymbol }}{{ (discountAmount / 100).toFixed(2) }}</div>

                </div>



                <div class="summary-divider"></div>



                <div class="summary-row total">

                  <div class="summary-label">{{ $t('order.total') }}</div>

                  <div class="summary-value">{{ quoteError ? '—' : currencySymbol + (finalPrice / 100).toFixed(2) }}</div>

                </div>

              </div>

            </div>

          </div>



          <SubscriptionPurchaseChoice
            :action="purchaseAction" :target-id="selectedSubscriptionId" :matches="matchingSubscriptions"
            :owned-count="subscriptions.length" :owned-packages="subscriptions" :loading="loading.packages" :busy="loading.submitting || verifying"
            :error="packageError || quoteError" :hint="renewalHint" :price-hint="priceSourceHint"
            @action="changePurchaseAction" @target="changeRenewalTarget" @retry="retryPurchaseData"
          />

          <!-- 操作按钮 -->

          <div class="action-buttons">

            <button

              class="btn-back"

              @click="goBack"

              :disabled="loading.plan"

            >

              <IconArrowLeft :size="18" />

              <span>{{ $t('order.back_to_shop') }}</span>

            </button>



            <button

              class="btn-order"

              @click="submitOrder"

              :disabled="!canSubmit"

            >

              <IconShoppingCart v-if="!loading.submitting" :size="18" />

              <span v-else class="loader"></span>

              <span>{{ purchaseAction === 'renew' ? '提交续费订单' : '提交新开订单' }}</span>

            </button>

          </div>

        </div>

      </div>

    </div>

    <!-- 二次确认弹窗 -->
    <CommonDialog
      :show-dialog="showConfirmDialog"
      :title="$t('order.title')"
      :content="purchaseConfirmContent"
      :confirm-button-text="purchaseAction === 'renew' ? '确认续费' : '确认新开'"
      :show-close-icon="true"
      :show-cancel-button="true"
      :show-confirm-button="true"
      :cancel-button-i18n-key="'common.cancel'"
      :confirm-button-i18n-key="'order.confirm_purchase'"
      @close="handleConfirmDialogClose"
      @confirm="handleConfirmDialogConfirm"
    />
  </div>

</template>



<script>

import { ref, reactive, onMounted, computed } from 'vue';

import { useI18n } from 'vue-i18n';

import { useToast } from '@/composables/useToast';

import { useRoute, useRouter } from 'vue-router';

import { getCommConfig, fetchPlanById, verifyCoupon as checkCoupon, submitOrder as createOrder } from '@/api/shop';

import { getUserInfo, getSubscribe } from '@/api/dashboard';
import SubscriptionPurchaseChoice from '@/components/shop/SubscriptionPurchaseChoice.vue';

import { isXboard, ORDER_CONFIG, SHOP_CONFIG } from '@/utils/baseConfig';

import CommonDialog from '@/components/popup/CommonDialog.vue';
import PlanDescription from '@/components/shop/PlanDescription.vue';
import { isAvailablePlanPrice } from '@/utils/planPrices';
import { getPlanStock } from '@/utils/planStock';

import {

  IconCheck,

  IconX,

  IconBox,

  IconShoppingCart,

  IconDiscount2,

  IconArrowLeft,

  IconAlertTriangle

} from '@tabler/icons-vue';



export default {

  name: 'OrderConfirm',

  components: {
    PlanDescription,
    SubscriptionPurchaseChoice,

    IconCheck,

    IconX,

    IconBox,

    IconShoppingCart,

    IconDiscount2,

    IconArrowLeft,

    IconAlertTriangle,

    CommonDialog

  },

  setup() {

    const { t } = useI18n();

    const { showToast } = useToast();

    const route = useRoute();

    const router = useRouter();



    const loading = reactive({

      plan: true,

      userInfo: true,
      packages: true,

      submitting: false

    });



    const plan = ref(null);

    const userInfo = ref(null);
    const subscriptions = ref([]);
    const packagesLoaded = ref(false);
    const packageError = ref('');
    const quoteError = ref('');
    const quoteReady = ref(false);
    const enteredFromPackage = route.query.subscription_action === 'renew' && Number(route.query.subscription_user_id) > 0;
    const purchaseSource = computed(() => enteredFromPackage && purchaseAction.value === 'renew' ? 'package' : 'shop');
    const priceSourceLabel = computed(() => purchaseSource.value === 'package' ? '原套餐续费价' : '商店标价');
    const priceSourceHint = computed(() => purchaseSource.value === 'package'
      ? '按所选套餐的续费价格计价；管理员为该周期设置过价格时，沿用该价格。'
      : '本次从商店购买，新开或续费均按商店标价计价。');
    const purchaseAction = ref(route.query.subscription_action === 'renew' ? 'renew' : 'add');
    const selectedSubscriptionId = ref(Number(route.query.subscription_user_id) || 0);
    const matchingSubscriptions = computed(() => subscriptions.value.filter(item => Number(item.plan_id) === Number(route.query.id)));
    const renewalTarget = computed(() => matchingSubscriptions.value.find(item => Number(item.id) === selectedSubscriptionId.value));
    const renewalTargetName = computed(() => {
      if (!renewalTarget.value) return '请选择续费套餐';
      const index = matchingSubscriptions.value.findIndex(item => Number(item.id) === selectedSubscriptionId.value);
      return (renewalTarget.value.plan_name || '当前套餐') + (matchingSubscriptions.value.length > 1 ? ` · 第 ${index + 1} 份` : '');
    });
    const purchaseActionLabel = computed(() => purchaseAction.value === 'renew' ? '续费同款' : '新开一份');
    const renewalHint = computed(() => selectedPriceType.value === 'onetime_price'
      ? '按一次性流量套餐续购：该份套餐流量会重置为本次购买的配额。'
      : '未到期的套餐从原到期时间顺延，已到期的套餐从付款后重新计时。');
    const canSubmit = computed(() => packagesLoaded.value && quoteReady.value && !loading.plan && !loading.submitting && !verifying.value
      && !!plan.value && !!selectedPriceType.value && (purchaseAction.value === 'add' || !!renewalTarget.value));
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const purchaseConfirmContent = computed(() => `<p>本次操作：<strong>${escapeHtml(purchaseActionLabel.value)}</strong></p>`
      + `<p>套餐：${escapeHtml(purchaseAction.value === 'renew' ? renewalTargetName.value : plan.value?.name)}</p>`
      + `<p>周期：${escapeHtml(t('shop.plan.price_options.' + getPriceTypeKey(selectedPriceType.value)))}</p>`
      + `<p>价格依据：${escapeHtml(priceSourceLabel.value)}</p>`
      + `<p>订单金额：${escapeHtml(currencySymbol.value)}${(finalPrice.value / 100).toFixed(2)}</p>`
      + `<p>${purchaseAction.value === 'add' ? '将独立开通一份套餐，可与已有套餐同时使用。' : escapeHtml(renewalHint.value)}</p>`);
    let quoteSequence = 0;


    const currency = ref('CNY');

    const currencySymbol = ref('¥');



    const selectedPriceType = ref('');



    const couponCode = ref('');

    const couponApplied = ref(false);

    const verifying = ref(false);

    const couponInfo = ref(null);

    const discountPercent = ref(0);



    // 新增：控制二次确认弹窗的变量

    const showConfirmDialog = ref(false);



    const originalPrice = computed(() => {

      if (!plan.value || !selectedPriceType.value) return 0;

      return plan.value[selectedPriceType.value] || 0;

    });



    const discountAmount = computed(() => {

      if (!couponApplied.value || !couponInfo.value) return 0;



      if (couponInfo.value.type === 1) {

        return couponInfo.value.value;

      } else if (couponInfo.value.type === 2 && discountPercent.value > 0 && originalPrice.value > 0) {

        return Math.round(originalPrice.value * (discountPercent.value / 100));

      }



      return 0;

    });



    const finalPrice = computed(() => {

      return Math.max(0, originalPrice.value - discountAmount.value);

    });



    const availablePrices = computed(() => {

      if (!plan.value) return {};



      const prices = {};

      const priceTypes = ['month_price', 'quarter_price', 'half_year_price', 'year_price', 'two_year_price', 'three_year_price', 'onetime_price'];



      priceTypes.forEach(type => {

        if (isAvailablePlanPrice(plan.value[type])) {

          prices[type] = plan.value[type];

        }

      });



      return prices;

    });



    const bestValuePeriod = computed(() => {

      if (!plan.value) return '';



      const valueWeight = {

        month_price: 1,

        quarter_price: 3,

        half_year_price: 6,

        year_price: 12,

        two_year_price: 24,

        three_year_price: 36,

        onetime_price: 12
      };



      let bestPeriod = '';

      let bestValue = 0;



      Object.entries(availablePrices.value).forEach(([type, price]) => {

        if (price <= 0) return;



        const monthlyValue = valueWeight[type] / price;



        if (monthlyValue > bestValue) {

          bestValue = monthlyValue;

          bestPeriod = type;

        }

      });



      return bestPeriod;

    });



    const getPlanStockText = plan => {
      const stock = getPlanStock(plan, SHOP_CONFIG.lowStockThreshold);
      return t(stock.textKey, { count: stock.count });
    };
    const getStockBadgeClass = plan => getPlanStock(plan, SHOP_CONFIG.lowStockThreshold).className;

    const getPriceTypeKey = (type) => {

      const keyMap = {

        month_price: 'month',

        quarter_price: 'quarter',

        half_year_price: 'half_year',

        year_price: 'year',

        two_year_price: 'two_year',

        three_year_price: 'three_year',

        onetime_price: 'onetime'

      };

      return keyMap[type] || '';

    };



    const isJsonContent = (content) => {

      if (!content) return false;

      try {

        const parsed = JSON.parse(content);

        return Array.isArray(parsed) && parsed.length > 0 && Object.prototype.hasOwnProperty.call(parsed[0], 'feature');

      } catch (e) {

        return false;

      }

    };



    const parseJsonContent = (content) => {

      try {

        return JSON.parse(content);

      } catch (e) {

        return [];

      }

    };



    const selectPriceType = (type) => {

      selectedPriceType.value = type;

    };



    const verifyCoupon = async () => {

      if (!couponCode.value || verifying.value || !quoteReady.value) return;



      verifying.value = true;



      try {

        const response = await checkCoupon(couponCode.value, plan.value.id);



        if (response.data) {

          couponApplied.value = true;

          couponInfo.value = response.data;



          if (response.message) {

            showToast(response.message, 'success');

          }



          if (response.data.type === 1) {

            discountAmount.value = response.data.value;

            discountPercent.value = 0;
            if (!response.message) {

              showToast(t('order.coupon_success_fixed', {

                code: couponCode.value,

                amount: (response.data.value / 100).toFixed(2)

              }), 'success');

            }

          } else if (response.data.type === 2) {

            discountPercent.value = couponInfo.value.value;



            if (isXboard()) {

              const calculatedDiscountAmount = Math.round(originalPrice.value * (discountPercent.value / 100));

              couponInfo.value.calculatedDiscountAmount = calculatedDiscountAmount;

            }



            if (!response.message) {

              showToast(t('order.coupon_success_percent', {

                code: couponCode.value,

                percent: couponInfo.value.value

              }), 'success');

            }

          } else if (!response.message) {

            showToast(t('order.coupon_success', { code: couponCode.value }), 'success');

          }

        } else {

          couponApplied.value = false;

          discountPercent.value = 0;

          discountAmount.value = 0;

          couponInfo.value = null;

          showToast(response.message || t('order.coupon_invalid'), 'error');

        }

      } catch (error) {

        console.error('验证优惠码失败:', error);

        couponApplied.value = false;

        discountPercent.value = 0;

        discountAmount.value = 0;

        couponInfo.value = null;

        showToast(error.response?.message || error.message || t('order.coupon_invalid'), 'error');

      } finally {

        verifying.value = false;

      }

    };



    // 修改后的submitOrder方法

    const submitOrder = async () => {

      if (!canSubmit.value) return;



      // 检查是否需要二次确认

      if (ORDER_CONFIG.confirmOrder) {

        showConfirmDialog.value = true;

        return; // 等待用户确认

      }



      // 如果不需要二次确认，直接执行订单提交

      await executeOrderSubmission();

    };



    // 实际的订单提交逻辑

    const executeOrderSubmission = async () => {
      if (!canSubmit.value) return;

      loading.submitting = true;



      try {

        const orderData = {

          plan_id: Number(plan.value.id),

          period: selectedPriceType.value

        };

        orderData.subscription_action = purchaseAction.value;
        orderData.purchase_source = purchaseSource.value;
        if (purchaseAction.value === 'renew') orderData.subscription_user_id = selectedSubscriptionId.value;



        if (couponApplied.value && couponCode.value && couponInfo.value) {

          orderData.coupon_code = couponCode.value;

        }



        const response = await createOrder(orderData);



        if (response.data) {

          showToast(response.message || t('order.order_success'), 'success');



          router.push({

            path: '/payment',

            query: {

              trade_no: response.data

            }

          });

        } else {

          showToast(response.message || t('order.order_failed'), 'error');

        }

      } catch (error) {

        console.error('提交订单失败:', error);

        showToast(error.response?.message || error.message || t('order.order_failed'), 'error');

      } finally {

        loading.submitting = false;

      }

    };



    // 处理确认弹窗的关闭事件

    const handleConfirmDialogClose = () => {

      showConfirmDialog.value = false;

    };



    // 处理确认弹窗的确认事件

    const handleConfirmDialogConfirm = () => {

      showConfirmDialog.value = false;

      executeOrderSubmission(); // 执行订单提交

    };



    const goBack = () => {

      router.push('/shop');

    };



    const fetchPlanData = async () => {
      const sequence = ++quoteSequence;
      quoteReady.value = false;
      quoteError.value = '';
      if (!packagesLoaded.value) return;
      if (purchaseAction.value === 'renew' && !renewalTarget.value) {
        loading.plan = false;
        quoteError.value = '请选择要续费的同款套餐；原选择可能已经取消或变更。';
        return;
      }
      loading.plan = true;
      selectedPriceType.value = '';
      couponApplied.value = false; couponInfo.value = null; discountPercent.value = 0;
      try {
        const response = await fetchPlanById(route.query.id, purchaseAction.value === 'renew' ? selectedSubscriptionId.value : null, purchaseAction.value, purchaseSource.value);
        if (sequence !== quoteSequence) return;
        if (!response.data || Number(response.data.id) !== Number(route.query.id)) throw new Error(response.message || '暂时无法获取套餐价格');
        plan.value = response.data;
        const preferred = purchaseAction.value === 'renew' ? plan.value.billing_period : route.query.period;
        selectedPriceType.value = Object.hasOwn(availablePrices.value, preferred) ? preferred : Object.keys(availablePrices.value)[0] || '';
        if (!selectedPriceType.value) throw new Error('当前没有可购买的计费周期，请选择其他套餐。');
        quoteReady.value = true;
      } catch (error) {
        if (sequence !== quoteSequence) return;
        quoteError.value = error.response?.data?.message || error.response?.message || error.message || '读取价格失败，请重试';
      } finally {
        if (sequence === quoteSequence) loading.plan = false;
      }
    };
    const fetchSubscriptions = async () => {
      loading.packages = true; packagesLoaded.value = false; packageError.value = '';
      try {
        const response = await getSubscribe();
        if (!Array.isArray(response.data?.subscriptions)) throw new Error('未能读取已持有套餐，请重试');
        subscriptions.value = response.data.subscriptions.filter(item => item.plan_id != null);
        packagesLoaded.value = true;
      } catch (error) {
        packageError.value = '读取已持有套餐失败，请重新读取后再下单。';
      } finally { loading.packages = false; }
    };
    const changePurchaseAction = action => {
      if (loading.submitting || verifying.value || action === purchaseAction.value) return;
      if (action === 'renew' && !matchingSubscriptions.value.length) return;
      purchaseAction.value = action;
      if (action === 'renew' && !renewalTarget.value) selectedSubscriptionId.value = Number(matchingSubscriptions.value[0].id);
      fetchPlanData();
    };
    const changeRenewalTarget = id => {
      if (loading.submitting || verifying.value) return;
      selectedSubscriptionId.value = id;
      fetchPlanData();
    };
    const retryPurchaseData = async () => {
      if (!packagesLoaded.value) await fetchSubscriptions();
      await fetchPlanData();
    };

    const fetchUserInfo = async () => {

      loading.userInfo = true;

      try {

        const response = await getUserInfo();

        if (response.data) {

          userInfo.value = response.data;

        } else if (response.message) {

          showToast(response.message, 'warning');

        }

      } catch (error) {

        console.error('获取用户信息失败:', error);

        showToast(error.response?.message || error.message || t('dashboard.userinfo_error'), 'error');

      } finally {

        loading.userInfo = false;

      }

    };



    const fetchConfig = async () => {

      try {

        const response = await getCommConfig();

        if (response.data) {

          currency.value = response.data.currency || 'CNY';

          currencySymbol.value = response.data.currency_symbol || '¥';

        } else if (response.message) {

          showToast(response.message, 'warning');

        }

      } catch (error) {

        console.error('获取系统配置失败:', error);

        showToast(error.response?.message || error.message || t('shop.config_error'), 'error');

      }

    };



    const removeCoupon = () => {

      couponCode.value = '';

      couponApplied.value = false;

      discountPercent.value = 0;

      couponInfo.value = null;

      showToast(t('order.coupon_removed'), 'info');

    };



    onMounted(async () => {
      await Promise.all([fetchSubscriptions(), fetchUserInfo(), fetchConfig()]);
      await fetchPlanData();
    });

    return {

      subscriptions, matchingSubscriptions, purchaseAction, selectedSubscriptionId, packageError, quoteError,
      renewalTargetName, purchaseActionLabel, renewalHint, priceSourceLabel, priceSourceHint, purchaseConfirmContent, canSubmit,
      changePurchaseAction, changeRenewalTarget, retryPurchaseData,

      plan,

      userInfo,

      loading,

      currency,

      currencySymbol,

      selectedPriceType,

      couponCode,

      couponApplied,

      verifying,

      couponInfo,

      originalPrice,

      discountAmount,

      finalPrice,


      availablePrices,

      bestValuePeriod,

      getPriceTypeKey,

      isJsonContent,

      parseJsonContent,

      selectPriceType,

      verifyCoupon,

      submitOrder,

      goBack,

      getPlanStockText,

      getStockBadgeClass,

      removeCoupon,


      // 新增的返回值
      ORDER_CONFIG,
      showConfirmDialog,
      handleConfirmDialogClose,
      handleConfirmDialogConfirm

    };

  }

};

</script>



<style lang="scss" scoped>
.purchase-target-name { max-width: 65%; overflow-wrap: anywhere; text-align: right; }
.purchase-quote-error { color: #c2410c; font-size: 13px; margin-bottom: 12px; }

.order-confirm-container {

  padding: 20px;

  display: flex;

  justify-content: center;

  margin-top: 20px;

  min-height: calc(100vh - 100px);



  .order-confirm-inner {

    width: 100%;

    max-width: 1200px;

    padding-bottom: 100px;

  }





  .welcome-card {

    margin-bottom: 24px;

    background-color: var(--card-bg-color);

    border-radius: 12px;

    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);

    padding: 20px;

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



    .card-body {

      p {

        margin: 0;

        color: var(--secondary-text-color);

        font-size: 14px;

        line-height: 1.6;

      }

    }

  }





  .alert-card {

    background-color: rgba(255, 152, 0, 0.08);

    border: 1px solid rgba(255, 152, 0, 0.2);

    border-radius: 16px;

    padding: 16px;

    margin-bottom: 30px;

    display: flex;

    align-items: center;

    width: 100%;

    box-shadow: 0 4px 15px rgba(255, 152, 0, 0.1);

    backdrop-filter: blur(10px);

    -webkit-backdrop-filter: blur(10px);

    transition: all 0.3s ease;



    &:hover {

      transform: translateY(-2px);

      box-shadow: 0 6px 20px rgba(255, 152, 0, 0.15);

    }



    .alert-icon {

      margin-right: 14px;

      color: #ff9800;

      flex-shrink: 0;

      background-color: rgba(255, 152, 0, 0.1);

      width: 44px;

      height: 44px;

      border-radius: 14px;

      display: flex;

      align-items: center;

      justify-content: center;

      padding: 0;



      svg {

        width: 28px;

        height: 28px;

      }

    }



    .alert-content {

      flex: 1;

      min-width: 0;



      h4 {

        font-size: 15px;

        font-weight: 600;

        margin: 0 0 6px 0;

        color: #ff9800;

        letter-spacing: 0.2px;

      }



      p {

        font-size: 14px;

        margin: 0;

        color: var(--secondary-text-color);

        line-height: 1.5;

      }

    }

  }





  .content-wrapper {

    display: flex;

    gap: 30px;





    .left-column {

      flex: 1;

      min-width: 0;

    }





    .right-column {

      flex: 1;

      min-width: 0;

    }

  }





  .section-wrapper {

    margin-bottom: 25px;



    .section-title {

      font-size: 18px;

      font-weight: 600;

      margin-bottom: 15px;

      color: var(--text-color);

      position: relative;

      padding-left: 14px;



      &::before {

        content: '';

        position: absolute;

        left: 0;

        top: 50%;

        transform: translateY(-50%);

        width: 4px;

        height: 18px;

        background-color: var(--theme-color);

        border-radius: 2px;

      }

    }

  }





  .plan-card {

    background-color: var(--card-bg-color);

    border-radius: 16px;

    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);

    padding: 24px;

    margin-bottom: 25px;

    border: 1px solid var(--border-color);

    transition: all 0.3s ease;



    &.glassmorphism {

      background-color: rgba(var(--card-background-rgb, 255, 255, 255), 0.7);

      backdrop-filter: blur(20px);

      -webkit-backdrop-filter: blur(20px);

      will-change: backdrop-filter, background-color;

    }



    .card-header {

      display: flex;

      justify-content: space-between;

      align-items: center;

      margin-bottom: 20px;



      .card-title {

        font-size: 20px;

        font-weight: 600;

        margin: 0;

        letter-spacing: 0.3px;

        color: var(--text-color);

      }



      .card-badge {

        display: flex;

        align-items: center;

        padding: 4px 12px;

        border-radius: 20px;

        font-size: 12px;

        font-weight: 500;

        backdrop-filter: blur(8px);

        -webkit-backdrop-filter: blur(8px);

        border: 1px solid rgba(255, 255, 255, 0.1);

        will-change: backdrop-filter, background-color, color;

        transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;



        &.stock-plenty {

          background-color: rgba(76, 175, 80, 0.2);

          border-color: rgba(76, 175, 80, 0.1);

          color: #4caf50;

        }



        &.stock-warning {

          background-color: rgba(255, 152, 0, 0.2);

          border-color: rgba(255, 152, 0, 0.1);

          color: #ff9800;

        }



        &.stock-danger {

          background-color: rgba(244, 67, 54, 0.2);

          border-color: rgba(244, 67, 54, 0.1);

          color: #f44336;

        }



        .badge-icon {

          margin-right: 4px;

        }

      }

    }



    .card-body {

      .plan-features {

        margin: 0;



        .feature-item {

          display: flex;

          align-items: center;

          margin-bottom: 14px;



          .feature-icon {

            width: 20px;

            height: 20px;

            margin-right: 10px;



            &.enabled {

              color: var(--theme-color);

            }



            &.disabled {

              color: #ccc;

            }

          }



          span {

            font-size: 14px;

            color: var(--text-color);

            line-height: 1.5;



            &.disabled-text {

              color: #999;

            }

          }

        }



        .html-content {

          font-size: 14px;

          line-height: 1.6;

          color: var(--text-color);

        }

      }

    }

  }





  .period-selection {

    margin-bottom: 20px;

    width: 100%;



    .skeleton-period-cards {

      display: flex;

      gap: 16px;

      overflow-x: hidden;

      padding-bottom: 8px;

      width: 100%;



      .skeleton-period-card {

        flex: 1;

        min-width: 0;

        height: 100px;

        background-color: rgba(0, 0, 0, 0.05);

        border-radius: 12px;

        position: relative;

        overflow: hidden;





        &::after {

          content: '';

          position: absolute;

          top: 0;

          right: 0;

          bottom: 0;

          left: 0;

          width: 30%;

          background: linear-gradient(90deg,

            rgba(255, 255, 255, 0) 0%,

            rgba(255, 255, 255, 0.15) 50%,

            rgba(255, 255, 255, 0) 100%);

          transform: translateX(-100%);

          animation: shimmer 2s infinite;

          will-change: transform;

        }

      }

    }



    .period-cards {

      display: grid;

      grid-template-columns: repeat(3, minmax(0, 1fr));

      gap: 15px;

      width: 100%;



      .period-card {

        cursor: pointer;

        border-radius: 12px;

        overflow: hidden;

        border: 2px solid var(--border-color);

        transition: all 0.3s ease;

        position: relative;

        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);



        &.active {

          border-color: var(--theme-color);

          transform: translateY(-3px);

          box-shadow: 0 5px 15px rgba(var(--theme-color-rgb), 0.15);



          .period-card-inner {

            background-color: rgba(var(--theme-color-rgb), 0.1);

          }



          .period-price {

            .currency,

            .amount {

              color: var(--theme-color);

            }

          }

        }



        &:hover:not(.active) {

          transform: translateY(-3px);

          border-color: rgba(var(--theme-color-rgb), 0.3);

          box-shadow: 0 3px 10px rgba(var(--theme-color-rgb), 0.1);

        }



        .period-card-inner {

          background-color: var(--card-bg-color);

          padding: 16px 12px !important;

          min-height: 90px !important;

          display: flex;

          flex-direction: column;

          align-items: center;

          justify-content: center;

          transition: background-color 0.3s ease;

        }



        .period-type {

          font-size: 14px !important;

          font-weight: 600;

          margin-bottom: 8px !important;

          color: var(--text-color);

          letter-spacing: 0.2px;

          text-align: center;

        }



        .period-price {

          margin-bottom: 8px;

          text-align: center;



          .currency {

            font-size: 14px !important;

            font-weight: 500;

            color: var(--text-color);

          }



          .amount {

            font-size: 24px !important;

            font-weight: 700;

            color: var(--text-color);

          }

        }



        .period-badge {

          display: none;

        }

      }

    }

  }





  .coupon-input {

    display: flex;

    gap: 12px;

    margin-bottom: 20px;

    flex-wrap: wrap;



    .coupon-field {

      flex: 1;

      height: 48px;

      padding: 0 18px;

      border-radius: 10px;

      border: 1px solid var(--border-color);

      background-color: var(--input-bg-color);

      color: var(--text-color);

      font-size: 14px;

      outline: none;

      transition: all 0.3s ease;

      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.03);

      min-width: 0;



      &.applied {

        border-color: #4caf50;

        background-color: rgba(76, 175, 80, 0.05);

      }



      &:focus:not(.applied) {

        border-color: rgba(var(--theme-color-rgb), 0.5);

        box-shadow: 0 0 0 3px rgba(var(--theme-color-rgb), 0.2);

        transform: translateY(-1px);

      }



      &::placeholder {

        color: var(--secondary-text-color);

        opacity: 0.6;

      }

    }



    .btn-verify {

      height: 48px;

      padding: 0 24px;

      border-radius: 10px;

      background-color: var(--theme-color);

      color: white;

      font-size: 14px;

      font-weight: 500;

      display: flex;

      align-items: center;

      gap: 8px;

      border: none;

      cursor: pointer;

      transition: all 0.3s ease;

      box-shadow: 0 4px 10px rgba(var(--theme-color-rgb), 0.2);

      white-space: nowrap;

      flex-shrink: 0;



      &.applied {

        background-color: #4caf50;

        box-shadow: 0 4px 10px rgba(76, 175, 80, 0.2);

        cursor: default;

      }



      &:hover:not(:disabled):not(.applied) {

        background-color: color-mix(in srgb, var(--theme-color) 85%, black) !important;

        transform: translateY(-2px);

        box-shadow: 0 6px 16px rgba(var(--theme-color-rgb), 0.3);

      }



      &:disabled {

        opacity: 0.6;

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



    .btn-remove-coupon {

      height: 48px;

      padding: 0 16px;

      border-radius: 10px;

      background-color: #f44336;

      color: white;

      font-size: 14px;

      font-weight: 500;

      display: flex;

      align-items: center;

      gap: 6px;

      border: none;

      cursor: pointer;

      transition: all 0.3s ease;

      box-shadow: 0 4px 10px rgba(244, 67, 54, 0.2);

      white-space: nowrap;

      flex-shrink: 0;



      &:hover {

        background-color: #d32f2f;

        transform: translateY(-2px);

        box-shadow: 0 6px 16px rgba(244, 67, 54, 0.3);

      }

    }

  }





  .order-summary {

    background-color: var(--card-bg-color);

    border-radius: 16px;

    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);

    padding: 24px;

    margin-bottom: 20px;

    border: 1px solid var(--border-color);



    &.glassmorphism {

      background-color: rgba(var(--card-background-rgb, 255, 255, 255), 0.7);

      backdrop-filter: blur(20px);

      -webkit-backdrop-filter: blur(20px);

    }



    .summary-row {

      display: flex;

      justify-content: space-between;

      margin-bottom: 12px;

      align-items: center;



      &.skeleton {

        margin-bottom: 16px;

      }



      .summary-label {

        font-size: 14px;

        color: var(--secondary-text-color);

        letter-spacing: 0.2px;



        .coupon-name {

          font-size: 12px;

          opacity: 0.8;

          font-style: italic;

        }

      }



      .summary-value {

        font-size: 14px;

        font-weight: 500;

        color: var(--text-color);



        &.discount {

          color: #f44336;

          font-weight: 600;

        }

      }



      &.total {

        margin-top: 8px;

        margin-bottom: 0;



        .summary-label {

          font-size: 16px;

          font-weight: 600;

          color: var(--text-color);

        }



        .summary-value {

          font-size: 22px;

          font-weight: 700;

          color: var(--theme-color);

        }

      }

    }



    .summary-divider {

      height: 1px;

      background-color: var(--border-color);

      margin: 16px 0;

    }

  }



  .order-summary-section {

    margin-top: 0;

  }





  .action-buttons {

    display: flex;

    justify-content: space-between;

    margin: 30px 0 40px 0;

    gap: 16px;



    .btn-back {

      height: 44px;

      padding: 0 20px;

      border-radius: 10px;

      background-color: transparent;

      color: var(--text-color);

      font-size: 14px;

      font-weight: 500;

      display: flex;

      align-items: center;

      gap: 8px;

      border: 1px solid var(--border-color);

      cursor: pointer;

      transition: all 0.3s ease;

      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.02);



      &:hover {

        background-color: rgba(0, 0, 0, 0.05);

        transform: translateY(-2px);

        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);

      }

    }



    .btn-order {

      height: 44px;

      padding: 0 24px;

      border-radius: 10px;

      background-color: var(--theme-color);

      color: white;

      font-size: 14px;

      font-weight: 500;

      display: flex;

      align-items: center;

      gap: 8px;

      border: none;

      cursor: pointer;

      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);

      box-shadow: 0 4px 12px rgba(var(--theme-color-rgb), 0.2);

      will-change: transform, box-shadow;



      &:hover:not(:disabled) {

        background-color: color-mix(in srgb, var(--theme-color) 85%, black) !important;

        transform: translateY(-2px);

        box-shadow: 0 6px 16px rgba(var(--theme-color-rgb), 0.3);

      }



      &:active:not(:disabled) {

        transform: translateY(0);

        box-shadow: 0 2px 8px rgba(var(--theme-color-rgb), 0.2);

      }



      &:disabled {

        opacity: 0.6;

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





.skeleton-card {

  width: 100%;

  height: 100%;

  position: relative;

  overflow: hidden;

  border-radius: 10px;



  .skeleton-header {

    height: 24px;

    width: 60%;

    background-color: rgba(0, 0, 0, 0.05);

    border-radius: 6px;

    margin-bottom: 20px;

    position: relative;

    overflow: hidden;

  }



  .skeleton-body {

    .skeleton-title {

      height: 40px;

      width: 100%;

      background-color: rgba(0, 0, 0, 0.05);

      border-radius: 8px;

      margin-bottom: 24px;

      position: relative;

      overflow: hidden;

    }



    .skeleton-features {

      margin-bottom: 24px;



      .skeleton-feature {

        height: 16px;

        background-color: rgba(0, 0, 0, 0.05);

        border-radius: 6px;

        margin-bottom: 12px;

        position: relative;

        overflow: hidden;



        &:nth-child(1) { width: 92%; }

        &:nth-child(2) { width: 85%; }

        &:nth-child(3) { width: 88%; }

        &:nth-child(4) { width: 80%; }

      }

    }

  }





  &::after {

    content: '';

    position: absolute;

    top: 0;

    right: 0;

    bottom: 0;

    left: 0;

    width: 30%;

    background: linear-gradient(90deg,

      rgba(255, 255, 255, 0) 0%,

      rgba(255, 255, 255, 0.15) 50%,

      rgba(255, 255, 255, 0) 100%);

    transform: translateX(-100%);

    animation: shimmer 2s infinite;

    will-change: transform;

    pointer-events: none;

  }

}





.skeleton-text {

  height: 16px;

  background-color: rgba(0, 0, 0, 0.05);

  border-radius: 6px;

  position: relative;

  overflow: hidden;

  width: 100px;



  &:first-child {

    width: 70%;

  }

}





@keyframes shimmer {

  0% {

    transform: translateX(-100%);

  }

  100% {

    transform: translateX(300%);

  }

}



@keyframes spin {

  to { transform: rotate(360deg); }

}





@media (max-width: 991px) {

  .order-confirm-container {

    .content-wrapper {

      gap: 25px;

    }

  }

}



@media (max-width: 768px) {

  .order-confirm-container {

    margin-top: 15px;



    .welcome-card {

      padding: 15px;



      .card-header .card-title {

        font-size: 16px;

      }



      .card-body p {

        font-size: 13px;

      }

    }



    .content-wrapper {

      flex-direction: column;

      gap: 20px;

    }



    .action-buttons {

      position: relative;

      z-index: 1;

      margin: 24px 0 30px 0;

      display: flex;

      flex-direction: row;

      gap: 12px;

      transform: none;

      opacity: 1;

      transition: none;



      .btn-back,

      .btn-order {

        flex: 1;

        min-width: 0;

        padding: 0 10px;

        justify-content: center;

        font-size: 13px;

        height: 44px;

        will-change: transform;

      }

    }



    .period-selection .period-cards {

      grid-template-columns: repeat(2, minmax(0, 1fr));

      gap: 12px;



      .period-card {

        .period-card-inner {

          padding: 12px 8px !important;

          min-height: 80px !important;



          .period-type {

            font-size: 13px !important;

            margin-bottom: 6px !important;

          }



          .period-price {

            .currency {

              font-size: 13px !important;

            }



            .amount {

              font-size: 20px !important;

            }

          }

        }

      }

    }

  }

}



@media (max-width: 480px) {

  .order-confirm-container {

    .section-title {

      font-size: 16px;

      margin-bottom: 12px;

    }



    .coupon-input {

      flex-direction: row;

      flex-wrap: wrap;

      gap: 8px;



      .coupon-field {

        flex: 1;

        min-width: 120px;

      }



      .btn-verify {

        width: auto;

        padding: 0 15px;

        justify-content: center;

        white-space: nowrap;

      }



      .btn-remove-coupon {

        padding: 0 12px;



        span {

          font-size: 13px;

        }

      }





      &:has(.coupon-field.applied) {

        .coupon-field {

          width: 100%;

          flex: none;

          margin-bottom: 8px;

        }



        .btn-verify, .btn-remove-coupon {

          flex: 1;

          min-width: 0;

          justify-content: center;

        }

      }

    }



    .plan-card, .order-summary {

      padding: 18px;

    }



    .period-selection .period-cards {

      grid-template-columns: repeat(2, minmax(0, 1fr));

      gap: 10px;



      .period-card {

        .period-card-inner {

          min-height: 70px !important;

        }

      }

    }

  }

}





@media (prefers-color-scheme: dark) {

  .skeleton-card,

  .skeleton-period-card,

  .skeleton-text {

    &::after {

      background: linear-gradient(90deg,

        rgba(255, 255, 255, 0) 0%,

        rgba(255, 255, 255, 0.08) 50%,

        rgba(255, 255, 255, 0) 100%);

    }

  }



  .skeleton-header,

  .skeleton-title,

  .skeleton-feature,

  .skeleton-text,

  .skeleton-period-card {

    background-color: rgba(255, 255, 255, 0.05);

  }

}





@media screen and (max-width: 768px) {

  .order-confirm-container .period-selection .period-cards {

    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;

    gap: 12px !important;

  }

}



@media screen and (max-width: 480px) {

  .order-confirm-container .period-selection .period-cards {

    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;

    gap: 10px !important;

  }

}





.order-confirm-container .period-selection .period-cards {

  display: grid !important;

}





@media screen and (max-width: 768px) {

  .period-cards {

    display: grid !important;

    grid-template-columns: repeat(2, 1fr) !important;

    gap: 12px !important;

  }

}



@media screen and (max-width: 480px) {

  .period-cards {

    display: grid !important;

    grid-template-columns: repeat(2, 1fr) !important;

    gap: 10px !important;

  }

}





:deep(.period-cards) {

  display: grid !important;

  grid-template-columns: repeat(3, minmax(0, 1fr)) !important;

  gap: 15px !important;

  width: 100% !important;

}



@media screen and (max-width: 768px) {

  :deep(.period-cards) {

    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;

    gap: 12px !important;

  }

}



@media screen and (max-width: 480px) {

  :deep(.period-cards) {

    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;

    gap: 10px !important;

  }

}

</style>
