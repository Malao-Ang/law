import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchReview, saveDocumentReview } from '../api/client';
import type { DocumentReviewState, ReviewDocument } from '../types/document';

export const useDocumentStore = defineStore('document', () => {
  const documentId = ref('');
  const review = ref<ReviewDocument | null>(null);
  const loading = ref(false);
  const error = ref('');
  const saving = ref(false);
  const saveError = ref('');

  async function fetch(id: string): Promise<void> {
    documentId.value = id;
    loading.value = true;
    error.value = '';
    try {
      review.value = await fetchReview(id);
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'ไม่สามารถโหลดเอกสาร';
    } finally {
      loading.value = false;
    }
  }

  async function saveReview(payload: {
    draft_html?: string;
    approved_by?: string;
    notes?: string;
    reset_to_generated?: boolean;
  }): Promise<DocumentReviewState | null> {
    saving.value = true;
    saveError.value = '';
    try {
      const res = await saveDocumentReview(documentId.value, payload);
      if (review.value) {
        review.value.document_review = res.document_review;
      }
      return res.document_review;
    } catch (e: unknown) {
      saveError.value = e instanceof Error ? e.message : 'บันทึกไม่สำเร็จ';
      return null;
    } finally {
      saving.value = false;
    }
  }

  function clearSaveError(): void {
    saveError.value = '';
  }

  function reset(): void {
    documentId.value = '';
    review.value = null;
    loading.value = false;
    error.value = '';
    saving.value = false;
    saveError.value = '';
  }

  return { documentId, review, loading, error, saving, saveError, fetch, saveReview, clearSaveError, reset };
});
