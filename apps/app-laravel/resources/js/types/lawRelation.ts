import type { LawRelation, RelationType } from './document';

export const RELATION_TYPES = [
  'related',
  'repeals',
  'amends',
  'issued_under',
  'supersedes',
] as const satisfies readonly RelationType[];

export const RELATION_TYPE_LABELS: Record<RelationType, string> = {
  related: 'เกี่ยวข้อง',
  repeals: 'ยกเลิก',
  amends: 'แก้ไขเพิ่มเติม',
  issued_under: 'ออกตามอำนาจ',
  supersedes: 'แทนที่',
};

export const RELATION_TYPE_COLORS: Record<RelationType, string> = {
  related: 'primary',
  repeals: 'error',
  amends: 'teal',
  issued_under: 'deep-purple',
  supersedes: 'orange-darken-2',
};

export const RELATION_TYPE_ICONS: Record<RelationType, string> = {
  related: 'mdi-link-variant',
  repeals: 'mdi-cancel',
  amends: 'mdi-file-edit-outline',
  issued_under: 'mdi-source-branch',
  supersedes: 'mdi-file-replace-outline',
};

export function relationTypeLabel(type: RelationType | string | null | undefined): string {
  if (type && type in RELATION_TYPE_LABELS) {
    return RELATION_TYPE_LABELS[type as RelationType];
  }
  return RELATION_TYPE_LABELS.related;
}

export function formatRelationTarget(rel: LawRelation): string {
  const parts = [rel.target_title.trim()];
  const section = rel.target_section?.trim();
  if (section) {
    parts.push(section);
  }
  return parts.join(' · ');
}

export function formatRelationChip(rel: LawRelation): string {
  return `${relationTypeLabel(rel.type)} · ${formatRelationTarget(rel)}`;
}

export const SECTION_CHANGE_DETAILS = [
  { value: 'ยกเลิกข้อ', title: 'ยกเลิกข้อ', color: 'error', icon: 'mdi-cancel' },
  { value: 'เพิ่มข้อความ', title: 'เพิ่มข้อความ', color: 'success', icon: 'mdi-plus' },
  { value: 'แก้ไขข้อความ', title: 'แก้ไขข้อความ', color: 'teal', icon: 'mdi-pencil' },
] as const;

export type SectionChangeDetail = (typeof SECTION_CHANGE_DETAILS)[number]['value'];

const LEGACY_CHANGE_DETAILS: Record<string, SectionChangeDetail> = {
  ยกเลิก: 'ยกเลิกข้อ',
  ยกเลิกมาตรา: 'ยกเลิกข้อ',
  เพิ่ม: 'เพิ่มข้อความ',
  แก้ไข: 'แก้ไขข้อความ',
};

export function normalizeChangeDetail(detail: string | null | undefined): SectionChangeDetail | null {
  const value = detail?.trim() ?? '';
  if (value === '') return null;
  if (value === 'ยกเลิกข้อ' || value === 'เพิ่มข้อความ' || value === 'แก้ไขข้อความ') return value;
  return LEGACY_CHANGE_DETAILS[value] ?? null;
}

export function relationTypeFromChangeDetail(detail: string | null | undefined): RelationType | undefined {
  const normalized = normalizeChangeDetail(detail);
  if (normalized === 'ยกเลิกข้อ') return 'repeals';
  if (normalized === 'เพิ่มข้อความ' || normalized === 'แก้ไขข้อความ') return 'amends';
  return undefined;
}

export function changeDetailMeta(detail: string | null | undefined) {
  const normalized = normalizeChangeDetail(detail);
  return SECTION_CHANGE_DETAILS.find((item) => item.value === normalized) ?? null;
}

export function uniqueRelationChangeDetails(relations: LawRelation[]): string[] {
  const details: string[] = [];
  for (const relation of relations) {
    const value = normalizeChangeDetail(relation.change_detail);
    if (value && !details.includes(value)) details.push(value);
  }
  return details;
}
