import { defineStore } from 'pinia';
import { ref } from 'vue';
import { exportDocument, fetchReview, fetchStatus, updateComposeState } from '../api/client';
import type { DocumentMetadata, DocumentStatus, ReviewDocument, ThaiFont } from '../types/document';

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

  return { review, loading, error, docStatus, exporting, fetch, pollStatus, triggerExport, saveComposeState, setError, reset };
});
