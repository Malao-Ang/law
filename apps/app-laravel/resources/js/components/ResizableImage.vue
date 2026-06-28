<template>
  <div
    class="resizable-image-container"
    :style="{ width: displayWidth ? displayWidth + 'px' : 'auto' }"
  >
    <img
      :src="src"
      :alt="alt"
      draggable="false"
    >
    <div
      v-if="editMode"
      class="resize-handle"
      @mousedown.prevent.stop="startResize"
    ></div>
    <div v-if="isResizing" class="resize-tooltip">{{ displayWidth }}px</div>
  </div>
</template>

<script setup lang="ts">
import { ref, onBeforeUnmount } from 'vue';

const props = defineProps<{
  src: string;
  alt?: string;
  editMode: boolean;
}>();

const displayWidth = ref<number | null>(null);
const isResizing = ref(false);
let startX = 0;
let startWidth = 0;

function startResize(event: MouseEvent): void {
  isResizing.value = true;
  startX = event.clientX;
  const container = (event.currentTarget as HTMLElement).parentElement;
  startWidth = container ? container.offsetWidth : (displayWidth.value ?? 400);
  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
}

function onMouseMove(event: MouseEvent): void {
  displayWidth.value = Math.max(40, Math.round(startWidth + (event.clientX - startX)));
}

function onMouseUp(): void {
  isResizing.value = false;
  document.removeEventListener('mousemove', onMouseMove);
  document.removeEventListener('mouseup', onMouseUp);
}

onBeforeUnmount(() => {
  document.removeEventListener('mousemove', onMouseMove);
  document.removeEventListener('mouseup', onMouseUp);
});
</script>
