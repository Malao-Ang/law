export type LawCommentType = 'question' | 'suggestion' | 'incorrect' | 'other';
export type LawCommentStatus = 'pending' | 'answered';
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
}

export interface LawComment {
  id: string;
  document_id: string;
  type: LawCommentType;
  type_label: string;
  body: string;
  section_ref: string | null;
  visibility: LawCommentVisibility;
  status: LawCommentStatus;
  status_label: string;
  author_name: string;
  is_mine: boolean;
  created_at: string;
  attachments: LawCommentAttachment[];
  reply: LawCommentReply | null;
}

export interface LawCommentCounts {
  all: number;
  types: Record<LawCommentType, number>;
  statuses: Record<LawCommentStatus, number>;
  visibility: { public: number; mine: number };
}

export interface LawCommentList {
  document: { id: string; title: string };
  items: LawComment[];
  total: number;
  counts: LawCommentCounts;
  can_reply: boolean;
}

export const COMMENT_TYPES: Array<{ value: LawCommentType; label: string }> = [
  { value: 'question', label: 'ข้อซักถาม' },
  { value: 'suggestion', label: 'ข้อเสนอแนะ' },
  { value: 'incorrect', label: 'แจ้งข้อมูลไม่ถูกต้อง' },
  { value: 'other', label: 'อื่น ๆ' },
];
