<!-- 移动端工单页面 -->

<template>
    <div class="mobile-ticket-container">
        <!-- 大屏幕提示 -->

        <div v-if="isLargeScreen" class="screen-size-notice">
            <div class="notice-content">
                <IconDeviceDesktop :size="48" />

                <h2>{{ $t('tickets.largeScreenNotice') }}</h2>

                <p>{{ $t('tickets.switchToDesktop') }}</p>

                <button class="switch-btn" @click="switchToDesktopView">
                    {{ $t('tickets.switchToDesktopView') }}
                </button>
            </div>
        </div>

        <template v-else>
            <!-- 欢迎卡片 -->

            <div class="dashboard-card welcome-card">
                <div class="card-header">
                    <h2 class="card-title">{{ $t('tickets.title') }}</h2>
                </div>

                <div class="card-body">
                    <p>{{ $t('tickets.description') }}</p>
                </div>
            </div>

            <!-- 工单列表 -->

            <div class="ticket-list" v-if="!selectedTicket">
                <!-- 创建工单按钮 -->

                <div class="create-ticket-wrapper">
                    <button
                        class="new-ticket-btn"
                        @click="showCreateTicketModal"
                    >
                        <IconPlus :size="18" />

                        {{ $t('tickets.newTicket') }}
                    </button>
                </div>

                <!-- 加载状态 -->

                <div v-if="loading" class="loading-state">
                    <LoadingSpinner />

                    <p>{{ $t('tickets.loadingTickets') }}</p>
                </div>

                <!-- 工单列表内容 -->

                <div v-else-if="tickets.length > 0" class="tickets-content">
                    <div
                        v-for="ticket in tickets"
                        :key="ticket.id"
                        class="ticket-item"
                        @click="selectTicket(ticket)"
                    >
                        <div class="ticket-header">
                            <span class="ticket-subject">{{
                                ticket.subject
                            }}</span>

                            <span
                                :class="[
                                    'ticket-status',
                                    `status-${ticket.status}`
                                ]"
                            >
                                {{
                                    ticket.status === 0
                                        ? $t('tickets.statusOpen')
                                        : $t('tickets.statusClosed')
                                }}
                            </span>
                        </div>

                        <div class="ticket-info">
                            <span class="ticket-time">{{
                                formatDate(ticket.created_at)
                            }}</span>

                            <span class="ticket-id">#{{ ticket.id }}</span>
                        </div>
                    </div>
                </div>

                <!-- 空状态 -->

                <div v-else class="empty-state">
                    <IconTicket :size="48" />

                    <p>{{ $t('tickets.noTickets') }}</p>

                    <button
                        class="create-ticket-btn"
                        @click="showCreateTicketModal"
                    >
                        {{ $t('tickets.createNew') }}
                    </button>
                </div>
            </div>

            <!-- 工单详情 -->

            <div v-else class="ticket-detail">
                <!-- 详情页头部 -->

                <div class="detail-header">
                    <button class="back-btn" @click="backToList">
                        <IconArrowLeft :size="20" />
                    </button>

                    <div class="ticket-info">
                        <h2>{{ selectedTicket.subject }}</h2>

                        <div class="meta-info">
                            <span
                                :class="[
                                    'status-badge',
                                    `status-${selectedTicket.status}`
                                ]"
                            >
                                {{
                                    selectedTicket.status === 0
                                        ? $t('tickets.statusOpen')
                                        : $t('tickets.statusClosed')
                                }}
                            </span>

                            <span
                                :class="[
                                    'level-badge',
                                    `level-${selectedTicket.level}`
                                ]"
                            >
                                {{ getPriorityLabel(selectedTicket.level) }}
                            </span>
                        </div>
                    </div>

                    <button
                        v-if="selectedTicket.status === 0"
                        class="close-ticket-btn"
                        @click="showCloseConfirm"
                    >
                        <IconX :size="16" />
                    </button>
                </div>

                <!-- 消息列表 -->

                <div class="messages-container">
                    <div v-if="loadingMessages" class="loading-state">
                        <LoadingSpinner />

                        <p>{{ $t('tickets.loadingMessages') }}</p>
                    </div>

                    <div
                        v-else-if="ticketMessages.length === 0"
                        class="empty-messages"
                    >
                        <IconMessageCircle :size="48" />

                        <p>{{ $t('tickets.noMessages') }}</p>
                    </div>

                    <div v-else class="message-list">
                        <div
                            v-for="message in ticketMessages"
                            :key="message.id"
                            :class="[
                                'message-item',

                                message.is_admin
                                    ? 'admin-message'
                                    : 'user-message'
                            ]"
                        >
                            <div class="message-avatar">
                                <IconHeadset v-if="message.is_admin" />

                                <IconUser v-else />
                            </div>

                            <div class="message-content">
                                <div class="message-header">
                                    <span class="sender-name">
                                        {{
                                            message.is_admin
                                                ? $t('tickets.admin')
                                                : $t('tickets.you')
                                        }}
                                    </span>

                                    <span class="message-time">{{
                                        formatTimeShort(message.created_at)
                                    }}</span>
                                </div>

                                <div
                                    class="message-text"
                                    v-html="md.render(message.message)"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 回复框 -->

                <div v-if="selectedTicket.status === 0" class="reply-box">
                    <textarea
                        v-model="replyMessage"
                        :placeholder="$t('tickets.replyPlaceholder')"
                        rows="3"
                        @keydown.ctrl.enter="sendReply"
                        @keyup="updateReplyCaret"
                        @click="updateReplyCaret"
                    ></textarea>
                    <div
                        class="reply-tools"
                        style="
                            display: flex;
                            gap: 8px;
                            align-items: center;
                            justify-content: flex-end;
                        "
                    >
                        <button
                            class="send-btn"
                            type="button"
                            @click="triggerReplyImageInput"
                            :disabled="uploadingReplyImages"
                        >
                            <IconPhotoPlus
                                :size="18"
                                v-if="!uploadingReplyImages"
                            />
                            <span v-else class="loader"></span>
                            <span v-if="!uploadingReplyImages">{{
                                $t('tickets.upPictures') || '上传图片'
                            }}</span>
                            <span v-else>{{
                                $t('tickets.uploadingImages') || '上传中'
                            }}</span>
                        </button>

                        <button
                            class="send-btn"
                            @click="sendReply"
                            :disabled="!replyMessage.trim() || sendingReply"
                        >
                            <span v-if="sendingReply" class="loader"></span>

                            <IconSend v-else :size="18" />

                            {{ $t('tickets.send') }}
                        </button>
                    </div>

                    <!-- 隐藏的文件选择框 -->
                    <input
                        ref="replyImageInput"
                        type="file"
                        accept="image/*"
                        multiple
                        style="display: none"
                        @change="handleReplyImageUpload"
                    />
                </div>

                <div v-else class="ticket-closed-notice">
                    <IconLock :size="20" />

                    <span>{{ $t('tickets.ticketClosed') }}</span>
                </div>
            </div>
        </template>

        <!-- 创建工单模态框 并将弹窗置顶-->
        <teleport to="body">
            <div v-if="showModal" class="modal-overlay" @click="closeModal">
                <div class="modal-content" @click.stop>
                    <div class="modal-header">
                        <h3>{{ $t('tickets.createNew') }}</h3>

                        <button class="close-btn" @click="closeModal">
                            <IconX :size="20" />
                        </button>
                    </div>

                    <div class="modal-body">
                        <div class="form-group">
                            <label>{{ $t('tickets.subject') }}</label>

                            <input
                                type="text"
                                v-model="newTicket.subject"
                                :placeholder="$t('tickets.subjectPlaceholder')"
                            />
                        </div>

                        <div class="form-group">
                            <label>{{ $t('tickets.level') }}</label>

                            <div class="priority-selector">
                                <button
                                    v-for="level in [0, 1, 2]"
                                    :key="level"
                                    :class="[
                                        'priority-btn',
                                        { active: newTicket.level === level }
                                    ]"
                                    @click="newTicket.level = level"
                                >
                                    {{ getPriorityLabel(level) }}
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>{{ $t('tickets.message') }}</label>

                            <textarea
                                v-model="newTicket.message"
                                :placeholder="$t('tickets.messagePlaceholder')"
                                rows="5"
                            ></textarea>

                            <div
                                v-if="TICKET_CONFIG.isImageHosting"
                                class="image-upload-area"
                                :class="{ dragging: draggingImage }"
                                @dragover.prevent="draggingImage = true"
                                @dragleave.prevent="draggingImage = false"
                                @drop.prevent="onDropImage"
                                @click="triggerImageInput"
                            >
                                <div class="upload-tip">
                                    <span class="upload-tip-text">{{
                                        $t('tickets.uploadTipText') ||
                                        '拖拽图片到此处或点击上传'
                                    }}</span
                                    ><br />
                                    <span class="upload-desc">{{
                                        $t('tickets.uploadDescText') ||
                                        '支持 JPG、PNG、GIF 格式，最大 5 MB'
                                    }}</span>
                                </div>
                                <input
                                    ref="imageInput"
                                    type="file"
                                    accept="image/*"
                                    multiple
                                    style="display: none"
                                    @change="handleImageUpload"
                                />
                            </div>
                            <div
                                v-if="uploadingImages"
                                style="margin-top: 8px; color: #2196f3"
                            >
                                {{
                                    $t('tickets.uploadingImages') ||
                                    '图片上传中...'
                                }}
                            </div>
                            <div
                                v-if="uploadedImages.length"
                                style="margin-top: 8px"
                            >
                                <span
                                    v-for="img in uploadedImages"
                                    :key="img"
                                    class="thumb"
                                    @click="insertImage(img)"
                                    title="点击插入到内容"
                                    style="
                                        display: inline-block;
                                        margin-right: 8px;
                                        cursor: pointer;
                                    "
                                >
                                    <img
                                        :src="img"
                                        style="
                                            max-width: 60px;
                                            max-height: 60px;
                                            border-radius: 6px;
                                            border: 1px solid #eee;
                                        "
                                    />
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button class="cancel-btn" @click="closeModal">
                            {{ $t('common.cancel') }}
                        </button>

                        <button
                            class="submit-btn"
                            @click="submitTicket"
                            :disabled="submitting"
                        >
                            <span v-if="submitting" class="loader"></span>

                            {{ $t('common.submit') }}
                        </button>
                    </div>
                </div>
            </div>
        </teleport>
        <!-- 关闭工单确认弹窗 -->

        <div
            v-if="showCloseTicketModal"
            class="modal-overlay"
            @click="closeConfirmModal"
        >
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>{{ $t('tickets.closeConfirmTitle') }}</h3>

                    <button class="close-btn" @click="closeConfirmModal">
                        <IconX :size="20" />
                    </button>
                </div>

                <div class="modal-body">
                    <p>{{ $t('tickets.closeConfirmText') }}</p>
                </div>

                <div class="modal-footer">
                    <button class="cancel-btn" @click="closeConfirmModal">
                        {{ $t('common.cancel') }}
                    </button>

                    <button
                        class="confirm-close-btn danger"
                        @click="closeSelectedTicket"
                        :disabled="closingTicket"
                    >
                        <span v-if="!closingTicket">{{
                            $t('tickets.closeTicket')
                        }}</span>

                        <span v-else class="btn-loading">
                            <span class="spinner"></span>

                            {{ $t('tickets.closing') }}
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <!-- 工单弹窗 -->

        <TicketPopup
            :show-popup="showTicketPopup"
            :title="ticketPopupCfg.title"
            :content="ticketPopupCfg.content"
            :cooldown-hours="ticketPopupCfg.cooldownHours"
            :close-wait-seconds="ticketPopupCfg.closeWaitSeconds"
            @close="handleTicketPopupClose"
        />
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed, nextTick } from 'vue';

