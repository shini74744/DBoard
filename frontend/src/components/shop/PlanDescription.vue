<template>
  <div class="plan-description" v-html="html"></div>
</template>

<script setup>
import { computed } from 'vue';
import MarkdownIt from 'markdown-it';
import DOMPurify from 'dompurify';

const props = defineProps({ content: { type: String, default: '' } });
// Existing HTML descriptions and Markdown templates use the same safe renderer.
const markdown = new MarkdownIt({ html: true, breaks: true, linkify: true });
const html = computed(() => DOMPurify.sanitize(markdown.render(props.content || '')));
</script>

<style scoped>
.plan-description {
  width: 100%;
  min-width: 0;
  font-size: 14px;
  line-height: 1.75;
  color: var(--text-color);
  overflow-wrap: anywhere;
}
.plan-description :deep(h1), .plan-description :deep(h2), .plan-description :deep(h3),
.plan-description :deep(h4), .plan-description :deep(h5), .plan-description :deep(h6) {
  margin: 18px 0 8px;
  font-size: 15px;
  line-height: 1.5;
  font-weight: 600;
}
.plan-description :deep(p) { margin: 8px 0; }
.plan-description :deep(ul), .plan-description :deep(ol) { margin: 8px 0; padding-left: 1.5em; }
.plan-description :deep(ul) { list-style: disc; }
.plan-description :deep(ol) { list-style: decimal; }
.plan-description :deep(li) { margin: 4px 0; }
.plan-description :deep(a) { color: var(--theme-color); text-decoration: underline; }
.plan-description :deep(img) { max-width: 100%; height: auto; }
.plan-description :deep(pre) { overflow-x: auto; padding: 10px; border-radius: 8px; background: rgba(128,128,128,.08); }
.plan-description :deep(table) { display: block; max-width: 100%; overflow-x: auto; border-collapse: collapse; }
.plan-description :deep(th), .plan-description :deep(td) { padding: 6px 10px; border: 1px solid rgba(128,128,128,.2); }
.plan-description :deep(blockquote) { margin: 10px 0; padding-left: 12px; border-left: 3px solid rgba(128,128,128,.3); }
.plan-description :deep(> :first-child) { margin-top: 0; }
.plan-description :deep(> :last-child) { margin-bottom: 0; }
</style>
