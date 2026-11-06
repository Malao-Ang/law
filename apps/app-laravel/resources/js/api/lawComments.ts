import { jsonRequest } from './client';
import type { LawComment, LawCommentList, LawCommentType, LawCommentVisibility } from '../types/lawComment';

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

function commentPath(documentId: string): string {
  return `/api/laws/${encodeURIComponent(documentId)}/comments`;
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
