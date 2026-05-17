<template>
  <section class="panel viewer-panel">
    <div class="viewer-header">
      <div>
        <h3>Page Review</h3>
        <p v-if="page" class="hint">Page {{ page.page_no }} · {{ page.source_kind ?? 'unknown' }} · {{ modeLabel }}</p>
      </div>
    </div>

    <div v-if="!page" class="preview-fallback">Select a block to preview.</div>

    <div v-else-if="mode === 'html'" class="page-preview-shell">
      <div class="page-stage page-stage--paper" :style="pageStageStyle">
        <article
          v-for="overlayBlock in positionedBlocks"
          :key="overlayBlock.block_id"
          class="overlay-block"
          :class="{
            selected: overlayBlock.block_id === block?.block_id,
            table: overlayBlock.type === 'table',
          }"
          :style="overlayBlock.style"
          @click="emit('select-block', overlayBlock.block_id)"
        >
          <div class="overlay-label">{{ overlayBlock.type }} · {{ overlayBlock.block_id }}</div>
          <div class="overlay-html" v-html="overlayBlock.html"></div>
        </article>
      </div>
    </div>

    <div v-else-if="page.image_url" class="page-preview-shell">
      <div class="page-stage">
        <img
          :src="page.image_url"
          :alt="`Page ${page.page_no}`"
          class="page-preview-image"
          @load="onImageLoad"
        />
        <div v-if="mode === 'overlay'" class="html-overlay">
          <article
            v-for="overlayBlock in positionedBlocks"
            :key="overlayBlock.block_id"
            class="overlay-block"
            :class="{
              selected: overlayBlock.block_id === block?.block_id,
              table: overlayBlock.type === 'table',
            }"
            :style="overlayBlock.style"
            @click="emit('select-block', overlayBlock.block_id)"
          >
            <div class="overlay-label">{{ overlayBlock.type }} · {{ overlayBlock.block_id }}</div>
            <div class="overlay-html">{{ overlayBlock.preview }}</div>
          </article>
        </div>
      </div>
    </div>

    <div v-else class="preview-fallback">
      No page image is available for this page. Switch to Extracted HTML to review OCR output.
    </div>

    <div v-if="block" class="meta">
      <p><strong>Block:</strong> {{ block.block_id }}</p>
      <p><strong>Type:</strong> {{ block.type }}</p>
      <p><strong>Confidence:</strong> {{ block.confidence.toFixed(2) }}</p>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import type { DocumentBlock, DocumentPage } from '../types/document';

const props = defineProps<{
  page: DocumentPage | null;
  block: DocumentBlock | null;
  mode: 'original' | 'overlay' | 'html';
}>();

const emit = defineEmits<{
  'select-block': [blockId: string];
}>();

const naturalWidth = ref(900);
const naturalHeight = ref(1200);

const modeLabel = computed(() => {
  if (props.mode === 'original') return 'Original';
  if (props.mode === 'overlay') return 'OCR Overlay';
  return 'Extracted HTML';
});

const pageBounds = computed(() => {
  const imageWidth = naturalWidth.value;
  const imageHeight = naturalHeight.value;
  if (props.page?.image_url) {
    return { width: imageWidth, height: imageHeight };
  }

  const bboxes = props.page?.blocks
    .map((pageBlock) => pageBlock.meta.layout?.bbox ?? pageBlock.bbox)
    .filter((bbox): bbox is [number, number, number, number] => Array.isArray(bbox) && bbox.length === 4) ?? [];

  const width = bboxes.length > 0 ? Math.max(...bboxes.map((bbox) => bbox[2])) + 48 : imageWidth;
  const height = bboxes.length > 0 ? Math.max(...bboxes.map((bbox) => bbox[3])) + 48 : imageHeight;

  return { width, height };
});

const pageStageStyle = computed(() => ({
  aspectRatio: `${pageBounds.value.width} / ${pageBounds.value.height}`,
}));

const positionedBlocks = computed(() => {
  if (!props.page) {
    return [];
  }

  const pageWidth = Math.max(pageBounds.value.width, 1);
  const pageHeight = Math.max(pageBounds.value.height, 1);

  return props.page.blocks.map((pageBlock, index) => {
    const layout = pageBlock.meta.layout;
    const bbox = layout?.bbox ?? pageBlock.bbox;
    const fallbackTop = 24 + index * 88;
    const top = bbox?.[1] ?? fallbackTop;
    const left = bbox?.[0] ?? 24;
    const width = Math.max((bbox?.[2] ?? 760) - left, 140);
    const height = Math.max((bbox?.[3] ?? top + 72) - top, pageBlock.type === 'table' ? 96 : 54);
    const html = pageBlock.meta.reviewed_html ?? `<p>${escapeHtml(pageBlock.approved_text || pageBlock.ai_suggested_text)}</p>`;
    const preview = (pageBlock.approved_text || pageBlock.ai_suggested_text || pageBlock.raw_text).replace(/\s+/g, ' ').trim();

    return {
      ...pageBlock,
      html,
      preview: preview.slice(0, 140),
      style: {
        top: `${(top / pageHeight) * 100}%`,
        left: `${(left / pageWidth) * 100}%`,
        width: `${(width / pageWidth) * 100}%`,
        minHeight: `${(height / pageHeight) * 100}%`,
      },
    };
  });
});

function onImageLoad(event: Event): void {
  const image = event.target as HTMLImageElement | null;
  if (!image) {
    return;
  }

  naturalWidth.value = image.naturalWidth || 900;
  naturalHeight.value = image.naturalHeight || 1200;
}

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;')
    .replaceAll('\n', '<br>');
}
</script>
