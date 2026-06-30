import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { useDocumentStore } from './documentStore';
import type { DocumentBlock } from '../types/document';

export const useReviewUiStore = defineStore('reviewUi', () => {
  const mode = ref<'edit' | 'preview'>('edit');
  const isDirty = ref(false);
  const selectedBlockId = ref<string | null>(null);
  const editingBlockId = ref<string | null>(null);
  const selectedPageNo = ref<number | null>(null);

  const selectedBlock = computed<DocumentBlock | null>(() => {
    const documentStore = useDocumentStore();
    if (!selectedBlockId.value || !documentStore.review) return null;
    for (const page of documentStore.review.pages) {
      const found = page.blocks.find((b) => b.block_id === selectedBlockId.value);
      if (found) return found;
    }
    return null;
  });

  function selectBlock(blockId: string, pageNo: number): void {
    selectedBlockId.value = blockId;
    selectedPageNo.value = pageNo;
  }

  function clearSelection(): void {
    selectedBlockId.value = null;
    selectedPageNo.value = null;
    editingBlockId.value = null;
  }

  function setEditing(blockId: string | null): void {
    editingBlockId.value = blockId;
  }

  function setMode(next: 'edit' | 'preview'): void {
    mode.value = next;
  }

  function setDirty(v: boolean): void {
    isDirty.value = v;
  }

  function reset(): void {
    mode.value = 'edit';
    isDirty.value = false;
    selectedBlockId.value = null;
    editingBlockId.value = null;
    selectedPageNo.value = null;
  }

  return {
    mode,
    isDirty,
    selectedBlockId,
    editingBlockId,
    selectedPageNo,
    selectedBlock,
    selectBlock,
    clearSelection,
    setEditing,
    setMode,
    setDirty,
    reset,
  };
});
