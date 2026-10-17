import type { MasterItem, MasterKind } from '../../types/masterData';

type LockItem = Pick<MasterItem, 'is_system' | 'is_active' | 'usage_count'>;

export interface LockText {
  reason: string;
  hint: string;
}

const USAGE_KINDS: ReadonlySet<MasterKind> = new Set<MasterKind>([
  'law_category',
  'law_type',
  'law_family',
  'legal_structure',
]);

const USAGE_TEXT: Partial<Record<MasterKind, (count: string) => LockText>> = {
  law_category: (count) => ({
    reason: `มีเอกสารใช้งาน ${count} รายการ`,
    hint: 'ย้ายเอกสารไปหมวดอื่นก่อนจึงจะปิดได้',
  }),
  law_type: (count) => ({
    reason: `มีเอกสารใช้งาน ${count} รายการ`,
    hint: 'เปลี่ยนประเภทของเอกสารก่อนจึงจะปิดได้',
  }),
  law_family: (count) => ({
    reason: `มีประเภทหรือเอกสารใช้งาน ${count} รายการ`,
    hint: 'ย้ายประเภทและเอกสารออกจากกลุ่มก่อนจึงจะปิดได้',
  }),
  legal_structure: (count) => ({
    reason: `มีเอกสารใช้งาน ${count} ฉบับ`,
    hint: 'เปลี่ยนหัวข้อในเอกสารก่อนจึงจะปิดได้',
  }),
};

const SYSTEM_TEXT: LockText = { reason: 'รายการของระบบ', hint: 'ไม่สามารถปิดใช้งานได้' };

function usageOf(item: LockItem): number {
  return item.usage_count ?? 0;
}

export function isLocked(item: LockItem, kind: MasterKind): boolean {
  if (!item.is_active) return false;
  if (item.is_system) return true;

  return USAGE_KINDS.has(kind) && usageOf(item) > 0;
}

export function lockReason(item: LockItem, kind: MasterKind): LockText | null {
  if (!isLocked(item, kind)) return null;
  if (item.is_system) return SYSTEM_TEXT;

  const build = USAGE_TEXT[kind];

  return build ? build(usageOf(item).toLocaleString('th-TH')) : null;
}

export function toggleText(item: LockItem, kind: MasterKind): LockText {
  const locked = lockReason(item, kind);
  if (locked) return locked;

  return { reason: item.is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน', hint: '' };
}

export function lockAriaLabel(item: LockItem, kind: MasterKind): string {
  const locked = lockReason(item, kind);
  if (!locked) return item.is_active ? 'ใช้งานอยู่ — ปิดใช้งาน' : 'ปิดใช้งานอยู่ — เปิดใช้งาน';

  return `ใช้งานอยู่ — ล็อก: ${locked.reason} ${locked.hint}`;
}

export function usageLink(kind: MasterKind, code: string, count: number | undefined): string | null {
  if ((count ?? 0) <= 0) return null;
  if (kind === 'law_category') return `/admin/laws?category=${encodeURIComponent(code)}`;
  if (kind === 'law_type') return `/admin/laws?type=${encodeURIComponent(code)}`;

  return null;
}
