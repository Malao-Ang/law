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
  ReportFilters,
  ReportSummary,
  ReviewDocument,
  ReviewedTable,
  ScanExtractionMode,
  UpdateDocumentReviewResponse,
  UploadResponse,
  WorkflowProgressResponse,
  PageMargins,
} from '../types/document';
import type { LawSearchFacets, LawSearchParams, LawSearchResponse, LawSuggestParams, LawSuggestResponse } from '../types/lawSearch';
import type { PermissionDirectoryResponse, PermissionGroup, UpsertPermissionGroupPayload } from '../types/permission';

type ApiErrorPayload = {
  message?: string;
  error?: string;
  errors?: Record<string, string[]>;
};

export type SelectableOption = {
  title: string;
  value: string;
  subtitle?: string;
};

export type LookupData = {
  document_types: SelectableOption[];
  statuses: SelectableOption[];
  change_statuses: SelectableOption[];
  agencies: SelectableOption[];
  law_groups: SelectableOption[];
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

export function getLookups(): Promise<LookupData> {
  return jsonRequest<LookupData>('/api/lookups');
}

export function saveDocumentReview(
  documentId: string,
  payload: {
    draft_html?: string;
    approved_by?: string;
    notes?: string;
    reset_to_generated?: boolean;
    page_margins?: Partial<PageMargins>;
    law_meta?: Partial<LawMeta>;
    relations?: LawRelation[];
  },
): Promise<UpdateDocumentReviewResponse> {
  return jsonRequest<UpdateDocumentReviewResponse>(`/api/documents/${documentId}/document-review`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  });
}

export function updateWorkflowProgress(
  documentId: string,
  completedStep: number,
): Promise<WorkflowProgressResponse> {
  return jsonRequest<WorkflowProgressResponse>(`/api/documents/${documentId}/workflow-progress`, {
    method: 'PATCH',
    body: JSON.stringify({ completed_step: completedStep }),
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

export async function confirmEsign(documentId: string): Promise<void> {
  await exportDocument(documentId);
  await updateWorkflowProgress(documentId, 6);
}

async function downloadBinaryExport(
  documentId: string,
  path: string,
  accept: string,
  fallbackName: string,
): Promise<void> {
  const response = await fetch(path, {
    method: 'POST',
    headers: { Accept: accept },
  });

  if (!response.ok) {
    const data = (await response.json().catch(() => ({}))) as ApiErrorPayload;
    throw new Error(data.message ?? `Export failed (${response.status})`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;

  const disposition = response.headers.get('Content-Disposition') ?? '';
  const starMatch = /filename\*=utf-8''([^;\n]+)/i.exec(disposition);
  if (starMatch) {
    anchor.download = decodeURIComponent(starMatch[1]);
  } else {
    const match = /filename="?([^";\n]+)"?/.exec(disposition);
    anchor.download = match?.[1] ?? fallbackName;
  }

  document.body.appendChild(anchor);
  anchor.click();
  document.body.removeChild(anchor);
  URL.revokeObjectURL(url);
}

export async function downloadPdfExport(documentId: string): Promise<void> {
  return downloadBinaryExport(
    documentId,
    `/api/documents/${documentId}/export-pdf`,
    'application/pdf',
    `document-${documentId}.pdf`,
  );
}

export async function downloadWordExport(documentId: string): Promise<void> {
  return downloadBinaryExport(
    documentId,
    `/api/documents/${documentId}/export-word`,
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    `document-${documentId}.docx`,
  );
}

export function fetchLawFacets(): Promise<LawSearchFacets> {
  return jsonRequest<LawSearchFacets>('/api/laws/facets');
}

export function searchLaws(params: LawSearchParams): Promise<LawSearchResponse> {
  return jsonRequest<LawSearchResponse>('/api/laws/search', {
    method: 'POST',
    body: JSON.stringify(params),
  });
}

export function suggestLaws(params: LawSuggestParams, signal?: AbortSignal): Promise<LawSuggestResponse> {
  return jsonRequest<LawSuggestResponse>('/api/laws/suggest', {
    method: 'POST',
    body: JSON.stringify(params),
    signal,
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

export function restoreBlocks(
  documentId: string,
  pages: Array<{ page_no: number; blocks: DocumentBlock[] }>,
): Promise<{ document_id: string; status: string }> {
  return jsonRequest(`/api/documents/${documentId}/blocks/restore`, {
    method: 'POST',
    body: JSON.stringify({ pages }),
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

export function fetchReportSummary(filters: ReportFilters = {}): Promise<ReportSummary> {
  const params = new URLSearchParams();
  if (filters.date_from) params.set('date_from', filters.date_from);
  if (filters.date_to) params.set('date_to', filters.date_to);
  if (filters.type) params.set('type', filters.type);
  if (filters.status) params.set('status', filters.status);
  for (const g of filters.group ?? []) params.append('group[]', g);
  for (const a of filters.agency ?? []) params.append('agency[]', a);

  const qs = params.toString();
  return jsonRequest<ReportSummary>(`/api/reports/summary${qs ? `?${qs}` : ''}`);
}

export function fetchPermissionDirectory(): Promise<PermissionDirectoryResponse> {
  return jsonRequest<PermissionDirectoryResponse>('/api/permission-directory');
}

export function listPermissionGroups(): Promise<{ groups: PermissionGroup[] }> {
  return jsonRequest<{ groups: PermissionGroup[] }>('/api/permission-groups');
}

export function fetchPermissionGroup(groupId: string): Promise<PermissionGroup> {
  return jsonRequest<PermissionGroup>(`/api/permission-groups/${groupId}`);
}

export function createPermissionGroup(payload: UpsertPermissionGroupPayload): Promise<PermissionGroup> {
  return jsonRequest<PermissionGroup>('/api/permission-groups', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function updatePermissionGroup(groupId: string, payload: UpsertPermissionGroupPayload): Promise<PermissionGroup> {
  return jsonRequest<PermissionGroup>(`/api/permission-groups/${groupId}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  });
}

export function deletePermissionGroup(groupId: string): Promise<{ group_id: string; status: string }> {
  return jsonRequest<{ group_id: string; status: string }>(`/api/permission-groups/${groupId}`, {
    method: 'DELETE',
  });
}
