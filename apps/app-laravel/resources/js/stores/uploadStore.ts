import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchStatus, uploadDocument } from '../api/client';
import type { DocumentStatus, ScanExtractionMode } from '../types/document';

export const useUploadStore = defineStore('upload', () => {
  const status = ref<DocumentStatus | null>(null);
  const pollError = ref('');

  async function upload(
    file: File,
    scanMode: ScanExtractionMode,
    engine: 'standard' | 'fast',
  ): Promise<string> {
    const response = await uploadDocument(file, scanMode, engine);
    return response.document_id;
  }

  async function pollOnce(documentId: string): Promise<DocumentStatus | null> {
    pollError.value = '';
    try {
      status.value = await fetchStatus(documentId);
      return status.value;
    } catch (e: unknown) {
      pollError.value = e instanceof Error ? e.message : 'ไม่สามารถตรวจสอบสถานะได้';
      return null;
    }
  }

  function reset(): void {
    status.value = null;
    pollError.value = '';
  }

  return { status, pollError, upload, pollOnce, reset };
});
