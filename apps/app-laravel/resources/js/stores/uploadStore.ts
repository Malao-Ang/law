import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchStatus, uploadDocument } from '../api/client';
import type { DocumentStatus, ScanExtractionMode } from '../types/document';

export interface UploadHistoryEntry {
  documentId: string;
  filename: string;
  status: 'done' | 'failed' | 'cancelled';
  finalStatus?: string;
  at: string; // ISO date string
}

export const useUploadStore = defineStore('upload', () => {
  const status = ref<DocumentStatus | null>(null);
  const pollError = ref('');
  const history = ref<UploadHistoryEntry[]>([]);

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

  function recordHistory(documentId: string, outcome: 'done' | 'failed' | 'cancelled'): void {
    history.value.unshift({
      documentId,
      filename: status.value?.source_file ?? 'เอกสาร',
      status: outcome,
      finalStatus: status.value?.status,
      at: new Date().toISOString(),
    });
  }

  function cancelCurrent(documentId: string): void {
    recordHistory(documentId, 'cancelled');
    reset();
  }

  function finalize(documentId: string): void {
    const s = status.value?.status;
    if (s === 'done' || s === 'exported' || s === 'ingested') {
      recordHistory(documentId, 'done');
    } else if (s === 'failed') {
      recordHistory(documentId, 'failed');
    }
  }

  function reset(): void {
    status.value = null;
    pollError.value = '';
  }

  return { status, pollError, history, upload, pollOnce, cancelCurrent, finalize, reset };
});