import { useI18n } from 'vue-i18n';

import { useRouter } from 'vue-router';

import MarkdownIt from 'markdown-it';

const md = new MarkdownIt({ linkify: true, breaks: true });

import {
    IconPlus,
    IconTicket,
    IconX,
    IconArrowLeft,
    IconMessageCircle,
    IconHeadset,
    IconUser,
    IconSend,
    IconLock,
    IconDeviceDesktop,
    IconPhotoPlus
} from '@tabler/icons-vue';

import LoadingSpinner from '@/components/common/LoadingSpinner.vue';

import { useToast } from '@/composables/useToast';

import {
    fetchTicketList,
    createTicket,
    getTicketDetail,
    replyTicket,
    closeTicket
} from '@/api/ticket';

import {
    getUserInfo,
    getIpLocationInfo,
    getCommConfig,
    getUserSubscribe
} from '@/api/user';

import { formatUserInfoForTicket } from '@/utils/formatters';

import { TICKET_CONFIG } from '@/utils/baseConfig';

import TicketPopup from '@/components/ticket/TicketPopup.vue';

const { t } = useI18n();

const { showToast } = useToast();

const router = useRouter();

const loading = ref(false);

const tickets = ref([]);

const showModal = ref(false);

const submitting = ref(false);

const selectedTicket = ref(null);

