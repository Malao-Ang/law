<template>
  <div class="block-ruler" @click="onRulerClick">
    <div class="block-ruler__labels">
      <span v-for="mark in marks" :key="mark.position" class="block-ruler__label" :style="{ left: `${mark.left}%` }">
        {{ mark.label }}
      </span>
    </div>

    <div class="block-ruler__track">
      <span
        v-for="tick in ticks"
        :key="tick.position"
        class="block-ruler__tick"
        :style="{ left: `${tick.left}%`, height: tick.major ? '14px' : '8px' }"
      />

      <button
        v-for="(tab, index) in tabs"
        :key="`${tab.position}-${index}`"
        class="block-ruler__tab"
        :class="`block-ruler__tab--${tab.type}`"
        :style="{ left: `${twipsToPercent(tab.position)}%` }"
        type="button"
        :title="`tab ${index + 1}: ${tab.type} @ ${tab.position}`"
        @click.stop="cycleTab(index)"
        @contextmenu.prevent.stop="removeTab(index)"
      >
        {{ tabGlyph(tab.type) }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { LayoutPatch, TabStop, BlockLayout } from '../../types/document';

const props = withDefaults(defineProps<{
  layout: BlockLayout | null;
  pageWidthTwips?: number;
}>(), {
  pageWidthTwips: 9000,
});

const emit = defineEmits<{
  update: [patch: Omit<LayoutPatch, 'page_no'>];
}>();

const tabs = computed(() => props.layout?.tabs ?? []);

const marks = computed(() => {
  const values = [0, 1440, 2880, 4320, 5760, 7200, 8640, props.pageWidthTwips];
  return values.map((position, index) => ({
    position,
    left: twipsToPercent(position),
    label: index === values.length - 1 ? '6.25in' : `${position / 1440}in`,
  }));
});

const ticks = computed(() => {
  const result: Array<{ position: number; left: number; major: boolean }> = [];
  const step = 360;
  for (let position = 0; position <= props.pageWidthTwips; position += step) {
    result.push({
      position,
      left: twipsToPercent(position),
      major: position % 720 === 0,
    });
  }
  return result;
});

function twipsToPercent(position: number): number {
  return Math.max(0, Math.min(100, (position / props.pageWidthTwips) * 100));
}

function clientXToTwips(clientX: number, target: HTMLElement): number {
  const rect = target.getBoundingClientRect();
  const ratio = rect.width > 0 ? (clientX - rect.left) / rect.width : 0;
  const raw = Math.max(0, Math.min(1, ratio)) * props.pageWidthTwips;
  return snapTwips(raw);
}

function snapTwips(position: number): number {
  const snap = 120;
  return Math.max(0, Math.min(props.pageWidthTwips, Math.round(position / snap) * snap));
}

function addTab(position: number): void {
  const next: TabStop[] = [...tabs.value, { position, type: 'left' }];
  next.sort((a, b) => a.position - b.position);
  emit('update', { tabs: next });
}

function cycleTab(index: number): void {
  const current = tabs.value[index];
  if (!current) return;

  const cycle: TabStop['type'][] = ['left', 'center', 'right', 'decimal'];
  const nextIndex = cycle.indexOf(current.type) + 1;
  if (nextIndex >= cycle.length) {
    removeTab(index);
    return;
  }

  const next = tabs.value.map((tab, i) => (i === index ? { ...tab, type: cycle[nextIndex] } : tab));
  emit('update', { tabs: next });
}

function removeTab(index: number): void {
  const next = tabs.value.filter((_, i) => i !== index);
  emit('update', { tabs: next });
}

function onRulerClick(event: MouseEvent): void {
  const target = event.currentTarget as HTMLElement | null;
  if (!target) return;
  addTab(clientXToTwips(event.clientX, target));
}

function tabGlyph(type: TabStop['type']): string {
  switch (type) {
    case 'center':
      return '⊥';
    case 'right':
      return '◁';
    case 'decimal':
      return '•';
    default:
      return 'L';
  }
}
</script>

<style scoped>
/* ponytail: ruler & tab-stop drawing widget — no Vuetify equivalent, keep all */
.block-ruler {
  position: relative;
  user-select: none;
  border: 1px solid #d5cdbd;
  background: linear-gradient(180deg, #faf7f0 0%, #f0eadc 100%);
  border-radius: 6px;
  margin-bottom: 0.5rem;
  padding: 8px 8px 16px;
  overflow: hidden;
}

.block-ruler__labels {
  position: relative;
  height: 18px;
  font-size: 0.7rem;
  color: #74674b;
}

.block-ruler__label {
  position: absolute;
  top: 0;
  transform: translateX(-50%);
  white-space: nowrap;
}

.block-ruler__track {
  position: relative;
  height: 24px;
  border-top: 1px solid #b7ab93;
}

.block-ruler__tick {
  position: absolute;
  top: 0;
  width: 1px;
  background: #b7ab93;
  transform: translateX(-50%);
}

.block-ruler__tab {
  position: absolute;
  top: 8px;
  transform: translateX(-50%);
  min-width: 18px;
  height: 18px;
  border: 1px solid #5f5240;
  border-radius: 999px;
  background: #fff;
  color: #2a2417;
  font-size: 0.7rem;
  line-height: 1;
  padding: 0 4px;
  cursor: pointer;
}

.block-ruler__tab--center {
  background: #e3decd;
}

.block-ruler__tab--right {
  background: #d6e4ef;
}

.block-ruler__tab--decimal {
  background: #efe0d2;
}
</style>
