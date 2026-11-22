import { jsonRequest } from './client';
import type {
  AdminLawComment,
  AdminLawCommentList,
  LawComment,
  LawCommentList,
  LawCommentStatus,
  LawCommentType,
  LawCommentVisibility,
} from '../types/lawComment';

export interface LawCommentQuery {
  type?: LawCommentType | '';
  status?: 'pending' | 'answered' | '';
  visibility?: 'all' | 'public' | 'mine';
  q?: string;
  sort?: 'newest' | 'oldest';
  limit?: number;
}

export interface CreateLawCommentInput {
  type: LawCommentType;
  body: string;
  sectionRef?: string;
  visibility: LawCommentVisibility;
  files: File[];
}

export interface AdminCommentQuery {
  status?: 'all' | LawCommentStatus;
  type?: LawCommentType | '';
  unit?: string;
  date?: string;
  q?: string;
  page?: number;
  per_page?: number;
}

export interface AdminReplyInput {
  body: string;
  visibility?: LawCommentVisibility;
  forwardForReview?: boolean;
  asDraft?: boolean;
  files?: File[];
}

function commentPath(documentId: string): string {
  return `/api/laws/${encodeURIComponent(documentId)}/comments`;
}

function adminPath(commentId?: string): string {
  const base = '/api/admin/comments';
  return commentId ? `${base}/${encodeURIComponent(commentId)}` : base;
}

export function fetchLawComments(documentId: string, query: LawCommentQuery = {}): Promise<LawCommentList> {
  const params = new URLSearchParams();
  if (query.type) params.set('type', query.type);
  if (query.status) params.set('status', query.status);
  if (query.visibility && query.visibility !== 'all') params.set('visibility', query.visibility);
  if (query.q) params.set('q', query.q);
  if (query.sort) params.set('sort', query.sort);
  if (query.limit) params.set('limit', String(query.limit));
  const search = params.toString();

  return jsonRequest<LawCommentList>(`${commentPath(documentId)}${search ? `?${search}` : ''}`);
}

export function createLawComment(documentId: string, input: CreateLawCommentInput): Promise<{ comment: LawComment }> {
  const form = new FormData();
  form.append('type', input.type);
  form.append('body', input.body);
  form.append('visibility', input.visibility);
  if (input.sectionRef?.trim()) form.append('section_ref', input.sectionRef.trim());
  input.files.forEach((file) => form.append('attachments[]', file));

  return jsonRequest<{ comment: LawComment }>(commentPath(documentId), { method: 'POST', body: form });
}

export function replyLawComment(documentId: string, commentId: string, body: string): Promise<{ comment: LawComment }> {
  return jsonRequest<{ comment: LawComment }>(`${commentPath(documentId)}/${encodeURIComponent(commentId)}/reply`, {
    method: 'POST',
    body: JSON.stringify({ body }),
  });
}

export function lawCommentFileUrl(documentId: string, commentId: string, fileId: string): string {
  return `${commentPath(documentId)}/${encodeURIComponent(commentId)}/files/${encodeURIComponent(fileId)}`;
}

export function fetchAdminComments(query: AdminCommentQuery = {}): Promise<AdminLawCommentList> {
  const params = new URLSearchParams();
  if (query.status && query.status !== 'all') params.set('status', query.status);
  if (query.type) params.set('type', query.type);
  if (query.unit) params.set('unit', query.unit);
  if (query.date) params.set('date', query.date);
  if (query.q) params.set('q', query.q);
  if (query.page) params.set('page', String(query.page));
  if (query.per_page) params.set('per_page', String(query.per_page));
  const search = params.toString();

  return jsonRequest<AdminLawCommentList>(`${adminPath()}${search ? `?${search}` : ''}`);
}

export function fetchAdminComment(commentId: string): Promise<{ comment: AdminLawComment }> {
  return jsonRequest<{ comment: AdminLawComment }>(adminPath(commentId));
}

export function replyAdminComment(commentId: string, input: AdminReplyInput): Promise<{ comment: AdminLawComment }> {
  const form = new FormData();
  form.append('body', input.body);
  form.append('visibility', input.visibility ?? 'public');
  form.append('forward_for_review', input.forwardForReview ? '1' : '0');
  form.append('as_draft', input.asDraft ? '1' : '0');
  (input.files ?? []).forEach((file) => form.append('attachments[]', file));

  return jsonRequest<{ comment: AdminLawComment }>(`${adminPath(commentId)}/reply`, { method: 'POST', body: form });
}

export function updateAdminCommentVisibility(
  commentId: string,
  visibility: LawCommentVisibility,
  reason: string,
): Promise<{ comment: AdminLawComment }> {
  return jsonRequest<{ comment: AdminLawComment }>(`${adminPath(commentId)}/visibility`, {
    method: 'PATCH',
    body: JSON.stringify({ visibility, reason }),
  });
}

export function archiveAdminComment(commentId: string, reason: string): Promise<{ comment: AdminLawComment }> {
  return jsonRequest<{ comment: AdminLawComment }>(`${adminPath(commentId)}/archive`, {
    method: 'POST',
    body: JSON.stringify({ reason }),
  });
}

export function adminCommentFileUrl(commentId: string, fileId: string): string {
  return `${adminPath(commentId)}/files/${encodeURIComponent(fileId)}`;
}
