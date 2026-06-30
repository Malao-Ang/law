<template>
  <node-view-wrapper as="span" class="img-nv" :class="{ 'img-nv--selected': selected }">
    <img
      ref="imgEl"
      :src="node.attrs.src"
      :alt="node.attrs.alt || ''"
      :style="imgStyle"
      draggable="false"
    >
    <span
      v-if="editor.isEditable"
      class="img-nv__handle"
      title="ลากเพื่อปรับขนาด"
      @mousedown.prevent.stop="startResize"
    />
    <span v-if="resizing" class="img-nv__badge">{{ node.attrs.width }}px</span>
  </node-view-wrapper>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3';
import { patchBlockSize } from '../../api/client';

const props = defineProps(nodeViewProps);

const imgEl = ref<HTMLImageElement | null>(null);
const resizing = ref(false);
let startX = 0;
let startW = 0;

const imgStyle = computed(() => {
  const width = props.node.attrs.width as number | null;
  return width ? { width: `${width}px`, height: 'auto' } : {};
});

function startResize(event: MouseEvent): void {
  resizing.value = true;
  startX = event.clientX;
  startW = (props.node.attrs.width as number | null) ?? imgEl.value?.offsetWidth ?? 400;
  document.addEventListener('mousemove', onMove);
  document.addEventListener('mouseup', onUp);
}

function onMove(event: MouseEvent): void {
  const next = Math.max(40, Math.round(startW + (event.clientX - startX)));
  props.updateAttributes({ width: next });
}

function onUp(): void {
  resizing.value = false;
  document.removeEventListener('mousemove', onMove);
  document.removeEventListener('mouseup', onUp);

  const documentId = (props.extension.options as { documentId?: string }).documentId ?? '';
  const blockId = (props.node.attrs.blockId as string | null) ?? '';
  const pageNo = Number(props.node.attrs.pageNo) || 1;
  const width = (props.node.attrs.width as number | null) ?? null;

  if (documentId && blockId && width) {
    patchBlockSize(documentId, blockId, {
      page_no: pageNo,
      display_width_px: width,
      display_height_px: null,
    }).catch(() => {
      // non-fatal — width remains in draft_html regardless
    });
  }
}

onBeforeUnmount(() => {
  document.removeEventListener('mousemove', onMove);
  document.removeEventListener('mouseup', onUp);
});
</script>

<style scoped>
.img-nv {
  display: inline-block;
  position: relative;
  line-height: 0;
}

.img-nv img {
  max-width: 100%;
  border: 1px solid #e1e8f5;
  border-radius: 4px;
}

.img-nv--selected img {
  outline: 2px solid #4f86ff;
  outline-offset: 1px;
}

.img-nv__handle {
  position: absolute;
  right: -5px;
  bottom: -5px;
  width: 12px;
  height: 12px;
  background: #4f86ff;
  border: 2px solid #fff;
  border-radius: 2px;
  cursor: nwse-resize;
}

.img-nv__badge {
  position: absolute;
  top: 4px;
  left: 4px;
  background: rgba(15, 23, 42, 0.8);
  color: #fff;
  font-size: 11px;
  line-height: 1;
  padding: 3px 5px;
  border-radius: 3px;
}
</style>
