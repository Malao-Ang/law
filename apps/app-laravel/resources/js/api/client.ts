import type {
  DocumentStatus,
  ExportResponse,
  ReprocessResponse,
  ReviewDocument,
  ReviewedTable,
  UpdateDocumentReviewResponse,
  UploadResponse,
} from '../types/document';

async function jsonRequest<T>(input: RequestInfo, init?: RequestInit): Promise<T> {
  const response = await fetch(input, {
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(init?.headers ?? {}),
    },
    ...init,
  });

  if (!response.ok) {
    const fallback = `HTTP ${response.status}`;
    try {
      const payload = (await response.json()) as { message?: string };
      throw new Error(payload.message ?? fallback);
    } catch {
      throw new Error(fallback);
    }
  }

  return (await response.json()) as T;
}

export async function uploadDocument(file: File): Promise<UploadResponse> {
  const form = new FormData();
  form.append('file', file);

  const response = await fetch('/api/documents', {
    method: 'POST',
    body: form,
    headers: {
      Accept: 'application/json',
    },
  });

  if (!response.ok) {
    const text = await response.text();
    throw new Error(text || `Upload failed (${response.status})`);
  }

  return (await response.json()) as UploadResponse;
}

export function fetchStatus(documentId: string): Promise<DocumentStatus> {
  return jsonRequest<DocumentStatus>(`/api/documents/${documentId}`);
}

export function fetchReview(documentId: string): Promise<ReviewDocument> {
  return jsonRequest<ReviewDocument>(`/api/documents/${documentId}/review`);
}

export function saveDocumentReview(
  documentId: string,
  payload: {
    draft_html?: string;
    approved_by?: string;
    notes?: string;
    reset_to_generated?: boolean;
  },
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

export function exportDocument(documentId: string): Promise<ExportResponse> {
  return jsonRequest<ExportResponse>(`/api/documents/${documentId}/export`, {
    method: 'POST',
    body: JSON.stringify({}),
  });
}
