<template>
  <div class="block-flow" :style="style" v-html="bodyHtml" />
</template>

<script setup lang="ts">
import { computed } from 'vue';
import DOMPurify from 'dompurify';
import type { DocumentBlock } from '../../types/document';

const props = defineProps<{
  block: DocumentBlock;
  overrideText?: string | null;
}>();

const style = computed<Record<string, string>>(() => {
  const layout = (props.block.meta.layout ?? {}) as Record<string, unknown>;
  const nextStyle: Record<string, string> = {};

  if (typeof layout.indent_left === 'number' && layout.indent_left > 0) {
    nextStyle.marginLeft = `${Math.min(layout.indent_left / 20, 200)}pt`;
  } else if (typeof layout.indent_level === 'number' && layout.indent_level > 0) {
    nextStyle.marginLeft = `${layout.indent_level * 24}px`;
  }

  if (typeof layout.indent_first_line === 'number' && layout.indent_first_line !== 0) {
    nextStyle.textIndent = `${layout.indent_first_line / 20}pt`;
  } else if (typeof layout.indent_hanging === 'number' && layout.indent_hanging > 0) {
    nextStyle.textIndent = `-${layout.indent_hanging / 20}pt`;
  }

  if (typeof layout.alignment === 'string' && layout.alignment !== '') {
    nextStyle.textAlign = layout.alignment;
  }

  return nextStyle;
});

function escapeHtml(text: string): string {
  return text
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');
}

const bodyHtml = computed<string>(() => {
  const block = props.block;

  if (block.type === 'image' && block.meta.image) {
    const src = block.meta.image.src_url ?? block.meta.image.data_uri ?? '';
    const width = block.meta.image.display_width_px ?? block.meta.image.width ?? null;
    const styleAttr = width ? ` style="width:${width}px;height:auto;"` : '';

    return DOMPurify.sanitize(`<img src="${src}" alt="" class="bf-img"${styleAttr}>`, {
      ALLOWED_TAGS: ['img'],
      ALLOWED_ATTR: ['src', 'alt', 'class', 'style'],
    });
  }

  if (block.type === 'table') {
    const tableHtml = block.meta.table?.html ?? block.meta.table_html ?? '';
    if (tableHtml !== '') {
      const inner = DOMPurify.sanitize(tableHtml, {
        ALLOWED_TAGS: ['table', 'thead', 'tbody', 'tr', 'th', 'td', 'br', 'span'],
        ALLOWED_ATTR: ['class', 'style', 'colspan', 'rowspan'],
      });
      const width = block.meta.table_display_width_px ?? null;

      return width
        ? `<div class="bf-table-wrap" style="width:${width}px;max-width:100%;">${inner}</div>`
        : inner;
    }
  }

  const reviewedHtml = typeof block.meta?.reviewed_html === 'string' && block.meta.reviewed_html.trim() !== ''
    ? block.meta.reviewed_html.trim()
    : '';

  if (reviewedHtml !== '' && props.overrideText == null) {
    return DOMPurify.sanitize(reviewedHtml, {
      ALLOWED_TAGS: ['div', 'p', 'br', 'span', 'strong', 'em', 'u'],
      ALLOWED_ATTR: ['class', 'style'],
    });
  }

  const raw = props.overrideText ?? (block.approved_text || block.normalized_text || block.raw_text || '');
  let html = escapeHtml(raw)
    .replaceAll('\n', '<br>')
    .replaceAll('\t', '<span class="bf-tab"></span>');

  const formatting = block.meta.formatting ?? {};
  if (formatting.underline) html = `<u>${html}</u>`;
  if (formatting.italic) html = `<em>${html}</em>`;
  if (formatting.bold) html = `<strong>${html}</strong>`;

  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: ['br', 'span', 'strong', 'em', 'u'],
    ALLOWED_ATTR: ['class'],
  });
});
</script>

<style scoped>
.block-flow {
  line-height: 1.95;
  font-size: 15px;
  margin: 0 0 4px;
  white-space: normal;
  color: #1e293b;
}

.block-flow :deep(.bf-tab) {
  display: inline-block;
  width: 36px;
}

.block-flow :deep(.bf-img) {
  max-width: 100%;
  height: auto;
  display: block;
  margin: 8px auto;
}

.block-flow :deep(.bf-table-wrap) {
  overflow-x: auto;
}

.block-flow :deep(table) {
  border-collapse: collapse;
  width: 100%;
  margin: 8px 0;
}

.block-flow :deep(th),
.block-flow :deep(td) {
  border: 1px solid #8e9aa2;
  padding: 7px 10px;
  vertical-align: top;
}

.block-flow :deep(th) {
  background: transparent;
  font-weight: 700;
}
</style>
