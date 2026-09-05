import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchStatus, uploadDocument } from '../api/client';
import type { DocumentStatus, ScanExtractionMode } from '../types/document';

const TERMINAL_STATUSES = new Set<DocumentStatus['status']>(['done', 'failed', 'exported', 'ingested']);

const USER_GEMINI_BUSY = 'ระบบประมวลผลเอกสารหนาแน่นชั่วคราว กรุณารอสักครู่แล้วลองอีกครั้ง';
const USER_GEMINI_UNAVAILABLE = 'ไม่สามารถอ่านเอกสารได้ในขณะนี้ กรุณาลองอีกครั้ง หรือติดต่อผู้ดูแลระบบ';

export function formatGeminiUploadError(raw: string): string {
  const text = raw.trim();
  if (text) {
    console.error('[ocr]', text);
  }
  if (/HTTP 503|high demand|UNAVAILABLE/i.test(text)) {
    return USER_GEMINI_BUSY;
  }
  if (/HTTP 429|RESOURCE_EXHAUSTED|credits are depleted|quota/i.test(text)) {
    return USER_GEMINI_UNAVAILABLE;
  }
  return USER_GEMINI_UNAVAILABLE;
}

export async function waitForDocumentStatus(
  documentId: string,
  options: { intervalMs?: number; timeoutMs?: number } = {},
): Promise<DocumentStatus> {
  const intervalMs = options.intervalMs ?? 1500;
  const timeoutMs = options.timeoutMs ?? 30 * 60 * 1000;
  const started = Date.now();

  while (Date.now() - started < timeoutMs) {
    const current = await fetchStatus(documentId);
    if (TERMINAL_STATUSES.has(current.status)) {
      return current;
    }
    await new Promise((resolve) => setTimeout(resolve, intervalMs));
  }

  throw new Error('หมดเวลารอการประมวลผล Gemini');
}

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
    opts: { documentType?: 'new' | 'old'; source?: string; lawType?: string } = {},
  ): Promise<string> {
    const response = await uploadDocument(file, scanMode, engine, opts);
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