const ticketMessages = ref([]);

const loadingMessages = ref(false);

const replyMessage = ref('');

const sendingReply = ref(false);

const showCloseTicketModal = ref(false);

const closingTicket = ref(false);

const refreshInterval = ref(null);

const newTicket = ref({
    subject: '',

    message: '',

    level: 0
});

const isLargeScreen = ref(false);

const showTicketPopup = ref(false);

const ticketPopupCfg = TICKET_CONFIG?.popup || {};

const checkScreenSize = () => {
    isLargeScreen.value = window.innerWidth >= 905;
};

const switchToDesktopView = () => {
    router.push('/tickets');
};

onMounted(() => {
    checkScreenSize();

    window.addEventListener('resize', checkScreenSize);

    fetchTickets();

    checkTicketPopup();
});

onUnmounted(() => {
    window.removeEventListener('resize', checkScreenSize);

    clearRefreshInterval();
});

const getPriorityLabel = (level) => {
    const labels = {
        0: t('tickets.levelLow'),

        1: t('tickets.levelMedium'),

        2: t('tickets.levelHigh')
    };

    return labels[level] || labels[0];
};

const formatDate = (timestamp) => {
    if (!timestamp) return '--';

    const date = new Date(timestamp * 1000);

    return date.toLocaleString();
};

const formatTimeShort = (timestamp) => {
    if (!timestamp) return '--';

    const date = new Date(timestamp * 1000);

    const now = new Date();

    const isToday =
        date.getDate() === now.getDate() &&
        date.getMonth() === now.getMonth() &&
        date.getFullYear() === now.getFullYear();

    if (isToday) {
        return date.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit'
        });
    } else {
        return (
            date.toLocaleDateString([], { month: 'short', day: 'numeric' }) +
            ' ' +
            date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        );
    }
};

const showCreateTicketModal = () => {
    showModal.value = true;
};

const closeModal = () => {
    const modalContent = document.querySelector('.modal-content');

    if (modalContent) {
        modalContent.classList.add('closing');

        setTimeout(() => {
            showModal.value = false;

            newTicket.value = {
                subject: '',

                message: '',

                level: 0
            };
        }, 300);
    } else {
        showModal.value = false;

        newTicket.value = {
            subject: '',

            message: '',

            level: 0
        };
    }
};

const fetchTicketDetail = async (ticketId, isAutoRefresh = false) => {
    if (!isAutoRefresh) {
        loadingMessages.value = true;

        ticketMessages.value = [];
    }

    try {
        const data = await getTicketDetail(ticketId);

        if (data.data) {
            if (data.data.message && Array.isArray(data.data.message)) {
                ticketMessages.value = data.data.message.map((msg) => ({
                    ...msg,

                    is_admin: !msg.is_me
                }));
            } else {
                ticketMessages.value = [];
            }

            if (selectedTicket.value && selectedTicket.value.id === ticketId) {
                if (data.data.status !== undefined) {
                    selectedTicket.value.status = data.data.status;

                    if (data.data.status === 1) {
                        clearRefreshInterval();
                    }
                }
            }
        }
    } catch (error) {
        console.error('Failed to fetch ticket details:', error);

        showToast(
            error.response?.message ||
                error.message ||
                t('tickets.fetchDetailError'),
            'error'
        );
    } finally {
        if (!isAutoRefresh) {
            loadingMessages.value = false;
        }
    }
};

const clearRefreshInterval = () => {
    if (refreshInterval.value) {
        clearInterval(refreshInterval.value);

        refreshInterval.value = null;
    }
};

const setupRefreshInterval = (ticketId) => {
    clearRefreshInterval();

    if (selectedTicket.value && selectedTicket.value.status === 0) {
        refreshInterval.value = setInterval(() => {
            if (selectedTicket.value && selectedTicket.value.id === ticketId) {
                fetchTicketDetail(ticketId, true);
            } else {
                clearRefreshInterval();
            }
        }, 5000);
    }
};

const selectTicket = async (ticket) => {
    clearRefreshInterval();

    selectedTicket.value = ticket;

    await fetchTicketDetail(ticket.id);

    if (ticket.status === 0) {
        setupRefreshInterval(ticket.id);
    }
};

const backToList = () => {
    clearRefreshInterval();

    const detailElement = document.querySelector('.ticket-detail');

    if (detailElement) {
        detailElement.classList.add('closing');

        setTimeout(() => {
            selectedTicket.value = null;
        }, 280);
    } else {
        selectedTicket.value = null;
    }
};

const fetchTickets = async () => {
    loading.value = true;

    try {
        const result = await fetchTicketList();

        tickets.value = Array.isArray(result.data) ? result.data : [];
    } catch (error) {
        console.error('Failed to fetch tickets:', error);

        showToast(
            error.response?.message || error.message || t('tickets.fetchError'),
            'error'
        );
    } finally {
        loading.value = false;
    }
};

const sendReply = async () => {
    if (
        !replyMessage.value.trim() ||
        !selectedTicket.value ||
        sendingReply.value
    )
        return;

    sendingReply.value = true;

    try {
        const data = await replyTicket(
            selectedTicket.value.id,
            replyMessage.value
        );

        if (data.data) {
            showToast(data.message || t('tickets.replySent'), 'success');

            replyMessage.value = '';

            await fetchTicketDetail(selectedTicket.value.id, true);
        }
    } catch (error) {
        console.error('Failed to send reply:', error);

        showToast(
            error.response?.message || error.message || t('tickets.replyError'),
            'error'
        );
    } finally {
        sendingReply.value = false;
    }
};

