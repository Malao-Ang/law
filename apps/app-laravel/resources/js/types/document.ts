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
  display_width_px?: number | null;
  display_height_px?: number | null;
}

export interface ReviewedTableCell {
  text: string;
  colspan: number;
  rowspan: number;
  alignment?: 'left' | 'center' | 'right' | 'justify' | null;
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
  alignment?: 'left' | 'center' | 'right' | 'justify' | null;
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

export interface SpellSuggestion {
  original: string;
  suggestions: string[];
}

export interface BlockFormatting {
  bold?: boolean;
  italic?: boolean;
  underline?: boolean;
}

export interface BlockReviewMeta {
  approved_by?: string | null;
  notes?: string | null;
  updated_at?: string | null;
}

export interface BlockMeta {
  section_path?: string | null;
  table_html?: string | null;
  table_display_width_px?: number | null;
  reviewed_html?: string | null;
  table_confidence?: number | null;
  table_detection_reason?: string | null;
  layout?: BlockLayout;
  list_marker?: ListMarker | null;
  image?: BlockImage | null;
  table?: ReviewedTable | null;
  spell_suggestions?: SpellSuggestion[];
  formatting?: BlockFormatting;
  review?: BlockReviewMeta;
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

export type ScanExtractionMode = 'auto' | 'local' | 'landingai' | 'gemini';

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

export type ThaiFont = 'sarabun' | 'psk-sarabun' | 'angsana';

export interface DocumentMetadata {
  department: string;
  doc_number: string;
  date: string;
  subject: string;
  recipient: string;
  reference: string;
  attachments: string;
  urgency: string;
  confidentiality: string;
  signatory_name: string;
  signatory_position: string;
}

export interface ComposeState {
  font_family: ThaiFont;
  font_size_pt: number;
  metadata: DocumentMetadata;
}

export interface LawMeta {
  status: string;
  law_type: string;
  law_group: string;
  agency: string;
  promulgation_date: string;
  effective_date: string;
  gazette_reference: string;
  royal_command: string;
  repealed_laws: string[];
}

export type RelationType = 'related' | 'repeals';
export type RelationScope = 'document' | 'section';

export interface LawRelation {
  id: string;
  scope: RelationScope;
  block_id: string | null;
  type: RelationType;
  target_document_id: string | null;
  target_title: string;
  target_section: string | null;
  note: string | null;
  url: string | null;
}

export interface DocumentListItem {
  document_id: string;
  title: string;
  status: string;
  updated_at?: string | null;
}

export interface ReviewDocument {
  document_id: string;
  source_file: string;
  source_type: 'docx' | 'pdf_text' | 'pdf_scan' | 'pdf_mixed';
  language: 'th';
  summary: DocumentSummary;
  pages: DocumentPage[];
  document_review: DocumentReviewState;
  compose_state?: ComposeState;
  law_meta?: LawMeta;
  relations?: LawRelation[];
  timings?: Record<string, number> | null;
  extraction?: {
    scan_extraction_mode_requested?: ScanExtractionMode;
    scan_extraction_mode_effective?: ScanExtractionMode;
    path?: string[];
    conversion?: {
      tool: string;
      duration_ms?: number | null;
      exit_code?: number | null;
      soffice_version?: string | null;
    } | null;
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
    gemini?: {
      source?: string | null;
      model?: string | null;
      page_count?: number | null;
      duration_ms?: number | null;
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
  scan_extraction_mode_requested?: ScanExtractionMode;
  scan_extraction_mode_effective?: ScanExtractionMode;
  extraction_path?: string[] | null;
  conversion?: {
    tool: string;
    duration_ms?: number | null;
    exit_code?: number | null;
    soffice_version?: string | null;
  } | null;
  extraction_engine?: 'standard' | 'fast';
  correction_status?: 'not_required' | 'pending' | 'in_progress' | 'done' | 'failed';
  fast_fallback_reason?: string;
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
  compose_state?: ComposeState;
  law_meta?: LawMeta;
  relations?: LawRelation[];
}

export interface PreviewData {
  html: string;
  draft_html: string;
  html_mode: 'generated' | 'manual';
  source_file?: string | null;
}
