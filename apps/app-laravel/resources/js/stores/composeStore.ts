import { defineStore } from 'pinia';
import { ref } from 'vue';
import { exportDocument, fetchReview, fetchStatus, updateComposeState } from '../api/client';
import type { DocumentBlock, DocumentMetadata, DocumentStatus, ReviewDocument, ThaiFont } from '../types/document';

export const useComposeStore = defineStore('compose', () => {
  const review = ref<ReviewDocument | null>(null);
  const loading = ref(false);
  const error = ref('');
  const docStatus = ref<DocumentStatus | null>(null);
  const exporting = ref(false);

  async function fetch(documentId: string): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
      review.value = await fetchReview(documentId);
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to load compose editor';
    } finally {
      loading.value = false;
    }
  }

  async function pollStatus(documentId: string): Promise<boolean> {
    try {
      docStatus.value = await fetchStatus(documentId);
      return true;
    } catch {
      return false;
    }
  }

  async function triggerExport(documentId: string): Promise<void> {
    if (exporting.value) return;
    exporting.value = true;
    error.value = '';
    try {
      await exportDocument(documentId);
      docStatus.value = await fetchStatus(documentId);
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Export failed';
    } finally {
      exporting.value = false;
    }
  }

  async function saveComposeState(
    documentId: string,
    payload: { font_family: ThaiFont; font_size_pt: number; metadata: DocumentMetadata },
  ): Promise<{ saved: boolean; errorMessage: string }> {
    try {
      const response = await updateComposeState(documentId, payload);
      if (review.value && response.compose_state) {
        review.value.compose_state = response.compose_state;
      }
      return { saved: true, errorMessage: '' };
    } catch (e: unknown) {
      return { saved: false, errorMessage: e instanceof Error ? e.message : 'บันทึกอัตโนมัติไม่สำเร็จ' };
    }
  }

  // Apply a merge result locally: update the anchor block returned by the API and
  // drop the other merged-away blocks. Avoids a full refetch after merge.
  function applyMerge(mergedBlock: DocumentBlock, removedIds: string[]): void {
    // Caller must exclude mergedBlock.block_id from removedIds, else the anchor is dropped.
    if (!review.value) return;
    const removeSet = new Set(removedIds);
    for (const page of review.value.pages) {
      for (const block of page.blocks) {
        if (block.block_id === mergedBlock.block_id) {
          block.approved_text = mergedBlock.approved_text;
          block.needs_review = mergedBlock.needs_review;
          if (block.meta && mergedBlock.meta) {
            block.meta.reviewed_html = mergedBlock.meta.reviewed_html;
            block.meta.chunk_type = mergedBlock.meta.chunk_type;
          }
        }
      }
      page.blocks = page.blocks.filter((block) => !removeSet.has(block.block_id));
    }
  }

  // Apply a split result locally: replace the original block with the two blocks
  // returned by the API, preserving position. Avoids a full refetch after split.
  function applySplit(originalId: string, first: DocumentBlock, second: DocumentBlock): void {
    if (!review.value) return;
    for (const page of review.value.pages) {
      const index = page.blocks.findIndex((block) => block.block_id === originalId);
      if (index !== -1) {
        page.blocks.splice(index, 1, first, second);
        return;
      }
    }
  }

  function setError(msg = ''): void {
    error.value = msg;
  }

  function reset(): void {
    review.value = null;
    loading.value = false;
    error.value = '';
    docStatus.value = null;
    exporting.value = false;
  }

  return { review, loading, error, docStatus, exporting, fetch, pollStatus, triggerExport, saveComposeState, applyMerge, applySplit, setError, reset };
});
