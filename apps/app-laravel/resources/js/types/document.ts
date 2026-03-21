export type BlockType =
  | 'title'
  | 'section_header'
  | 'paragraph'
  | 'list_item'
  | 'table'
  | 'figure_caption'
  | 'footnote'
  | 'unknown';

export interface ReviewedTable {
  headers: string[];
  rows: string[][];
  html?: string | null;
}

export interface BlockMeta {
  section_path?: string | null;
  table_html?: string | null;
  reviewed_html?: string | null;
  layout?: {
    bbox: [number, number, number, number] | null;
    reading_order: number | null;
  };
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
  blocks: DocumentBlock[];
}

export interface DocumentSummary {
  page_count: number;
  block_count: number;
  review_required_count: number;
}

export interface ReviewDocument {
  document_id: string;
  source_file: string;
  source_type: 'docx' | 'pdf_text' | 'pdf_scan';
  language: 'th';
  summary: DocumentSummary;
  pages: DocumentPage[];
}

export interface DocumentStatus {
  document_id: string;
  status: 'queued' | 'processing' | 'done' | 'failed' | 'exported';
  progress: number;
  current_step: string;
  source_file?: string;
  review_path?: string;
  export_path?: string;
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
}