const showCloseConfirm = () => {
    showCloseTicketModal.value = true;
};

const closeConfirmModal = () => {
    const modalContent = document.querySelector('.modal-content');

    if (modalContent) {
        modalContent.classList.add('closing');

        setTimeout(() => {
            showCloseTicketModal.value = false;

            closingTicket.value = false;
        }, 300);
    } else {
        showCloseTicketModal.value = false;

        closingTicket.value = false;
    }
};

const closeSelectedTicket = async () => {
    if (!selectedTicket.value) return;

    closingTicket.value = true;

    try {
        const data = await closeTicket(selectedTicket.value.id);

        if (data.data === true) {
            showToast(data.message || t('tickets.closeSuccess'), 'success');

            showCloseTicketModal.value = false;

            selectedTicket.value.status = 1;

            await fetchTickets();

            clearRefreshInterval();

            backToList();
        }
    } catch (error) {
        console.error('Failed to close ticket:', error);

        showToast(
            error.response?.message || error.message || t('tickets.closeError'),
            'error'
        );
    } finally {
        closingTicket.value = false;
    }
};

const submitTicket = async () => {
    if (!newTicket.value.subject || !newTicket.value.message) {
        showToast(t('tickets.formIncomplete'), 'error');

        return;
    }

    submitting.value = true;

    try {
        let messageContent = newTicket.value.message;

        if (TICKET_CONFIG.includeUserInfoInTicket) {
            const [
                userInfoResponse,
                commConfigResponse,
                subscribeResponse,
                ipInfoResponse
            ] = await Promise.all([
                getUserInfo(),

                getCommConfig(),

                getUserSubscribe(),

                getIpLocationInfo().catch((error) => {
                    console.warn('Failed to get ticket IP location info:', error);

                    return null;
                })
            ]);

            if (
                commConfigResponse &&
                commConfigResponse.data &&
                commConfigResponse.data.currency_symbol
            ) {
                userInfoResponse.currency_symbol =
                    commConfigResponse.data.currency_symbol;
            }

            const userInfoText = formatUserInfoForTicket(
                userInfoResponse,

                ipInfoResponse,

                subscribeResponse
            );

            messageContent = `${newTicket.value.message}\n\n${userInfoText}`;
        }

        const data = await createTicket({
            subject: newTicket.value.subject,

            message: messageContent,

            level: parseInt(newTicket.value.level)
        });

        if (data.data) {
            showToast(data.message || t('tickets.createSuccess'), 'success');

            closeModal();

            await fetchTickets();

            newTicket.value = {
                subject: '',

                message: '',

                level: '0'
            };
        }
    } catch (error) {
        console.error('Failed to create ticket:', error);

        showToast(
            error.response?.message ||
                error.message ||
                t('tickets.createError'),
            'error'
        );
    } finally {
        submitting.value = false;
    }
};

const checkTicketPopup = () => {
    if (!ticketPopupCfg.enabled) return;

    const lastClose = Number(
        localStorage.getItem('ticket_popup_close_time') || 0
    );

    const cooldownMs = (ticketPopupCfg.cooldownHours || 0) * 3600 * 1000;

    if (!lastClose || Date.now() - lastClose >= cooldownMs) {
        showTicketPopup.value = true;
    }
};

const handleTicketPopupClose = () => {
    showTicketPopup.value = false;
};

fetchTickets();

// 上传相关
const uploadedImages = ref([]);
const uploadingImages = ref(false);
const draggingImage = ref(false);
const imageInput = ref(null);

const newTicketTextarea = ref(null);
const caretPos = ref(0);

// 记录光标位置
const updateCaret = () => {
    const ta = newTicketTextarea.value;
    if (!ta) return;
    caretPos.value = ta.selectionStart ?? newTicket.value.message.length;
};

// 在光标处插入文本（支持选中覆盖）
const insertAtCursor = async (text) => {
    const ta = newTicketTextarea.value;
    const value = newTicket.value.message || '';
    if (!ta) {
        // 没有拿到DOM，直接末尾追加
        newTicket.value.message =
            value + (value && !value.endsWith('\n') ? '\n' : '') + text + '\n';
        return;
    }
    const start = ta.selectionStart ?? caretPos.value ?? value.length;
    const end = ta.selectionEnd ?? start;
    newTicket.value.message = value.slice(0, start) + text + value.slice(end);
    await nextTick();
    const pos = start + text.length;
    ta.focus();
    ta.setSelectionRange(pos, pos);
    caretPos.value = pos;
};

// 点击缩略图时插入 Markdown
const insertImage = (url) => {
    const md = `![image](${url})`;
    // 避免重复插入同一URL（可选）
    if ((newTicket.value.message || '').includes(url)) return;
    insertAtCursor(md);
};

const IMGBB_API_URL = 'https://api.imgbb.com/1/upload';
const IMGBB_API_KEY = TICKET_CONFIG.imgbbApiKey;

const triggerImageInput = () => {
    imageInput.value && imageInput.value.click();
};

const onDropImage = async (e) => {
    draggingImage.value = false;

    const files = Array.from(e.dataTransfer.files).filter((f) =>
        f.type.startsWith('image/')
    );

    if (files.length) {
        await handleImageUpload({ target: { files } });
    }
};

const handleImageUpload = async (e) => {
    const files = Array.from(e.target.files);
    if (!files.length) return;
    uploadingImages.value = true;

    for (const file of files) {
        if (file.size > 5 * 1024 * 1024) {
            showToast('图片不能超过 5MB', 'error');
            continue;
        }

        try {
            // 转成 base64
            const base64 = await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result.split(',')[1]);
                reader.onerror = reject;
                reader.readAsDataURL(file);
            });

            const formData = new FormData();
            formData.append('image', base64);

            const res = await fetch(`${IMGBB_API_URL}?key=${IMGBB_API_KEY}`, {
                method: 'POST',
                body: formData
            });

            const result = await res.json();
            if (result.success && result.data && result.data.url) {
                uploadedImages.value.push(result.data.url);
                newTicket.value.message += `\n![image](${result.data.url})`;
            } else {
                showToast(result.error?.message || '图片上传失败', 'error');
            }
        } catch (err) {
            console.error(err);
            showToast('图片上传异常', 'error');
        }
    }

    uploadingImages.value = false;
};

