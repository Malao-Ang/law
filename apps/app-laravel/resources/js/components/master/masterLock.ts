import type { MasterItem, MasterKind } from '../../types/masterData';

type LockItem = Pick<MasterItem, 'is_system' | 'is_active' | 'usage_count'>;

export interface LockText {
  reason: string;
  hint: string;
}

export function isLocked(_item: LockItem, _kind: MasterKind): boolean {
  return false;
}

export function lockReason(_item: LockItem, _kind: MasterKind): LockText | null {
  return null;
}

export function toggleText(item: LockItem, _kind: MasterKind): LockText {
  return { reason: item.is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน', hint: '' };
}

export function lockAriaLabel(item: LockItem, _kind: MasterKind): string {
  return item.is_active ? 'ใช้งานอยู่ — ปิดใช้งาน' : 'ปิดใช้งานอยู่ — เปิดใช้งาน';
}

export function usageLink(kind: MasterKind, code: string, count: number | undefined): string | null {
  if ((count ?? 0) <= 0) return null;
  const filters: Partial<Record<MasterKind, string>> = {
    law_family: 'family', law_type: 'type', law_category: 'category',
    enforcement_status: 'status', change_status: 'change', legal_structure: 'structure',
  };
  const filter = filters[kind];
  return filter ? `/admin/laws?${filter}=${encodeURIComponent(code)}` : null;
}
