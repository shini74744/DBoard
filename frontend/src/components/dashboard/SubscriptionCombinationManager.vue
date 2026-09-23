<template>
  <section v-if="packages.length > 1" class="combination-entry">
    <button type="button" class="combination-open" @click="open">合并订阅</button>
    <teleport to="body">
      <div v-if="visible" class="combination-backdrop" @click.self="close">
        <section class="combination-dialog" role="dialog" aria-modal="true" aria-labelledby="combination-title">
          <header class="combination-head">
            <div><h2 id="combination-title">合并订阅</h2><p>选择套餐，生成一条可重复使用的订阅链接。</p></div>
            <button type="button" class="close-button" aria-label="关闭" @click="close">×</button>
          </header>
          <div class="combination-body">
            <p v-if="error" class="combination-error" role="alert">{{ error }}</p>
            <form class="combination-form" @submit.prevent="save">
              <label class="field-label" for="combination-name">组合名称</label>
              <input id="combination-name" v-model.trim="name" maxlength="60" placeholder="例如：日常使用" required/>
              <span class="field-label">选择套餐（至少两份）</span>
              <div class="package-options">
                <label v-for="item in packages" :key="item.id" class="package-option">
                  <input v-model="selectedIds" type="checkbox" :value="item.id"/>
                  <span><strong>{{ item.display_name || item.plan_name || '套餐' }}</strong>
                    <small>{{ item.active ? '可用' : '暂不可用' }} · 剩余 {{ formatTraffic(item.remaining) }}</small>
                  </span>
                </label>
              </div>
              <p class="selection-preview">已选 {{ selectedIds.length }} 份 · 当前可用 {{ selectedActive.length }} 份 · 合计剩余 {{ formatTraffic(selectedActive.reduce((sum, item) => sum + Math.max(0, Number(item.remaining) || 0), 0)) }}</p>
              <fieldset class="duplicate-options">
                <legend>相同节点如何显示</legend>
                <label><input v-model="duplicateMode" type="radio" value="all"/> 每份套餐分别保留</label>
                <label><input v-model="duplicateMode" type="radio" value="first"/> 重叠节点只留第一份（只使用这份套餐的流量）</label>
              </fieldset>
              <p class="combination-note">每份套餐独立计流量和到期；过期或流量用尽后，其节点会自动退出此链接。</p>
              <div class="form-actions">
                <button v-if="editingId" type="button" class="secondary-button" @click="resetForm">取消编辑</button>
                <button type="submit" class="primary-button" :disabled="busy || selectedIds.length < 2">{{ busy ? '保存中…' : (editingId ? '保存修改' : '生成组合订阅') }}</button>
              </div>
            </form>
            <section class="saved-combinations">
              <h3>已保存的组合</h3>
              <p v-if="loading">正在读取…</p>
              <p v-else-if="!combinations.length">还没有组合订阅。</p>
              <article v-for="item in combinations" :key="item.id" class="saved-item">
                <div class="saved-heading">
                  <strong>{{ item.name }}</strong>
                  <small>{{ item.package_ids.length }} 份套餐 · {{ item.duplicate_node_mode === 'first' ? '同名节点只留一条' : '同名节点分别保留' }}</small>
                </div>
                <p>{{ item.package_ids.map(id => packageLabel(id)).join('、') }}</p>
                <div class="saved-actions">
                  <button type="button" @click="copy(item)">{{ copiedId === item.id ? '已复制' : '复制链接' }}</button>
                  <button type="button" @click="importCombination(item)">导入订阅</button>
                  <button type="button" @click="edit(item)">编辑</button>
                  <button type="button" class="delete-button" :disabled="busy" @click="remove(item)">删除</button>
                </div>
              </article>
            </section>
          </div>
        </section>
      </div>
    </teleport>
  </section>
</template>

<script setup>
import {computed, nextTick, onBeforeUnmount, onMounted, ref} from 'vue';
import {getSubscriptionCombinations, saveSubscriptionCombination, deleteSubscriptionCombination} from '@/api/dashboard';