// ========= 回复区上传图片 =========
const replyImageInput = ref(null);
const replyTextarea = ref(null);
const uploadingReplyImages = ref(false);
const replyCaretPos = ref(0);

const triggerReplyImageInput = () => {
    replyImageInput.value && replyImageInput.value.click();
};

const updateReplyCaret = () => {
    const ta = replyTextarea.value;
    if (!ta) return;
    replyCaretPos.value = ta.selectionStart ?? replyMessage.value.length;
};

const insertAtCursorToReply = async (text) => {
    const ta = replyTextarea.value;
    const value = replyMessage.value || '';
    if (!ta) {
        replyMessage.value =
            value + (value && !value.endsWith('\n') ? '\n' : '') + text + '\n';
        return;
    }
    const start = ta.selectionStart ?? replyCaretPos.value ?? value.length;
    const end = ta.selectionEnd ?? start;
    replyMessage.value = value.slice(0, start) + text + value.slice(end);
    await nextTick();
    const pos = start + text.length;
    ta.focus();
    ta.setSelectionRange(pos, pos);
    replyCaretPos.value = pos;
};

const fileToBase64 = (file) =>
    new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result.split(',')[1]);
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });

const uploadToImgbb = async (file) => {
    const b64 = await fileToBase64(file);
    const fd = new FormData();
    fd.append('image', b64);
    const res = await fetch(`${IMGBB_API_URL}?key=${IMGBB_API_KEY}`, {
        method: 'POST',
        body: fd
    });
    const json = await res.json();
    if (json.success && json.data?.url) return json.data.url;
    throw new Error(json.error?.message || 'imgbb 上传失败');
};

const handleReplyImageUpload = async (e) => {
    const files = Array.from(e.target.files || []);
    if (!files.length) return;
    uploadingReplyImages.value = true;

    try {
        for (const file of files) {
            if (file.size > 5 * 1024 * 1024) {
                showToast('图片不能超过 5MB', 'error');
                continue;
            }
            const url = await uploadToImgbb(file);
            const md = `![image](${url})`;
            await insertAtCursorToReply(md);
        }
        showToast(t('tickets.uploadSuccess') || '图片上传成功', 'success');
    } catch (err) {
        console.error(err);
        showToast(err.message || '图片上传异常', 'error');
    } finally {
        uploadingReplyImages.value = false;
        // 清空 input 值，避免同一文件无法再次触发 change
        if (replyImageInput.value) replyImageInput.value.value = '';
    }
};
// ==================

const addImageToInput = (imgUrl) => {
    newTicket.value.message += `\n![image](${imgUrl})`;
};
</script>

<style lang="scss" scoped>
.mobile-ticket-container {
    height: 100%;

    display: flex;

    flex-direction: column;

    padding: 20px;

    padding-bottom: 90px;

    position: relative;
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

.create-ticket-wrapper {
    padding: 0;

    margin-bottom: 1.5rem;

    background-color: var(--theme-color);

    border-radius: 10px;

    box-shadow: 0 4px 12px rgba(var(--theme-color-rgb), 0.2);

    margin: 0 0 1.5rem;

    transition: all 0.3s ease;

    overflow: hidden;

    &:hover {
        transform: translateY(-2px);

        box-shadow: 0 6px 15px rgba(var(--theme-color-rgb), 0.3);
    }

    .new-ticket-btn {
        width: 100%;

        display: flex;

        align-items: center;

        justify-content: center;

        gap: 0.75rem;

        padding: 0.85rem;

        background-color: transparent;

        color: white;

        border: none;

        font-size: 1rem;

        font-weight: 500;

        cursor: pointer;

        transition: all 0.3s ease;

        &:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        &:active {
            background-color: rgba(255, 255, 255, 0.2);
        }
    }
}

.search-bar {
    display: none;
}

.loading-state,
.empty-state {
    display: flex;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    padding: 3rem 1.5rem;

    text-align: center;

    color: var(--text-muted);

    margin: 1rem 0;

    background-color: var(--card-bg);

    border-radius: 12px;

    border: 1px dashed var(--border-color);

    p {
        margin: 1.25rem 0;

        font-size: 1rem;

        line-height: 1.5;
    }

    .create-ticket-btn {
        margin-top: 1rem;

        padding: 0.75rem 1.75rem;

        border-radius: 8px;

        background-color: var(--theme-color);

        color: white;

        border: none;

        font-size: 0.95rem;

        font-weight: 500;

        cursor: pointer;

        transition: all 0.3s ease;

        box-shadow: 0 4px 12px rgba(var(--theme-color-rgb), 0.2);

        display: flex;

        align-items: center;

        gap: 0.5rem;

        &:hover {
            background-color: rgba(var(--theme-color-rgb), 0.9);

            transform: translateY(-2px);

            box-shadow: 0 6px 15px rgba(var(--theme-color-rgb), 0.3);
        }

        &:active {
            transform: translateY(0);
        }
    }
}

.tickets-content {
    padding: 0;

    margin-top: 0.5rem;

    margin-bottom: 1rem;
}

.ticket-item {
    background-color: var(--card-bg);

    border: 1px solid var(--border-color);

    border-radius: 10px;

    padding: 1.25rem;

    margin-bottom: 1rem;

    cursor: pointer;

    transition: all 0.3s ease;

    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);

    &:hover {
        border-color: var(--theme-color);

        transform: translateY(-3px);

        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }

    &:active {
        transform: translateY(-1px);
    }

    .ticket-header {
        display: flex;

        justify-content: space-between;

        align-items: flex-start;

        margin-bottom: 0.75rem;

        .ticket-subject {
            font-weight: 600;

            color: var(--text-color);

            flex: 1;

            margin-right: 1rem;

            font-size: 1rem;

            line-height: 1.4;
        }

        .ticket-status {
            font-size: 0.8rem;

            padding: 0.35rem 0.75rem;

            border-radius: 6px;

            font-weight: 500;

            white-space: nowrap;

            &.status-0 {
                background-color: rgba(76, 175, 80, 0.1);

                color: #4caf50;
            }

            &.status-1 {
                background-color: rgba(158, 158, 158, 0.1);

                color: #9e9e9e;
            }
        }
    }

    .ticket-info {
        display: flex;

        justify-content: space-between;

        font-size: 0.85rem;

        color: var(--text-muted);

        padding-top: 0.5rem;

        border-top: 1px dashed rgba(var(--border-color-rgb), 0.5);
    }
}

