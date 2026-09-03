// map law_type → DocBadge law-type badge + สี border หัวเอกสาร.
// ค่าสีตรงกับ DocBadge.vue (Figma design system).
// ponytail: 4 hex นี้ mirror DocBadge STYLES โดยตรง — ถ้า DocBadge เปลี่ยนสี ให้แก้ที่นี่ด้วย.

export type LawBadgeType = 'กฎหมายภายนอก' | 'ระเบียบ' | 'ข้อบังคับ' | 'ประกาศ';

export const LAW_BADGE_COLORS: Record<LawBadgeType, string> = {
  'กฎหมายภายนอก': '#854d0e',
  'ระเบียบ': '#3b82f6',
  'ข้อบังคับ': '#10b981',
  'ประกาศ': '#fb923c',
};

/** external ทุกประเภท → "กฎหมายภายนอก"; internal → สีเฉพาะประเภท (default = ประกาศ). */
export function lawBadgeType(lawType: string | null | undefined, isExternal: boolean): LawBadgeType {
  if (isExternal) return 'กฎหมายภายนอก';
  if (lawType === 'ระเบียบ') return 'ระเบียบ';
  if (lawType === 'ข้อบังคับ') return 'ข้อบังคับ';
  return 'ประกาศ';
}
