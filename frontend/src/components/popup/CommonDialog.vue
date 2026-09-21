<template>
  <transition name="fade">
    <div v-if="showDialog" class="dialog-overlay" @click.self="handleOverlayClick">
      <div class="dialog-container">
        <div class="dialog-header">
          <h2 class="dialog-title" v-html="title"></h2>
          <button
            v-if="showCloseIcon"
            class="dialog-close-btn"
            @click="handleClose"
          >
            <IconX :size="20" />
          </button>
        </div>

        <div class="dialog-content">
          <div v-html="content"></div>
        </div>

        <div class="dialog-footer" v-if="showCancelButton || showConfirmButton">
          <button
            v-if="showCancelButton"
            class="dialog-btn dialog-btn-cancel"
            @click="handleClose"
          >
            {{ cancelButtonText || $t(cancelButtonI18nKey) }}
          </button>

          <button
            v-if="showConfirmButton"
            class="dialog-btn dialog-btn-confirm"
            @click="handleConfirm"
          >
            {{ confirmButtonText || $t(confirmButtonI18nKey) }}
          </button>
        </div>
      </div>
    </div>
  </transition>
</template>

<script>
import { ref, watch } from 'vue';
import { IconX } from '@tabler/icons-vue';

export default {
  name: 'CommonDialog',
  components: {
    IconX
  },
  props: {
    /**
     * 是否显示弹窗
     */
    showDialog: {
      type: Boolean,
      default: false
    },
    /**
     * 弹窗标题
     */
    title: {
      type: String,
      default: ''
    },
    /**
     * 弹窗内容
     */
    content: {
      type: String,
      default: ''
    },
    /**
     * 是否显示右上角关闭图标
     */
    showCloseIcon: {
      type: Boolean,
      default: true
    },
    /**
     * 是否显示取消按钮
     */
    showCancelButton: {
      type: Boolean,
      default: true
    },
    /**
     * 是否显示确认按钮
     */
    showConfirmButton: {
      type: Boolean,
      default: true
    },
    /**
     * 取消按钮文本（如果提供，优先使用此文本）
     */
    cancelButtonText: {
      type: String,
      default: ''
    },
    /**
     * 确认按钮文本（如果提供，优先使用此文本）
     */
    confirmButtonText: {
      type: String,
      default: ''
    },
    /**
     * 取消按钮i18n key
     */
    cancelButtonI18nKey: {
      type: String,
      default: 'common.cancel'
    },
    /**
     * 确认按钮i18n key
     */
    confirmButtonI18nKey: {
      type: String,
      default: 'common.confirm'
    },
    /**
     * 点击遮罩层是否可以关闭
     */
    clickOverlayToClose: {
      type: Boolean,
      default: true
    }
  },
  emits: ['close', 'confirm'],
  setup(props, { emit }) {
    const show = ref(false);

    /**
     * 处理关闭操作（取消按钮和右上角X按钮都触发此方法）
     */
    const handleClose = () => {
      show.value = false;
      emit('close');
    };

    /**
     * 处理确认操作
     */
    const handleConfirm = () => {
      show.value = false;
      emit('confirm');
    };

    /**
     * 处理遮罩层点击
     */
    const handleOverlayClick = () => {
      if (props.clickOverlayToClose) {
        handleClose();
      }
    };

    /**
     * 监听showDialog变化
     */
    watch(() => props.showDialog, (newVal) => {
      show.value = newVal;
    }, { immediate: true });

    return {
      show,
      handleClose,
      handleConfirm,
      handleOverlayClick
    };
  }
};
</script>

<style lang="scss" scoped>
.dialog-overlay {
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

  .dialog-container {
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

    .dialog-header {
      padding: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid var(--border-color);
      background-color: rgba(var(--theme-color-rgb), 0.03);

      .dialog-title {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: var(--text-color);
      }

      .dialog-close-btn {
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
      }
    }

    .dialog-content {
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

    .dialog-footer {
      padding: 15px 20px;
      border-top: 1px solid var(--border-color);
      display: flex;
      justify-content: flex-end;
      gap: 10px;

      .dialog-btn {
        padding: 8px 20px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        min-width: 80px;

        &:hover {
          transform: translateY(-2px);
        }
      }

      .dialog-btn-cancel {
        background-color: var(--border-color);
        color: var(--text-color);

        &:hover {
          background-color: var(--secondary-text-color);
          box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
      }

      .dialog-btn-confirm {
        background-color: var(--theme-color);
        color: white;

        &:hover {
          box-shadow: 0 4px 10px rgba(var(--theme-color-rgb), 0.3);
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
  .dialog-overlay {
    padding: 15px;

    .dialog-container {
      max-width: 100%;
      max-height: 85vh;

      .dialog-header {
        padding: 15px;

        .dialog-title {
          font-size: 16px;
        }
      }

      .dialog-content {
        padding: 15px;
      }

      .dialog-footer {
        padding: 12px 15px;
        flex-direction: column-reverse;

        .dialog-btn {
          width: 100%;
          margin: 0;
        }
      }
    }
  }
}
</style>