.ticket-detail {
    padding-top: 0;

    flex: 1;

    display: flex;

    flex-direction: column;

    background-color: var(--card-background, rgba(30, 30, 30, 0.8));

    position: fixed;

    top: 0;

    left: 0;

    right: 0;

    bottom: 0;

    z-index: 20;

    animation: slideInRight 0.3s ease;

    backdrop-filter: blur(5px);

    -webkit-backdrop-filter: blur(5px);

    &.closing {
        animation: slideOutRight 0.3s ease forwards;
    }
}

.detail-header {
    padding: 1rem;

    background-color: var(--card-bg);

    border-bottom: 1px solid var(--border-color);

    display: flex;

    align-items: center;

    gap: 1rem;

    position: sticky;

    top: 0;

    z-index: 10;

    margin-top: 70px;

    backdrop-filter: blur(10px);

    -webkit-backdrop-filter: blur(10px);

    .back-btn {
        background: none;

        border: none;

        color: var(--text-color);

        padding: 0.5rem;

        border-radius: 8px;

        cursor: pointer;

        display: flex;

        align-items: center;

        justify-content: center;

        transition: all 0.3s ease;

        animation: fadeInLeft 0.4s ease;

        &:hover {
            background-color: rgba(var(--theme-color-rgb), 0.1);

            color: var(--theme-color);
        }
    }

    .ticket-info {
        flex: 1;

        min-width: 0;

        h2 {
            margin: 0;

            font-size: 1.1rem;

            margin-bottom: 0.5rem;

            white-space: nowrap;

            overflow: hidden;

            text-overflow: ellipsis;

            font-weight: 600;

            color: var(--text-color);
        }

        .meta-info {
            display: flex;

            gap: 0.5rem;

            flex-wrap: wrap;
        }
    }

    .close-ticket-btn {
        background: none;

        border: none;

        color: #f44336;

        padding: 0.5rem;

        border-radius: 8px;

        cursor: pointer;

        display: flex;

        align-items: center;

        justify-content: center;

        transition: all 0.3s ease;

        &:hover {
            background-color: rgba(244, 67, 54, 0.1);
        }
    }
}

.status-badge,
.level-badge {
    display: inline-block;

    padding: 0.35rem 0.75rem;

    border-radius: 6px;

    font-size: 0.85rem;

    font-weight: 500;
}

.status-badge {
    &.status-0 {
        background-color: rgba(76, 175, 80, 0.1);

        color: #4caf50;
    }

    &.status-1 {
        background-color: rgba(158, 158, 158, 0.1);

        color: #9e9e9e;
    }
}

.level-badge {
    &.level-0 {
        background-color: rgba(33, 150, 243, 0.1);

        color: #2196f3;
    }

    &.level-1 {
        background-color: rgba(255, 152, 0, 0.1);

        color: #ff9800;
    }

    &.level-2 {
        background-color: rgba(244, 67, 54, 0.1);

        color: #f44336;
    }
}

.messages-container {
    flex: 1;

    overflow-y: auto;

    padding: 1.25rem;

    display: flex;

    flex-direction: column;

    background-color: var(--card-background, rgba(30, 30, 30, 0.8));

    .loading-state {
        flex: 1;

        display: flex;

        flex-direction: column;

        align-items: center;

        justify-content: center;

        padding: 3rem 1.5rem;

        text-align: center;

        color: var(--text-color);

        margin: 1rem 0;

        background-color: var(--card-bg);

        border-radius: 12px;

        border: 1px dashed var(--border-color);

        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);

        p {
            margin: 1.25rem 0;

            font-size: 1rem;

            line-height: 1.5;

            color: var(--text-muted);
        }
    }
}

.empty-messages {
    flex: 1;

    display: flex;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    color: var(--text-muted);

    padding: 2.5rem;

    text-align: center;

    background-color: var(--card-bg);

    border-radius: 12px;

    border: 1px dashed var(--border-color);

    margin: 1rem 0;

    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);

    .icon {
        margin-bottom: 1.25rem;

        opacity: 0.7;
    }

    p {
        font-size: 1rem;

        line-height: 1.5;
    }
}

.message-list {
    display: flex;

    flex-direction: column;

    gap: 1.25rem;

    padding-bottom: 1rem;

    margin-top: 0.5rem;
}

.message-item {
    display: flex;

    gap: 0.75rem;

    max-width: 85%;

    animation: fadeInUp 0.4s ease;

    &.admin-message {
        align-self: flex-start;

        .message-content {
            background-color: var(--card-bg);

            border-radius: 0 12px 12px 12px;

            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .message-avatar {
            background-color: #f44336;
        }
    }

    &.user-message {
        align-self: flex-end;

        flex-direction: row-reverse;

        .message-content {
            background-color: var(--theme-color);

            color: white;

            border-radius: 12px 0 12px 12px;

            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);

            .message-header {
                .sender-name,
                .message-time {
                    color: rgba(255, 255, 255, 0.9);
                }
            }
        }

        .message-avatar {
            background-color: var(--theme-color);
        }
    }
}

.message-avatar {
    width: 36px;

    height: 36px;

    border-radius: 50%;

    display: flex;

    align-items: center;

    justify-content: center;

    color: white;

    flex-shrink: 0;

    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}

.message-content {
    padding: 1rem;

    border-radius: 12px;

    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);

    .message-header {
        display: flex;

        justify-content: space-between;

        align-items: center;

        margin-bottom: 0.5rem;

        font-size: 0.85rem;

        .sender-name {
            font-weight: 600;
        }

        .message-time {
            color: var(--text-muted);
        }
    }

    .message-text {
        font-size: 0.95rem;

        line-height: 1.6;

        white-space: pre-wrap;

        word-break: break-word;
    }
}

