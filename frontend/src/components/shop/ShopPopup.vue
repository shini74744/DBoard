<template>
  <transition name="fade">
    <div v-if="show" class="shop-popup-overlay" @click.self="attemptClose">
      <div class="shop-popup-container">
        <div class="shop-popup-header">
          <h2 class="popup-title" v-html="title"></h2>
          <button class="popup-close-btn" @click="attemptClose">
            <IconX :size="20" />
          </button>
        </div>
        <div class="shop-popup-content">
          <div v-html="content"></div>
        </div>
        <div class="shop-popup-footer">
          <button
            class="popup-action-btn"
            @click="attemptClose"
            :disabled="waitTimeRemaining > 0"
          >
            <template v-if="waitTimeRemaining > 0">
              {{ $t('shop.popup.wait_close_btn', { seconds: waitTimeRemaining }) }}
            </template>
            <template v-else>
              {{ $t('shop.popup.close_btn') }}
            </template>
          </button>
        </div>
      </div>
    </div>
  </transition>
</template>

<script>
import { ref, watch, onUnmounted } from 'vue';
import { IconX } from '@tabler/icons-vue';

export default {
  name: 'ShopPopup',
  components: {
    IconX
  },
  props: {
    showPopup: {
      type: Boolean,
      default: false
    },
    title: {
      type: String,
      default: ''
    },
    content: {
      type: String,
      default: ''
    },
    cooldownHours: {
      type: Number,
      default: 24
    },
    closeWaitSeconds: {
      type: Number,
      default: 0
    }
  },
  emits: ['close'],
  setup(props, { emit }) {
    const show = ref(false);
    const waitTimeRemaining = ref(0);
    let countdownTimer = null;

    const startCountdown = () => {
      if (countdownTimer) {
        clearInterval(countdownTimer);
      }

      if (props.closeWaitSeconds > 0) {
        waitTimeRemaining.value = props.closeWaitSeconds;

        countdownTimer = setInterval(() => {
          if (waitTimeRemaining.value > 0) {
            waitTimeRemaining.value -= 1;
          } else {
            clearInterval(countdownTimer);
          }
        }, 1000);
      } else {
        waitTimeRemaining.value = 0;
      }
    };

    const attemptClose = () => {
      if (waitTimeRemaining.value > 0) {
        return;
      }

      show.value = false;

      if (props.cooldownHours > 0) {
        const closeTime = new Date().getTime();
        localStorage.setItem('shop_popup_close_time', closeTime.toString());
      }

      emit('close');
    };

    watch(() => props.showPopup, (newVal) => {
      if (newVal) {
        setTimeout(() => {
          if (props.cooldownHours === 0) {
            show.value = true;
            startCountdown();
            return;
          }

          const closeTime = localStorage.getItem('shop_popup_close_time');
          if (closeTime) {
            const now = new Date().getTime();
            const elapsed = now - parseInt(closeTime);
            const cooldownMs = props.cooldownHours * 60 * 60 * 1000;

            if (elapsed >= cooldownMs) {
              show.value = true;
              startCountdown();
            } else {
              emit('close');
            }
          } else {
            show.value = true;
            startCountdown();
          }
        }, 500);
      } else {
        show.value = false;
        if (countdownTimer) {
          clearInterval(countdownTimer);
        }
      }
    }, { immediate: true });

    onUnmounted(() => {
      if (countdownTimer) {
        clearInterval(countdownTimer);
      }
    });

    return {
      show,
      attemptClose,
      waitTimeRemaining
    };
  }
};
</script>

<style lang="scss" scoped>
.shop-popup-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.5);
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  box-sizing: border-box;
  backdrop-filter: blur(4px);

  .shop-popup-container {
    width: 100%;
    max-width: 500px;
    background-color: rgba(var(--card-background-rgb, 255, 255, 255), 1);
    border-radius: 16px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
    border: 1px solid rgba(var(--theme-color-rgb), 0.15);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    max-height: 80vh;
    animation: modal-in 0.3s cubic-bezier(0.16, 1, 0.3, 1);

    @media (prefers-color-scheme: dark) {
      background-color: rgba(var(--card-background-rgb, 30, 30, 30), 1);
    }

    .shop-popup-header {
      padding: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid var(--border-color);
      background-color: rgba(var(--theme-color-rgb), 0.03);

      .popup-title {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: var(--text-color);
      }

      .popup-close-btn {
        background: none;
        border: none;
        cursor: pointer;
        color: var(--secondary-text-color);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 8px;
        margin: -8px;
        border-radius: 50%;
        transition: all 0.3s ease;

        &:hover {
          background-color: rgba(0, 0, 0, 0.05);
          color: var(--text-color);
          transform: rotate(90deg);
        }

        &:disabled {
          opacity: 0.5;
          cursor: not-allowed;
        }
      }
    }

    .shop-popup-content {
      padding: 20px;
      overflow-y: auto;
      flex: 1;
      background: linear-gradient(to bottom, rgba(var(--theme-color-rgb), 0.02), transparent);

      :deep(p) {
        margin: 12px 0;
        line-height: 1.6;
        color: var(--text-color);
      }

      :deep(strong) {
        color: var(--theme-color);
        font-weight: 600;
      }

      :deep(a) {
        color: var(--theme-color);
        text-decoration: none;

        &:hover {
          text-decoration: underline;
        }
      }
    }

    .shop-popup-footer {
      padding: 15px 20px;
      border-top: 1px solid var(--border-color);
      display: flex;
      justify-content: flex-end;

      .popup-action-btn {
        padding: 8px 20px;
        background-color: var(--theme-color);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        min-width: 120px;

        &:hover:not(:disabled) {
          transform: translateY(-2px);
          box-shadow: 0 4px 10px rgba(var(--theme-color-rgb), 0.3);
        }

        &:disabled {
          opacity: 0.7;
          cursor: not-allowed;
          background-color: var(--secondary-text-color);
        }
      }
    }
  }
}

@keyframes modal-in {
  from {
    opacity: 0;
    transform: translateY(10px) scale(0.98);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@keyframes modal-out {
  from {
    opacity: 1;
    transform: scale(1);
  }
  to {
    opacity: 0;
    transform: scale(0.95);
  }
}

.fade-enter-active {
  transition: opacity 0.3s ease;
}

.fade-leave-active {
  transition: opacity 0.5s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}


@media (max-width: 768px) {
  .shop-popup-overlay {
    padding: 15px;

    .shop-popup-container {
      max-width: 100%;
      max-height: 85vh;

      .shop-popup-header {
        padding: 15px;

        .popup-title {
          font-size: 16px;
        }
      }

      .shop-popup-content {
        padding: 15px;
      }

      .shop-popup-footer {
        padding: 12px 15px;
      }
    }
  }
}
</style>
