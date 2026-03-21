<template>
  <section class="panel viewer-panel">
    <div class="viewer-header">
      <div>
        <h3>Document Viewer</h3>
        <p v-if="page" class="hint">Page {{ page.page_no }} · HTML canvas review</p>
      </div>
      <button v-if="block" class="btn" @click="emit('sync-selected')">Use Selected Layout</button>
    </div>

    <div v-if="!page" class="hint">Select a block to preview.</div>

    <div v-else class="canvas-shell">
      <canvas ref="canvasRef" class="document-canvas" width="900" height="1200"></canvas>

      <div class="html-overlay">
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

    <div v-if="block" class="meta">
      <p><strong>Block:</strong> {{ block.block_id }}</p>
      <p><strong>Type:</strong> {{ block.type }}</p>
      <p><strong>Confidence:</strong> {{ block.confidence.toFixed(2) }}</p>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import type { DocumentBlock, DocumentPage } from '../types/document';

const props = defineProps<{
  page: DocumentPage | null;
  block: DocumentBlock | null;
}>();

const emit = defineEmits<{
  'select-block': [blockId: string];
  'sync-selected': [];
}>();

const canvasRef = ref<HTMLCanvasElement | null>(null);

const positionedBlocks = computed(() => {
  if (!props.page) {
    return [];
  }

  return props.page.blocks.map((pageBlock, index) => {
    const layout = pageBlock.meta.layout;
    const bbox = layout?.bbox ?? pageBlock.bbox;
    const fallbackTop = 24 + index * 88;
    const top = bbox?.[1] ?? fallbackTop;
    const left = bbox?.[0] ?? 24;
    const width = Math.max((bbox?.[2] ?? 760) - left, 260);
    const height = Math.max((bbox?.[3] ?? top + 72) - top, pageBlock.type === 'table' ? 160 : 72);
    const html = pageBlock.meta.reviewed_html ?? `<p>${escapeHtml(pageBlock.approved_text || pageBlock.ai_suggested_text)}</p>`;

    return {
      ...pageBlock,
      html,
      style: {
        top: `${top}px`,
        left: `${left}px`,
        width: `${width}px`,
        minHeight: `${height}px`,
      },
    };
  });
});

function drawCanvas(): void {
  const canvas = canvasRef.value;
  if (!canvas) {
    return;
  }

  const ctx = canvas.getContext('2d');
  if (!ctx) {
    return;
  }

  ctx.clearRect(0, 0, canvas.width, canvas.height);
  const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
  gradient.addColorStop(0, '#fbfaf5');
  gradient.addColorStop(1, '#eef3f6');
  ctx.fillStyle = gradient;
  ctx.fillRect(0, 0, canvas.width, canvas.height);

  ctx.strokeStyle = '#c5d0d9';
  ctx.lineWidth = 1;
  ctx.strokeRect(18, 18, canvas.width - 36, canvas.height - 36);

  ctx.fillStyle = '#6b7b8c';
  ctx.font = '18px sans-serif';
  ctx.fillText(`Review Canvas - Page ${props.page?.page_no ?? ''}`, 28, 48);
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

onMounted(drawCanvas);
watch(() => props.page, drawCanvas, { deep: true });
</script>