const props = defineProps({
  subscriptions: {type: Array, required: true},
  formatTraffic: {type: Function, required: true}
});
const emit = defineEmits(['import']);
const packages = computed(() => props.subscriptions.filter(item => item.plan_id != null));
const selectedActive = computed(() => packages.value.filter(item => item.active && selectedIds.value.includes(item.id)));
const visible = ref(false);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const combinations = ref([]);
const copiedId = ref(null);
const selectedIds = ref([]);
const name = ref('');
const duplicateMode = ref('all');
const editingId = ref(null);
let previousOverflow = '';

function resetForm() {
  editingId.value = null;
  name.value = '组合订阅 ' + (combinations.value.length + 1);
  selectedIds.value = packages.value.filter(item => item.active).map(item => item.id);
  duplicateMode.value = 'all';
  error.value = '';
}
async function load() {
  loading.value = true;
  try {
    const response = await getSubscriptionCombinations();
    combinations.value = Array.isArray(response.data) ? response.data : [];
  } catch (e) {
    error.value = e.response?.message || e.message || '读取组合订阅失败';
  } finally {
    loading.value = false;
  }
}
async function open() {
  visible.value = true;
  previousOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  resetForm();
  await load();
  await nextTick();
  document.getElementById('combination-name')?.focus();
}
function close() {
  visible.value = false;
  document.body.style.overflow = previousOverflow;
}
function packageLabel(id) {
  const item = packages.value.find(p => p.id === id);
  return item ? (item.display_name || item.plan_name || '套餐') : '已移除套餐';
}
function edit(item) {
  editingId.value = item.id;
  name.value = item.name;
  selectedIds.value = item.package_ids.filter(id => packages.value.some(p => p.id === id));
  duplicateMode.value = item.duplicate_node_mode;
  error.value = '';
  document.getElementById('combination-name')?.focus();
}

async function save() {
  error.value = '';
  if (selectedIds.value.length < 2) {
    error.value = '请至少选择两份套餐';
    return;
  }
  busy.value = true;
  try {
    const response = await saveSubscriptionCombination({
      ...(editingId.value ? {id: editingId.value} : {}),
      name: name.value,
      package_ids: JSON.stringify(selectedIds.value),
      duplicate_node_mode: duplicateMode.value
    });
    const saved = response.data;
    const index = combinations.value.findIndex(item => item.id === saved.id);
    if (index === -1) combinations.value.push(saved);
    else combinations.value.splice(index, 1, saved);
    resetForm();
  } catch (e) {
    error.value = e.response?.message || e.message || '保存失败，请重试';
  } finally {
    busy.value = false;
  }
}
async function remove(item) {
  if (!window.confirm('删除后，这条组合订阅链接将失效。确定删除？')) return;
  busy.value = true;
  error.value = '';
  try {
    await deleteSubscriptionCombination(item.id);
    combinations.value = combinations.value.filter(saved => saved.id !== item.id);
    if (editingId.value === item.id) resetForm();
  } catch (e) {
    error.value = e.response?.message || e.message || '删除失败，请重试';
  } finally {
    busy.value = false;
  }
}

async function copy(item) {
  error.value = '';
  try {
    await navigator.clipboard.writeText(item.subscribe_url);
    copiedId.value = item.id;
  } catch (e) {
    error.value = '复制失败，请检查浏览器剪贴板权限';
  }
}
function importCombination(item) {
  close();
  emit('import', {
    id: item.id,
    plan_name: item.name,
    subscribe_url: item.subscribe_url
  });
}
function onKeydown(event) {
  if (event.key === 'Escape' && visible.value) close();
}
onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown);
  if (visible.value) document.body.style.overflow = previousOverflow;
});
</script>

