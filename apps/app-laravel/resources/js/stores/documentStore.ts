import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchReview, saveDocumentReview, updateWorkflowProgress } from '../api/client';
import type { DocumentReviewState, LawMeta, LawRelation, ReviewDocument } from '../types/document';

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

  async function saveLawMeta(payload: Partial<LawMeta>): Promise<boolean> {
    saving.value = true;
    saveError.value = '';
    try {
      const res = await saveDocumentReview(documentId.value, { law_meta: payload });
      if (review.value && res.law_meta) {
        review.value.law_meta = res.law_meta;
      }
      return true;
    } catch (e: unknown) {
      saveError.value = e instanceof Error ? e.message : 'บันทึกไม่สำเร็จ';
      return false;
    } finally {
      saving.value = false;
    }
  }

  async function saveRelations(relations: LawRelation[]): Promise<boolean> {
    saving.value = true;
    saveError.value = '';
    try {
      const res = await saveDocumentReview(documentId.value, { relations });
      if (review.value && res.relations) {
        review.value.relations = res.relations;
      }
      return true;
    } catch (e: unknown) {
      saveError.value = e instanceof Error ? e.message : 'บันทึกความสัมพันธ์ไม่สำเร็จ';
      return false;
    } finally {
      saving.value = false;
    }
  }

  async function completeWorkflowStep(step: number): Promise<boolean> {
    saving.value = true;
    saveError.value = '';
    try {
      await updateWorkflowProgress(documentId.value, step);
      return true;
    } catch (e: unknown) {
      saveError.value = e instanceof Error ? e.message : 'อัปเดตขั้นตอนไม่สำเร็จ';
      return false;
    } finally {
      saving.value = false;
    }
  }

  function setSaveError(msg = ''): void {
    saveError.value = msg;
  }

  function reset(): void {
    documentId.value = '';
    review.value = null;
    loading.value = false;
    error.value = '';
    saving.value = false;
    saveError.value = '';
  }

  return {
    documentId,
    review,
    loading,
    error,
    saving,
    saveError,
    fetch,
    saveReview,
    saveLawMeta,
    saveRelations,
    completeWorkflowStep,
    setSaveError,
    reset,
  };
});
