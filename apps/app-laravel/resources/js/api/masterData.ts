import { jsonRequest } from './client';
import type {
  MasterItem,
  MasterKind,
  MasterListParams,
  MasterListResponse,
  UpsertMasterItemPayload,
} from '../types/masterData';

function masterPath(kind: MasterKind, code?: string): string {
  const base = `/api/master-data/${encodeURIComponent(kind)}`;
  return code ? `${base}/${encodeURIComponent(code)}` : base;
}

function queryString(params: MasterListParams = {}): string {
  const search = new URLSearchParams();
  if (params.q) search.set('q', params.q);
  if (params.active) search.set('active', params.active);
  if (params.page) search.set('page', String(params.page));
  if (params.per_page) search.set('per_page', String(params.per_page));
  const qs = search.toString();

  return qs ? `?${qs}` : '';
}

export function listMaster(kind: MasterKind, params: MasterListParams = {}): Promise<MasterListResponse> {
  return jsonRequest<MasterListResponse>(`${masterPath(kind)}${queryString(params)}`);
}

export function getMaster(kind: MasterKind, code: string): Promise<MasterItem> {
  return jsonRequest<MasterItem>(masterPath(kind, code));
}

export function createMaster(kind: MasterKind, payload: UpsertMasterItemPayload): Promise<MasterItem> {
  return jsonRequest<MasterItem>(masterPath(kind), {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function updateMaster(kind: MasterKind, code: string, payload: UpsertMasterItemPayload): Promise<MasterItem> {
  return jsonRequest<MasterItem>(masterPath(kind, code), {
    method: 'PUT',
    body: JSON.stringify(payload),
  });
}

export function setMasterActive(kind: MasterKind, code: string, isActive: boolean): Promise<MasterItem> {
  return jsonRequest<MasterItem>(`${masterPath(kind, code)}/active`, {
    method: 'PATCH',
    body: JSON.stringify({ is_active: isActive }),
  });
}

export function reorderMaster(kind: MasterKind, codes: string[]): Promise<{ status: string }> {
  return jsonRequest<{ status: string }>(`${masterPath(kind)}/reorder`, {
    method: 'PATCH',
    body: JSON.stringify({ codes }),
  });
}