<style scoped>
.combination-entry { display: flex; justify-content: center; margin: -4px 0 26px; }
.combination-open, .primary-button { border: 1px solid var(--theme-color); border-radius: 10px; background: var(--theme-color); color: #fff; padding: 11px 24px; font: inherit; font-weight: 600; cursor: pointer; }
.combination-open:hover, .primary-button:hover { background: var(--primary-color-hover, var(--theme-color)); }
.combination-backdrop { position: fixed; inset: 0; z-index: 3200; padding: 18px; display: grid; place-items: center; background: rgba(10,20,40,.68); }
.combination-dialog { display: flex; flex-direction: column; width: min(100%, 720px); max-height: min(90svh, 850px); border: 1px solid var(--border-color); border-radius: 18px; background: var(--card-background, #fff); color: var(--text-color); box-shadow: 0 24px 70px #06132b55; }
.combination-head { display: flex; justify-content: space-between; gap: 16px; padding: 22px 24px 16px; border-bottom: 1px solid var(--border-color); }
.combination-head h2 { margin: 0; font-size: 22px; }
.combination-head p { margin: 6px 0 0; color: var(--secondary-text-color); font-size: 13px; }
.close-button { flex: none; width: 34px; height: 34px; border: 1px solid var(--border-color); border-radius: 8px; background: transparent; color: var(--text-color); font-size: 24px; cursor: pointer; }
.combination-body { min-height: 0; overflow-y: auto; padding: 20px 24px 24px; }
.combination-error { padding: 10px 12px; border-radius: 8px; color: #a11; background: #ffe9e9; }
.combination-form { display: grid; gap: 12px; }
.field-label, .duplicate-options legend { font-size: 14px; font-weight: 600; }
.combination-form > input { box-sizing: border-box; width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 9px; background: var(--card-bg-color, #fff); color: var(--text-color); font: inherit; }
.package-options { display: grid; gap: 8px; max-height: 220px; overflow-y: auto; padding: 2px; }
.package-option { display: flex; gap: 12px; align-items: center; min-width: 0; padding: 11px 12px; border: 1px solid var(--border-color); border-radius: 10px; cursor: pointer; }
.package-option:has(input:checked) { border-color: var(--theme-color); background: rgba(var(--theme-color-rgb), .06); }
.package-option input, .duplicate-options input { width: 17px; height: 17px; flex: none; accent-color: var(--theme-color); }
.package-option span { display: grid; gap: 3px; min-width: 0; overflow-wrap: anywhere; }
.package-option small, .saved-heading small { color: var(--secondary-text-color); }
.duplicate-options { display: flex; flex-wrap: wrap; gap: 12px 24px; margin: 0; padding: 12px 14px; border: 1px solid var(--border-color); border-radius: 10px; }
.duplicate-options label { display: inline-flex; align-items: center; gap: 7px; cursor: pointer; }
.selection-preview { margin: 0; color: var(--secondary-text-color); font-size: 13px; }
.combination-note { margin: 0; color: var(--secondary-text-color); font-size: 13px; }
.form-actions, .saved-actions { display: flex; flex-wrap: wrap; gap: 9px; }
.form-actions { justify-content: flex-end; }
.primary-button:disabled { opacity: .55; cursor: not-allowed; }
.secondary-button, .saved-actions button { padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--card-bg-color, #fff); color: var(--text-color); font: inherit; cursor: pointer; }
.saved-combinations { margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--border-color); }
.saved-combinations h3 { margin: 0 0 12px; font-size: 17px; }
.saved-item { padding: 14px; margin-top: 10px; border: 1px solid var(--border-color); border-radius: 11px; }
.saved-heading { display: flex; justify-content: space-between; gap: 10px; }
.saved-item p { margin: 8px 0 12px; color: var(--secondary-text-color); font-size: 13px; overflow-wrap: anywhere; }
.saved-actions .delete-button { color: #b52525; }
.combination-dialog button:focus-visible, .combination-dialog input:focus-visible, .combination-open:focus-visible { outline: 2px solid var(--theme-color); outline-offset: 2px; }
@media (max-width: 600px) { .combination-backdrop { padding: 8px; } .combination-dialog { max-height: 95svh; } .combination-head, .combination-body { padding: 16px; } .duplicate-options { display: grid; } }
</style>
