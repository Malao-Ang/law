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
  if (kind === 'law_category') return `/admin/laws?category=${encodeURIComponent(code)}`;
  if (kind === 'law_type') return `/admin/laws?type=${encodeURIComponent(code)}`;

  return null;
}
