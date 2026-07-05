<template>
  <div
    class="drag-block"
    :class="{ 'drag-block--selected': selected }"
    :style="containerStyle"
    @click.stop="emit('update:selected', true)"
  >
    <slot />

    <template v-if="selected">
      <div
        v-for="handle in HANDLES"
        :key="handle.id"
        class="drag-block__handle"
        :style="handle.style"
        :data-handle="handle.id"
        @mousedown.prevent.stop="startResize($event, handle.id)"
      />
    </template>

    <div v-if="isResizing" class="drag-block__tooltip">
      {{ currentWidth }}px
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';

type HandleId = 'tl' | 'tm' | 'tr' | 'ml' | 'mr' | 'bl' | 'bm' | 'br';

const props = defineProps<{
  initialWidth?: number | null;
  initialHeight?: number | null;
  selected: boolean;
  lockAspectRatio?: boolean;
}>();

const emit = defineEmits<{
  'update:selected': [value: boolean];
  resize: [payload: { widthPx: number; heightPx: number | null }];
}>();

const MIN_WIDTH = 60;
const MIN_HEIGHT = 40;

const HANDLES: Array<{ id: HandleId; style: Record<string, string> }> = [
  { id: 'tl', style: { top: '-4px', left: '-4px', cursor: 'nwse-resize' } },
  { id: 'tm', style: { top: '-4px', left: 'calc(50% - 4px)', cursor: 'ns-resize' } },
  { id: 'tr', style: { top: '-4px', right: '-4px', cursor: 'nesw-resize' } },
  { id: 'ml', style: { top: 'calc(50% - 4px)', left: '-4px', cursor: 'ew-resize' } },
  { id: 'mr', style: { top: 'calc(50% - 4px)', right: '-4px', cursor: 'ew-resize' } },
  { id: 'bl', style: { bottom: '-4px', left: '-4px', cursor: 'nesw-resize' } },
  { id: 'bm', style: { bottom: '-4px', left: 'calc(50% - 4px)', cursor: 'ns-resize' } },
  { id: 'br', style: { bottom: '-4px', right: '-4px', cursor: 'nwse-resize' } },
];

const currentWidth = ref<number>(props.initialWidth ?? 400);
const currentHeight = ref<number | null>(props.initialHeight ?? null);
const isResizing = ref(false);

let activeHandle: HandleId | null = null;
let startX = 0;
let startY = 0;
let startWidth = 0;
let startHeight = 0;
let startAspect = 1;

const containerStyle = computed(() => ({
  width: `${currentWidth.value}px`,
  height: currentHeight.value != null ? `${currentHeight.value}px` : undefined,
  position: 'relative' as const,
  display: 'inline-block',
}));

function startResize(event: MouseEvent, handle: HandleId): void {
  activeHandle = handle;
  isResizing.value = true;
  startX = event.clientX;
  startY = event.clientY;
  startWidth = currentWidth.value;
  startHeight = currentHeight.value ?? 0;
  startAspect = startHeight > 0 ? startWidth / startHeight : 1;

  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
}

function onMouseMove(event: MouseEvent): void {
  if (!activeHandle) return;

  const deltaX = event.clientX - startX;
  const deltaY = event.clientY - startY;

  const isLeft = activeHandle.includes('l');
  const isRight = activeHandle.includes('r');
  const isTop = activeHandle.startsWith('t');
  const isBottom = activeHandle.startsWith('b');
  const isCorner = (isLeft || isRight) && (isTop || isBottom);

  let newWidth = startWidth + (isLeft ? -deltaX : isRight ? deltaX : 0);
  let newHeight: number | null = null;

  if (currentHeight.value != null) {
    newHeight = startHeight + (isTop ? -deltaY : isBottom ? deltaY : 0);
    if (props.lockAspectRatio && isCorner) {
      newWidth = Math.max(MIN_WIDTH, Math.round(newWidth));
      newHeight = Math.round(newWidth / startAspect);
    } else {
      newHeight = Math.max(MIN_HEIGHT, Math.round(newHeight));
    }
  }

  if (!props.lockAspectRatio || !isCorner || currentHeight.value == null) {
    newWidth = Math.max(MIN_WIDTH, Math.round(newWidth));
  }

  currentWidth.value = newWidth;
  currentHeight.value = newHeight;
}

function onMouseUp(): void {
  isResizing.value = false;
  activeHandle = null;
  document.removeEventListener('mousemove', onMouseMove);
  document.removeEventListener('mouseup', onMouseUp);
  emit('resize', { widthPx: currentWidth.value, heightPx: currentHeight.value });
}

onBeforeUnmount(() => {
  document.removeEventListener('mousemove', onMouseMove);
  document.removeEventListener('mouseup', onMouseUp);
});
</script>

<style scoped>
/* ponytail: drag+resize positioning, keep — all rules below are load-bearing for
   absolute handle placement, cursor feedback, tooltip positioning, and selection outline.
   No structural chrome (no card, no toolbar, no header) exists in this component.
   Only the hardcoded Vuetify-primary hex (#1976d2) and surface-white (#ffffff) are
   converted to theme tokens; all positioning values are untouched. */

.drag-block {
  display: inline-block;
  position: relative;
  box-sizing: border-box;
  user-select: none;
}

.drag-block--selected {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.drag-block__handle {
  position: absolute;
  width: 8px;
  height: 8px;
  background: rgb(var(--v-theme-primary));
  border: 1px solid rgb(var(--v-theme-surface));
  border-radius: 1px;
  z-index: 10;
}

.drag-block__tooltip {
  position: absolute;
  bottom: -24px;
  left: 50%;
  transform: translateX(-50%);
  background: rgba(0, 0, 0, 0.7);
  color: #ffffff;
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 3px;
  white-space: nowrap;
  pointer-events: none;
  z-index: 11;
}
</style>
