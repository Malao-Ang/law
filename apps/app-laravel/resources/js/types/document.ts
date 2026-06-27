export type BlockType =
  | 'title'
  | 'section_header'
  | 'paragraph'
  | 'list_item'
  | 'table'
  | 'image'
  | 'figure_caption'
  | 'footnote'
  | 'unknown';

export type ListMarkerType =
  | 'thai-numeral'
  | 'arabic'
  | 'legal-มาตรา'
  | 'legal-ข้อ'
  | 'legal-วรรค'
  | 'paren'
  | 'bullet';

export interface ListMarker {
  text: string;
  type: ListMarkerType;
  level: number;
  raw_match: string;
}

export interface BlockImage {
  src_path: string;
  src_url?: string | null;
  data_uri?: string | null;
  width?: number | null;
  height?: number | null;
  caption?: string | null;
}

export interface ReviewedTableCell {
  text: string;
  colspan: number;
  rowspan: number;
  alignment?: 'left' | 'center' | 'right' | 'justify' | string | null;
}

export interface TabStop {
  position: number;
  type: 'left' | 'center' | 'right' | 'decimal';
}

export interface ReviewedTable {
  headers: string[];
  rows: string[][];
  cells?: ReviewedTableCell[][];
  html?: string | null;
}

export interface BlockLayout {
  bbox: [number, number, number, number] | null;
  reading_order: number | null;
  alignment?: 'left' | 'center' | 'right' | 'justify' | string | null;
  indent_left?: number | null;
  indent_first_line?: number | null;
  indent_hanging?: number | null;
  indent_level?: number | null;
  indent_unit_pt?: number | null;
  indent_source?: string | null;
  indent_reason?: string | null;
  first_line_inferred?: string | null;
  tabs?: TabStop[];
}

export interface LayoutPatch {
  page_no: number;
  indent_level?: number | null;
  list_marker_level?: number | null;
  alignment?: 'left' | 'center' | 'right' | 'justify' | null;
  indent_left?: number | null;
  indent_first_line?: number | null;
  indent_hanging?: number | null;
  tabs?: TabStop[] | null;
}

export interface BlockMeta {
  section_path?: string | null;
  table_html?: string | null;
  reviewed_html?: string | null;
  table_confidence?: number | null;
  table_detection_reason?: string | null;
  layout?: BlockLayout;
  list_marker?: ListMarker | null;
  image?: BlockImage | null;
  table?: ReviewedTable | null;
  review?: {
    approved_by?: string | null;
    notes?: string | null;
    updated_at?: string | null;
  };
  [key: string]: unknown;
}

export interface DocumentBlock {
  block_id: string;
  type: BlockType;
  bbox: [number, number, number, number] | null;
  reading_order: number;
  raw_text: string;
  normalized_text: string;
  ai_suggested_text: string;
  approved_text: string;
  confidence: number;
  needs_review: boolean;
  flags: string[];
  meta: BlockMeta;
}

export interface DocumentPage {
  page_no: number;
  image_path: string | null;
  image_url?: string | null;
  source_kind?: 'docx' | 'pdf_text' | 'pdf_scan';
  blocks: DocumentBlock[];
}

export interface DocumentSummary {
  page_count: number;
  block_count: number;
  review_required_count: number;
}

export interface DocumentReviewState {
  generated_html: string;
  draft_html: string;
  html_mode: 'generated' | 'manual';
  out_of_sync: boolean;
  updated_at?: string | null;
  approved_by?: string | null;
  notes?: string | null;
}

export interface ReviewDocument {
  document_id: string;
  source_file: string;
  source_type: 'docx' | 'pdf_text' | 'pdf_scan' | 'pdf_mixed';
  language: 'th';
  summary: DocumentSummary;
  pages: DocumentPage[];
  document_review: DocumentReviewState;
  timings?: Record<string, number> | null;
  extraction?: {
    scan_extraction_mode_requested?: 'auto' | 'local' | 'landingai';
    scan_extraction_mode_effective?: 'auto' | 'local' | 'landingai';
    path?: string[];
    landingai?: {
      status_code?: number | null;
      filename?: string | null;
      org_id?: string | null;
      page_count?: number | null;
      duration_ms?: number | null;
      credit_usage?: number | null;
      job_id?: string | null;
      version?: string | null;
      failed_pages?: number[];
    } | null;
  } | null;
}

export interface DocumentStatus {
  document_id: string;
  status: 'queued' | 'processing' | 'done' | 'failed' | 'exported' | 'ingesting' | 'ingested';
  progress: number;
  current_step: string;
  source_file?: string;
  review_path?: string;
  export_path?: string;
  ingest_path?: string;
  ingested_chunk_count?: number;
  scan_extraction_mode_requested?: 'auto' | 'local' | 'landingai';
  scan_extraction_mode_effective?: 'auto' | 'local' | 'landingai';
  extraction_path?: string[] | null;
  timings?: Record<string, number> | null;
  error?: string;
}

export interface UploadResponse {
  document_id: string;
  status: 'queued';
}

export interface ReprocessResponse {
  document_id: string;
  page_no: number;
  block_id: string;
  status: 'queued';
}

export interface ExportResponse {
  document_id: string;
  status: 'exported';
  export_path: string;
  rag_status: 'queued';
}

export interface UpdateDocumentReviewResponse {
  document_id: string;
  status: 'updated';
  document_review: DocumentReviewState;
}
