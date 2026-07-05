import type {
  ComposeState,
  DocumentBlock,
  DocumentListItem,
  DocumentStatus,
  ExportResponse,
  LayoutPatch,
  LawMeta,
  LawRelation,
  PreviewData,
  ReprocessResponse,
  ReviewDocument,
  ReviewedTable,
  ScanExtractionMode,
  UpdateDocumentReviewResponse,
  UploadResponse,
} from '../types/document';

type ApiErrorPayload = {
  message?: string;
  error?: string;
  errors?: Record<string, string[]>;
};

export async function jsonRequest<T>(input: RequestInfo, init?: RequestInit): Promise<T> {
  const isFormData = init?.body instanceof FormData;
  const response = await fetch(input, {
    headers: isFormData
      ? {
          Accept: 'application/json',
          ...(init?.headers ?? {}),
        }
      : {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          ...(init?.headers ?? {}),
        },
    ...init,
  });

  if (!response.ok) {
    const fallback = `HTTP ${response.status}`;
    let payload: ApiErrorPayload | null = null;

    try {
      payload = (await response.json()) as ApiErrorPayload;
    } catch {
      payload = null;
    }

    const firstValidationError = payload?.errors
      ? Object.values(payload.errors).flat()[0]
      : undefined;

    throw new Error(firstValidationError ?? payload?.message ?? payload?.error ?? fallback);
  }

  return (await response.json()) as T;
}

export async function uploadDocument(
  file: File,
  scanExtractionMode: ScanExtractionMode = 'gemini',
  extractionEngine: 'standard' | 'fast' = 'standard',
): Promise<UploadResponse> {
  const form = new FormData();
  form.append('file', file);
  form.append('scan_extraction_mode', scanExtractionMode);
  form.append('extraction_engine', extractionEngine);

  return jsonRequest<UploadResponse>('/api/documents', {
    method: 'POST',
    body: form,
  });
}

export function fetchDocumentList(): Promise<{ documents: DocumentListItem[] }> {
  return jsonRequest<{ documents: DocumentListItem[] }>('/api/documents');
}

export function fetchStatus(documentId: string): Promise<DocumentStatus> {
  return jsonRequest<DocumentStatus>(`/api/documents/${documentId}`);
}

export function fetchReview(documentId: string): Promise<ReviewDocument> {
  return jsonRequest<ReviewDocument>(`/api/documents/${documentId}/review`);
}

export function fetchPreview(documentId: string): Promise<PreviewData> {
  return jsonRequest<PreviewData>(`/api/documents/${documentId}/preview`);
}

export function saveDocumentReview(
  documentId: string,
  payload: {
    draft_html?: string;
    approved_by?: string;
    notes?: string;
    reset_to_generated?: boolean;
    law_meta?: Partial<LawMeta>;
    relations?: LawRelation[];
  },
): Promise<UpdateDocumentReviewResponse> {
  return jsonRequest<UpdateDocumentReviewResponse>(`/api/documents/${documentId}/document-review`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  });
}

export function updateComposeState(
  documentId: string,
  payload: Partial<ComposeState>,
): Promise<UpdateDocumentReviewResponse> {
  return jsonRequest<UpdateDocumentReviewResponse>(`/api/documents/${documentId}/document-review`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  });
}

export function patchBlock(
  documentId: string,
  blockId: string,
  payload: {
    page_no: number;
    approved_text: string;
    approved_by?: string;
    notes?: string;
    mark_uncertain: boolean;
    type?: string;
    reading_order?: number;
    bbox?: [number, number, number, number] | null;
    reviewed_html?: string;
    table?: ReviewedTable | null;
    chunk_type?: string | null;
  },
): Promise<{ status: string }> {
  return jsonRequest(`/api/documents/${documentId}/blocks/${blockId}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  });
}

export function reprocessBlock(
  documentId: string,
  blockId: string,
  payload: { page_no: number; mode: 'ai_correction' },
): Promise<ReprocessResponse> {
  return jsonRequest<ReprocessResponse>(`/api/documents/${documentId}/blocks/${blockId}/reprocess`, {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function patchBlockLayout(
  documentId: string,
  blockId: string,
  payload: LayoutPatch,
): Promise<{ status: string }> {
  return jsonRequest(`/api/documents/${documentId}/blocks/${blockId}/layout`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  });
}

export function patchBlockSize(
  documentId: string,
  blockId: string,
  payload: {
    page_no: number;
    display_width_px: number | null;
    display_height_px: number | null;
  },
): Promise<{ status: string }> {
  return jsonRequest(`/api/documents/${documentId}/blocks/${blockId}/size`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  });
}

export function exportDocument(documentId: string): Promise<ExportResponse> {
  return jsonRequest<ExportResponse>(`/api/documents/${documentId}/export`, {
    method: 'POST',
    body: JSON.stringify({}),
  });
}

export function reprocessPageWithLandingAI(
  documentId: string,
  pageNo: number,
  scanExtractionMode: ScanExtractionMode = 'gemini',
): Promise<{ document_id: string; page_no: number; status: string }> {
  return jsonRequest(`/api/documents/${documentId}/pages/${pageNo}/reprocess`, {
    method: 'POST',
    body: JSON.stringify({ page_no: pageNo, scan_extraction_mode: scanExtractionMode }),
  });
}

export function reorderBlocks(
  documentId: string,
  blockIds: string[],
): Promise<{ document_id: string; status: string; reordered_block_ids: string[] }> {
  return jsonRequest(`/api/documents/${documentId}/blocks/reorder`, {
    method: 'POST',
    body: JSON.stringify({ block_ids: blockIds }),
  });
}

export function deleteBlock(
  documentId: string,
  blockId: string,
  pageNo: number,
): Promise<{ status: string }> {
  return jsonRequest(`/api/documents/${documentId}/blocks/${blockId}?page_no=${pageNo}`, {
    method: 'DELETE',
  });
}

export function mergeBlocks(
  documentId: string,
  blockIds: string[],
): Promise<{ status: string; block: DocumentBlock }> {
  return jsonRequest(`/api/documents/${documentId}/blocks/merge`, {
    method: 'POST',
    body: JSON.stringify({ block_ids: blockIds }),
  });
}

export function splitBlock(
  documentId: string,
  blockId: string,
  payload: {
    page_no: number;
    before_text: string;
    before_html: string;
    after_text: string;
    after_html: string;
  },
): Promise<{
  status: string;
  first: DocumentBlock;
  second: DocumentBlock;
}> {
  return jsonRequest(`/api/documents/${documentId}/blocks/${blockId}/split`, {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function createBlock(
  documentId: string,
  payload: {
    page_no: number;
    after_block_id?: string | null;
    type?: string;
    approved_text?: string;
    reviewed_html?: string;
  },
): Promise<{ status: string; block: DocumentBlock }> {
  return jsonRequest(`/api/documents/${documentId}/blocks`, {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function listDocuments(): Promise<{ documents: DocumentListItem[] }> {
  return jsonRequest('/api/documents');
}
