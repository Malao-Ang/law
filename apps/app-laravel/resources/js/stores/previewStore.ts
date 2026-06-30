import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchPreview } from '../api/client';
import type { PreviewData } from '../types/document';

export const usePreviewStore = defineStore('preview', () => {
  const data = ref<PreviewData | null>(null);
  const loading = ref(false);
  const error = ref('');

  async function fetch(documentId: string): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
      data.value = await fetchPreview(documentId);
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'โหลดตัวอย่างไม่สำเร็จ';
    } finally {
      loading.value = false;
    }
  }

  function reset(): void {
    data.value = null;
    loading.value = false;
    error.value = '';
  }

  return { data, loading, error, fetch, reset };
});
