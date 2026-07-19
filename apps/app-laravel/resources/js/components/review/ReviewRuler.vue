<template>
  <div ref="trackEl" class="review-ruler">
    <span v-if="props.currentPage" class="review-ruler__page-badge">
      หน้า {{ props.currentPage }}{{ props.totalPages ? ` / ${props.totalPages}` : '' }}
    </span>
    <span
      v-for="tick in ticks"
      :key="tick.mm"
      class="review-ruler__tick"
      :class="{ 'review-ruler__tick--major': tick.major }"
      :style="{ left: `${tick.pct}%` }"
    />
    <button
      type="button"
      class="review-ruler__marker review-ruler__marker--firstline"
      title="ย่อหน้าบรรทัดแรก"
      :style="{ left: `${firstLinePct}%` }"
      @pointerdown="startDrag('firstline', $event)"
    />
    <button
      type="button"
      class="review-ruler__marker review-ruler__marker--left"
      title="ระยะย่อหน้า"
      :style="{ left: `${leftPct}%` }"
      @pointerdown="startDrag('left', $event)"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { Editor } from '@tiptap/vue-3';
import {
  indentLevelToMm,
  mmToIndentLevel,
  ptToMm,
  mmToPt,
  offsetToMm,
} from './rulerMath';

const props = defineProps<{
  editor: Editor;
  contentMm: number;
  currentPage?: number;
  totalPages?: number;
}>();

const trackEl = ref<HTMLElement | null>(null);
const activeIndentLevel = ref(0);
const activeFirstLineMm = ref(0);

const safeContentMm = computed(() => Math.max(1, props.contentMm));

const leftPct = computed(() => (indentLevelToMm(activeIndentLevel.value) / safeContentMm.value) * 100);
const firstLinePct = computed(
  () => ((indentLevelToMm(activeIndentLevel.value) + activeFirstLineMm.value) / safeContentMm.value) * 100,
);

const ticks = computed(() => {
  const result: Array<{ mm: number; pct: number; major: boolean }> = [];
  for (let mm = 0; mm <= safeContentMm.value; mm += 5) {
    result.push({ mm, pct: (mm / safeContentMm.value) * 100, major: mm % 10 === 0 });
  }
  return result;
});

function syncFromEditor(): void {
  const attrs = props.editor.getAttributes('paragraph');
  activeIndentLevel.value = typeof attrs.indent === 'number' ? attrs.indent : 0;
  const raw = typeof attrs.firstLineIndent === 'string' ? attrs.firstLineIndent : '';
  activeFirstLineMm.value = raw ? ptToMm(parseFloat(raw) || 0) : 0;
}

function startDrag(kind: 'left' | 'firstline', ev: PointerEvent): void {
  ev.preventDefault();
  const track = trackEl.value;
  if (!track) return;

  const move = (e: PointerEvent): void => {
    const rect = track.getBoundingClientRect();
    const mm = offsetToMm(e.clientX - rect.left, rect.width, safeContentMm.value);
    if (kind === 'left') {
      props.editor.chain().focus().setIndentLevel(mmToIndentLevel(mm)).run();
    } else {
      const rel = Math.max(0, mm - indentLevelToMm(activeIndentLevel.value));
      const pt = Math.round(mmToPt(rel) / 6) * 6;
      props.editor.chain().focus().setFirstLineIndent(pt > 0 ? `${pt}pt` : '').run();
    }
  };
  const up = (): void => {
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', up);
  };
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', up);
}

onMounted(() => {
  props.editor.on('selectionUpdate', syncFromEditor);
  props.editor.on('transaction', syncFromEditor);
  syncFromEditor();
});

onBeforeUnmount(() => {
  props.editor.off('selectionUpdate', syncFromEditor);
  props.editor.off('transaction', syncFromEditor);
});
</script>

<style scoped>
/* ponytail: ruler drawing widget — no Vuetify equivalent */
.review-ruler {
  position: relative;
  height: 20px;
  background: linear-gradient(180deg, #faf7f0 0%, #f0eadc 100%);
  border: 1px solid #d5cdbd;
  border-radius: 4px;
  box-sizing: border-box;
}

.review-ruler__tick {
  position: absolute;
  top: 11px;
  bottom: 1px;
  width: 1px;
  background: #c3b79b;
  transform: translateX(-50%);
}

.review-ruler__tick--major {
  top: 5px;
  background: #a1926f;
}

.review-ruler__marker {
  position: absolute;
  width: 0;
  height: 0;
  padding: 0;
  border: none;
  background: transparent;
  cursor: ew-resize;
  transform: translateX(-50%);
}

.review-ruler__marker--left {
  bottom: -1px;
  border-left: 6px solid transparent;
  border-right: 6px solid transparent;
  border-bottom: 8px solid #2563eb;
}

.review-ruler__marker--firstline {
  top: -1px;
  border-left: 6px solid transparent;
  border-right: 6px solid transparent;
  border-top: 8px solid #2563eb;
}

.review-ruler__page-badge {
  position: absolute;
  left: -76px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 10px;
  color: #1a56db;
  background: #e8f0fe;
  border: 1px solid #4285f4;
  border-radius: 4px;
  padding: 1px 5px;
  white-space: nowrap;
  pointer-events: none;
}
</style>