.reply-box {
    padding: 1.25rem;

    background-color: var(--card-bg);

    border-top: 1px solid var(--border-color);

    display: flex;

    flex-direction: column;

    gap: 1rem;

    margin-bottom: 80px;

    position: relative;

    z-index: 5;

    backdrop-filter: blur(10px);

    -webkit-backdrop-filter: blur(10px);

    textarea {
        width: 100%;

        padding: 1rem;

        border: 1px solid var(--border-color);

        border-radius: 12px;

        background-color: var(--bg-secondary);

        color: var(--text-color);

        font-size: 0.95rem;

        resize: none;

        transition: all 0.3s ease;

        min-height: 100px;

        &:focus {
            outline: none;

            border-color: var(--theme-color);

            box-shadow: 0 2px 8px rgba(var(--theme-color-rgb), 0.1);
        }
    }

    .send-btn {
        align-self: flex-end;

        display: flex;

        align-items: center;

        gap: 0.75rem;

        padding: 0.75rem 1.5rem;

        border-radius: 8px;

        background-color: var(--theme-color);

        color: white;

        border: none;

        font-size: 0.95rem;

        font-weight: 500;

        cursor: pointer;

        transition: all 0.3s ease;

        box-shadow: 0 4px 12px rgba(var(--theme-color-rgb), 0.2);

        &:hover:not(:disabled) {
            background-color: rgba(var(--theme-color-rgb), 0.9);

            transform: translateY(-2px);

            box-shadow: 0 6px 15px rgba(var(--theme-color-rgb), 0.3);
        }

        &:active:not(:disabled) {
            transform: translateY(0);
        }

        &:disabled {
            opacity: 0.7;

            cursor: not-allowed;
        }

        .loader {
            width: 18px;

            height: 18px;

            border: 2px solid rgba(255, 255, 255, 0.3);

            border-radius: 50%;

            border-top-color: white;

            animation: spin 1s linear infinite;
        }
    }
}

.ticket-closed-notice {
    padding: 1.25rem;

    background-color: var(--card-bg);

    border-top: 1px solid var(--border-color);

    display: flex;

    align-items: center;

    justify-content: center;

    gap: 0.75rem;

    color: var(--text-muted);

    font-size: 1rem;

    margin-bottom: 80px;

    position: relative;

    z-index: 5;

    backdrop-filter: blur(10px);

    -webkit-backdrop-filter: blur(10px);
}

.modal-overlay {
    position: fixed;

    top: 0;

    left: 0;

    right: 0;

    bottom: 0;

    background-color: rgba(0, 0, 0, 0.6);

    backdrop-filter: blur(6px);

    display: flex;

    justify-content: center;

    align-items: center;

    z-index: 1000;

    animation: fadeIn 0.3s ease;

    padding: 1rem;
}

.modal-content {
    width: 90%;

    max-width: 500px;

    background-color: var(--card-background);

    border-radius: 16px;

    overflow: hidden;

    animation: slideIn 0.3s ease;

    border: 1px solid var(--border-color);

    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);

    &.closing {
        animation: slideOut 0.3s ease forwards;
    }
}

.modal-header {
    padding: 1.5rem;

    border-bottom: 1px solid var(--border-color);

    display: flex;

    justify-content: space-between;

    align-items: center;

    background-color: var(--bg-color);

    h3 {
        margin: 0;

        font-size: 1.25rem;

        font-weight: 600;

        color: var(--text-color);
    }

    .close-btn {
        background: none;

        border: none;

        color: var(--text-muted);

        cursor: pointer;

        padding: 0.5rem;

        display: flex;

        align-items: center;

        justify-content: center;

        border-radius: 50%;

        transition: all 0.3s ease;

        &:hover {
            background-color: rgba(var(--theme-color-rgb), 0.1);

            color: var(--theme-color);
        }
    }
}

.modal-body {
    padding: 1.5rem;

    background-color: var(--bg-color);

    p {
        color: var(--text-color);

        font-size: 1rem;

        line-height: 1.6;

        margin-bottom: 1.5rem;
    }

    .form-group {
        margin-bottom: 1.5rem;

        label {
            display: block;

            margin-bottom: 0.75rem;

            font-weight: 500;

            color: var(--text-color);

            font-size: 1rem;
        }

        input,
        textarea {
            width: 100%;

            padding: 0.85rem 1rem;

            border: 1px solid var(--border-color);

            border-radius: 10px;

            background-color: var(--card-bg);

            color: var(--text-color);

            font-size: 1rem;

            transition: all 0.3s ease;

            &:focus {
                outline: none;

                border-color: var(--theme-color);

                box-shadow: 0 0 0 3px rgba(var(--theme-color-rgb), 0.1);
            }

            &::placeholder {
                color: var(--text-muted);
            }
        }

        textarea {
            resize: vertical;

            min-height: 140px;
        }
    }

    .priority-selector {
        display: flex;

        gap: 0.75rem;

        .priority-btn {
            flex: 1;

            padding: 0.85rem;

            border: 1px solid var(--border-color);

            border-radius: 10px;

            background-color: var(--card-bg);

            color: var(--text-color);

            cursor: pointer;

            transition: all 0.3s ease;

            font-weight: 500;

            font-size: 0.95rem;

            &.active {
                background-color: var(--theme-color);

                color: white;

                border-color: var(--theme-color);

                box-shadow: 0 4px 10px rgba(var(--theme-color-rgb), 0.25);
            }

            &:hover:not(.active) {
                background-color: var(--bg-color);

                border-color: var(--theme-color);

                color: var(--theme-color);
            }
        }
    }
}

