<!-- apps/app-laravel/resources/js/components/rag/SplitBlockDialog.vue -->
<template>
  <v-dialog :model-value="modelValue" max-width="640" @update:model-value="emit('update:modelValue', $event)">
    <v-card rounded="lg">
      <v-card-title class="text-subtitle-1 font-weight-bold">แยกบรรทัดออกเป็นบล็อกใหม่</v-card-title>
      <v-divider />
      <v-card-text>
        <div v-if="lines.length < 2" class="text-body-2 text-medium-emphasis py-4 text-center">
          บล็อกนี้มีบรรทัดเดียว ไม่สามารถแบ่งได้
        </div>
        <template v-else>
          <p class="text-caption text-medium-emphasis mb-3">
            เลือกจุด "แยกตรงนี้" เพื่อแบ่งบล็อกออกเป็นบล็อกใหม่ (แก้ไขข้อความไม่ได้)
          </p>
          <div class="split-preview">
            <template v-for="(line, i) in lines" :key="i">
              <div class="split-line" :class="`split-line--g${groupOf(i) % 4}`">{{ line || ' ' }}</div>
              <button
                v-if="i < lines.length - 1"
                type="button"
                class="split-boundary"
                :class="{ 'is-on': boundaries.has(i + 1) }"
                @click="toggle(i + 1)"
              >
                <span class="mdi mdi-scissors-cutting" /> แยกตรงนี้
              </button>
            </template>
          </div>
          <div class="text-caption text-medium-emphasis mt-3">จะได้ {{ boundaries.size + 1 }} บล็อก</div>
        </template>
      </v-card-text>
      <v-divider />
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="emit('update:modelValue', false)">ยกเลิก</v-btn>
        <v-btn color="primary" variant="flat" :disabled="boundaries.size === 0" @click="confirm">
          ยืนยันการแยก
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { DocumentBlock } from '../../types/document';

const props = defineProps<{ modelValue: boolean; block: DocumentBlock | null }>();
const emit = defineEmits<{ 'update:modelValue': [boolean]; confirm: [number[]] }>();

const lines = computed<string[]>(() => {
  const text = props.block?.approved_text || props.block?.normalized_text || props.block?.raw_text || '';
  return text.split('\n');
});

const boundaries = ref<Set<number>>(new Set());

// Clear selections whenever the dialog (re)opens.
watch(() => props.modelValue, (open) => { if (open) boundaries.value = new Set(); });

function toggle(index: number): void {
  const next = new Set(boundaries.value);
  if (next.has(index)) next.delete(index);
  else next.add(index);
  boundaries.value = next;
}

// Which resulting block a line falls into — used only to colour the preview.
function groupOf(lineIndex: number): number {
  let g = 0;
  for (const b of boundaries.value) if (b <= lineIndex) g++;
  return g;
}

function confirm(): void {
  emit('confirm', [...boundaries.value].sort((a, b) => a - b));
}
</script>

<style scoped>
.split-preview {
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 10px;
  overflow: hidden;
}

.split-line {
  padding: 8px 12px;
  font-size: 14px;
  white-space: pre-wrap;
  word-break: break-word;
}

.split-line--g0 { background: rgba(var(--v-theme-primary), 0.04); }
.split-line--g1 { background: rgba(var(--v-theme-success), 0.07); }
.split-line--g2 { background: rgba(var(--v-theme-warning), 0.09); }
.split-line--g3 { background: rgba(var(--v-theme-doc-rabiap), 0.08); }

.split-boundary {
  width: 100%;
  text-align: center;
  font-size: 12px;
  padding: 3px 0;
  cursor: pointer;
  color: rgb(var(--v-theme-primary));
  background: #fff;
  border: none;
  border-top: 1px dashed rgba(var(--v-theme-primary), 0.4);
  border-bottom: 1px dashed rgba(var(--v-theme-primary), 0.4);
}

.split-boundary.is-on {
  background: rgb(var(--v-theme-primary));
  color: #fff;
  font-weight: 700;
}
</style>
