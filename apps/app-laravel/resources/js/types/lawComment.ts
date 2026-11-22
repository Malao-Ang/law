export type LawCommentType = 'question' | 'suggestion' | 'incorrect' | 'other';
export type LawCommentStatus = 'pending' | 'answered' | 'archived';
export type LawCommentVisibility = 'public' | 'private';

export interface LawCommentAttachment {
  id: string;
  name: string;
  size: number;
  mime: string;
}

export interface LawCommentReply {
  body: string;
  author_name: string;
  created_at: string;
  attachments?: LawCommentAttachment[];
}

export interface LawCommentReplyVersion {
  id: string;
  version: number;
  body: string;
  author_name: string;
  created_at: string;
  status: 'draft' | 'pending_review' | 'published' | string;
  visibility: LawCommentVisibility;
  attachments: LawCommentAttachment[];
  is_latest: boolean;
}

export interface LawCommentReplyDraft {
  body: string;
  visibility: LawCommentVisibility;
  forward_for_review: boolean;
  updated_at: string;
  attachments: LawCommentAttachment[];
}

export interface LawComment {
  id: string;
  code?: string;
  document_id: string;
  type: LawCommentType;
  type_label: string;
  body: string;
  section_ref: string | null;
  visibility: LawCommentVisibility;
  reveal_name?: boolean;
  status: LawCommentStatus;
  status_label: string;
  author_name: string;
  is_mine: boolean;
  created_at: string;
  attachments: LawCommentAttachment[];
  reply: LawCommentReply | null;
}

export interface AdminLawComment extends LawComment {
  code: string;
  document_title: string;
  unit_code: string | null;
  unit_name: string;
  agency: string;
  notify: boolean;
  reveal_name: boolean;
  attachment_count: number;
  body_preview?: string;
  replies?: LawCommentReplyVersion[];
  reply_draft?: LawCommentReplyDraft | null;
  archive_reason?: string | null;
  archived_at?: string | null;
  visibility_history?: Array<{
    from: LawCommentVisibility;
    to: LawCommentVisibility;
    reason: string;
    author_name: string;
    created_at: string;
  }>;
}

export interface LawCommentCounts {
  all: number;
  types: Record<LawCommentType, number>;
  statuses: Record<string, number>;
  visibility: { public: number; mine: number };
}

export interface LawCommentList {
  document: { id: string; title: string };
  items: LawComment[];
  total: number;
  counts: LawCommentCounts;
  can_reply: boolean;
}

export interface AdminLawCommentList {
  items: AdminLawComment[];
  total: number;
  page: number;
  per_page: number;
  last_page: number;
  counts: {
    all: number;
    pending: number;
    answered: number;
    archived: number;
  };
  types: Record<string, string>;
  statuses: Record<string, string>;
  units: Array<{ code: string; name: string }>;
}

export const COMMENT_TYPES: Array<{ value: LawCommentType; label: string }> = [
  { value: 'question', label: 'ข้อซักถาม' },
  { value: 'suggestion', label: 'ข้อเสนอแนะ' },
  { value: 'incorrect', label: 'แจ้งข้อมูลไม่ถูกต้อง' },
  { value: 'other', label: 'อื่น ๆ' },
];

export const COMMENT_STATUS_META: Record<LawCommentStatus, { label: string; color: string }> = {
  pending: { label: 'รอพิจารณา', color: 'warning' },
  answered: { label: 'ตอบแล้ว', color: 'success' },
  archived: { label: 'จัดเก็บเข้าคลัง', color: 'grey' },
};