.modal-footer {
    padding: 1.5rem;

    border-top: 1px solid var(--border-color);

    display: flex;

    justify-content: flex-end;

    gap: 1rem;

    background-color: var(--bg-color);

    button {
        padding: 0.85rem 1.75rem;

        border-radius: 10px;

        font-size: 1rem;

        font-weight: 500;

        cursor: pointer;

        transition: all 0.3s ease;

        &.cancel-btn {
            background-color: var(--card-bg);

            border: 1px solid var(--border-color);

            color: var(--text-color);

            &:hover {
                background-color: var(--bg-secondary);

                border-color: var(--text-muted);
            }
        }

        &.submit-btn {
            background-color: var(--theme-color);

            color: white;

            border: none;

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 0.75rem;

            box-shadow: 0 4px 10px rgba(var(--theme-color-rgb), 0.25);

            &:hover:not(:disabled) {
                background-color: rgba(var(--theme-color-rgb), 0.9);

                transform: translateY(-2px);

                box-shadow: 0 6px 15px rgba(var(--theme-color-rgb), 0.3);
            }

            &:active:not(:disabled) {
                transform: translateY(0);
            }

            &:disabled {
                opacity: 0.7;

                cursor: not-allowed;
            }

            .loader {
                width: 18px;

                height: 18px;

                border: 2px solid rgba(255, 255, 255, 0.3);

                border-radius: 50%;

                border-top-color: white;

                animation: spin 1s linear infinite;
            }
        }
    }
}

@keyframes spin {
    0% {
        transform: rotate(0deg);
    }

    100% {
        transform: rotate(360deg);
    }
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }

    to {
        opacity: 1;
    }
}

@keyframes slideIn {
    from {
        transform: translateY(-30px);

        opacity: 0;
    }

    to {
        transform: translateY(0);

        opacity: 1;
    }
}

@keyframes slideOut {
    from {
        transform: translateY(0);

        opacity: 1;
    }

    to {
        transform: translateY(30px);

        opacity: 0;
    }
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);

        opacity: 0;
    }

    to {
        transform: translateX(0);

        opacity: 1;
    }
}

@keyframes slideOutRight {
    from {
        transform: translateX(0);

        opacity: 1;
    }

    to {
        transform: translateX(100%);

        opacity: 0;
    }
}

@keyframes scaleIn {
    from {
        transform: scale(0.9);

        opacity: 0;
    }

    to {
        transform: scale(1);

        opacity: 1;
    }
}

@keyframes fadeInLeft {
    from {
        transform: translateX(-10px);

        opacity: 0;
    }

    to {
        transform: translateX(0);

        opacity: 1;
    }
}

@keyframes fadeInUp {
    from {
        transform: translateY(10px);

        opacity: 0;
    }

    to {
        transform: translateY(0);

        opacity: 1;
    }
}

.screen-size-notice {
    position: fixed;

    top: 0;

    left: 0;

    right: 0;

    bottom: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    background-color: var(--bg-color);

    z-index: 1000;

    padding: 1.5rem;

    animation: fadeIn 0.4s ease;

    .notice-content {
        text-align: center;

        background-color: var(--card-bg);

        padding: 2.5rem;

        border-radius: 16px;

        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);

        max-width: 90%;

        width: 450px;

        border: 1px solid var(--border-color);

        animation: scaleIn 0.5s ease;

        h2 {
            margin: 1.25rem 0;

            font-size: 1.5rem;

            color: var(--text-color);

            font-weight: 600;
        }

        p {
            color: var(--text-muted);

            margin-bottom: 2rem;

            font-size: 1.05rem;

            line-height: 1.6;
        }

        .switch-btn {
            padding: 0.85rem 1.75rem;

            border-radius: 10px;

            background-color: var(--theme-color);

            color: white;

            border: none;

            font-size: 1.05rem;

            font-weight: 500;

            cursor: pointer;

            transition: all 0.3s ease;

            display: inline-flex;

            align-items: center;

            gap: 0.75rem;

            box-shadow: 0 6px 15px rgba(var(--theme-color-rgb), 0.25);

            &:hover {
                background-color: rgba(var(--theme-color-rgb), 0.9);

                transform: translateY(-1px);

                box-shadow: 0 8px 20px rgba(var(--theme-color-rgb), 0.35);
            }

            &:active {
                transform: translateY(-1px);

                box-shadow: 0 4px 10px rgba(var(--theme-color-rgb), 0.25);
            }
        }
    }
}

.confirm-close-btn.danger {
    background-color: #f44336;

    color: white;

    border: none;

    &:hover {
        background-color: #d32f2f;
    }

    &:disabled {
        background-color: #fbb4af;

        cursor: not-allowed;
    }
}

.btn-loading {
    display: flex;

    align-items: center;

    justify-content: center;

    gap: 6px;

    .spinner {
        display: inline-block;

        width: 16px;

        height: 16px;

        border: 2px solid rgba(255, 255, 255, 0.3);

        border-radius: 50%;

        border-top-color: #fff;

        animation: spin 1s linear infinite;

        margin-right: 4px;
    }
}

.image-upload-area {
    border: 2px dashed #2196f3;

    border-radius: 12px;

    background: rgba(33, 150, 243, 0.03);

    padding: 18px 0;

    text-align: center;

    cursor: pointer;

    margin-top: 10px;

    transition: border-color 0.2s, background 0.2s;

    position: relative;
}

.upload-icon {
    margin-bottom: 8px;
}

.upload-tip {
    font-size: 0.95rem;

    margin-bottom: 4px;
}

.upload-tip-text {
    color: #333;

    font-weight: 500;

    font-size: 1rem;

    transition: color 0.2s;
}

.upload-desc {
    display: block;

    font-size: 0.92rem;

    color: #888;

    margin-top: 2px;
}

.uploaded-images-list {
    margin-top: 8px;

    display: flex;

    gap: 8px;

    flex-wrap: wrap;
}

.uploaded-image-thumb img {
    transition: box-shadow 0.2s;

    box-shadow: 0 2px 8px rgba(33, 150, 243, 0.08);
}

.uploaded-image-thumb img:hover {
    box-shadow: 0 4px 16px rgba(33, 150, 243, 0.18);

    border-color: #2196f3;
}

@media (prefers-color-scheme: dark) {
    .image-upload-area {
        background: rgba(33, 150, 243, 0.09);

        border-color: #90caf9;
    }

    .upload-tip-text {
        color: var(--text-color);
    }

    .upload-desc {
        color: #b0bec5;
    }
}
</style>
