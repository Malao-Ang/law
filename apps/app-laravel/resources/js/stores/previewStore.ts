import { defineStore } from 'pinia';
import { ref } from 'vue';
import { getPreviewCached } from './reviewCache';
import type { PreviewData } from '../types/document';

export const usePreviewStore = defineStore('preview', () => {
  const data = ref<PreviewData | null>(null);
  const loading = ref(false);
  const error = ref('');

  let fetchRequest = 0;
  async function fetch(documentId: string, force = false): Promise<void> {
    const current = ++fetchRequest;
    data.value = null;
    loading.value = true;
    error.value = '';
    try {
      const response = await getPreviewCached(documentId, force);
      if (current === fetchRequest) data.value = response;
    } catch (e: unknown) {
      if (current === fetchRequest) error.value = e instanceof Error ? e.message : 'โหลดตัวอย่างไม่สำเร็จ';
    } finally {
      if (current === fetchRequest) loading.value = false;
    }
  }

  function reset(): void {
    fetchRequest++;
    data.value = null;
    loading.value = false;
    error.value = '';
  }

  return { data, loading, error, fetch, reset };
});
