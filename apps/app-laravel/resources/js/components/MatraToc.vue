<template>
  <div class="matra-toc">
    <div class="matra-toc__header" @click="collapsed = !collapsed">
      <span class="matra-toc__title">มาตรา</span>
      <span class="matra-toc__count hint">{{ items.length }}</span>
      <span class="matra-toc__toggle">{{ collapsed ? '▶' : '▼' }}</span>
    </div>

    <div v-if="!collapsed" class="matra-toc__list">
      <div
        v-if="items.length === 0"
        class="matra-toc__empty hint"
      >ไม่พบมาตรา</div>

      <button
        v-for="item in items"
        :key="item.block.block_id"
        class="matra-toc__item"
        :class="{ 'matra-toc__item--active': currentBlockId === item.block.block_id }"
        :title="item.block.approved_text || item.block.normalized_text"
        @click="emit('jump', item.block.block_id)"
      >
        <span class="matra-toc__marker">{{ item.block.meta.list_marker?.text ?? '' }}</span>
        <span class="matra-toc__snippet hint">{{ snippet(item.block) }}</span>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import type { DocumentBlock, DocumentPage } from '../types/document';

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
.matra-toc {
  margin-bottom: 1rem;
  border: 1px solid #d1fae5;
  border-radius: 6px;
  overflow: hidden;
  background: #f0fdf4;
}

.matra-toc__header {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.6rem;
  cursor: pointer;
  background: #d1fae5;
  user-select: none;
}

.matra-toc__header:hover {
  background: #a7f3d0;
}

.matra-toc__title {
  font-weight: 600;
  font-size: 0.85rem;
}

.matra-toc__count {
  font-size: 0.75rem;
}

.matra-toc__toggle {
  margin-left: auto;
  font-size: 0.75rem;
}

.matra-toc__list {
  max-height: 240px;
  overflow-y: auto;
}

.matra-toc__empty {
  padding: 0.4rem 0.6rem;
  font-size: 0.8rem;
}

.matra-toc__item {
  display: flex;
  align-items: baseline;
  gap: 0.4rem;
  width: 100%;
  text-align: left;
  padding: 0.3rem 0.6rem;
  background: transparent;
  border: none;
  border-bottom: 1px solid #d1fae5;
  cursor: pointer;
  font-family: inherit;
  font-size: 0.8rem;
  transition: background 0.1s;
}

.matra-toc__item:last-child {
  border-bottom: none;
}

.matra-toc__item:hover {
  background: #ecfdf5;
}

.matra-toc__item--active {
  background: #6ee7b7;
  font-weight: 600;
}

.matra-toc__marker {
  white-space: nowrap;
  flex-shrink: 0;
  color: #047857;
}

.matra-toc__snippet {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
}
</style>
