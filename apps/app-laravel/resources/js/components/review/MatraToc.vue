<template>
  <div class="matra-toc">
    <div
      class="matra-toc__header d-flex align-center ga-2 px-3 py-2"
      @click="collapsed = !collapsed"
    >
      <span class="text-caption font-weight-semibold">มาตรา</span>
      <span class="text-caption text-medium-emphasis">{{ items.length }}</span>
      <v-spacer />
      <v-icon :icon="collapsed ? 'mdi-chevron-right' : 'mdi-chevron-down'" size="x-small" />
    </div>

    <div v-if="!collapsed" class="matra-toc__body">
      <div v-if="items.length === 0" class="text-caption text-medium-emphasis pa-3">ไม่พบมาตรา</div>
      <v-list v-else nav density="compact" bg-color="transparent">
        <v-list-item
          v-for="item in items"
          :key="item.block.block_id"
          :active="currentBlockId === item.block.block_id"
          color="success"
          :title="item.block.meta.list_marker?.text ?? ''"
          :subtitle="snippet(item.block)"
          @click="emit('jump', item.block.block_id)"
        />
      </v-list>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import type { DocumentBlock } from '../../types/document';

interface TocItem {
  page_no: number;
  block: DocumentBlock;
}

const props = defineProps<{
  items: TocItem[];
  currentBlockId?: string | null;
}>();

const emit = defineEmits<{
  jump: [blockId: string];
}>();

const collapsed = ref(false);

function snippet(block: DocumentBlock): string {
  const text = block.approved_text || block.normalized_text;
  const marker = block.meta.list_marker?.text ?? '';
  const body = text.startsWith(marker) ? text.slice(marker.length).trim() : text;
  return body.length > 30 ? body.slice(0, 30) + '…' : body;
}
</script>

<style scoped>
/* ponytail: brand green TOC palette (legal section marker theme), no Vuetify token, keep as CSS */
.matra-toc {
  border: 1px solid #d1fae5;
  border-radius: 6px;
  overflow: hidden;
  background: #f0fdf4;
  margin-bottom: 1rem;
}

.matra-toc__header {
  background: #d1fae5;
  cursor: pointer;
  user-select: none;
}

.matra-toc__header:hover { background: #a7f3d0; }

/* ponytail: functional scroll constraint, keep as CSS */
.matra-toc__body {
  max-height: 240px;
  overflow-y: auto;
}
</style>
