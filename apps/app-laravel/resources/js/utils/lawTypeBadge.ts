import { createLawTypeCatalog } from '../composables/useLawType';

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

const lawTypes = createLawTypeCatalog();

/** external ทุกประเภท → "กฎหมายภายนอก"; internal → สีตามกลุ่มประเภท. */
export function lawBadgeType(lawType: string | null | undefined, isExternal: boolean): LawBadgeType {
  if (isExternal || lawTypes.typeSource(lawType) === 'external') return 'กฎหมายภายนอก';
  const familyCode = lawTypes.familyItem(lawType)?.code ?? lawTypes.typeFamily(lawType);
  if (familyCode === 'LFM02') return 'ระเบียบ';
  if (familyCode === 'LFM01') return 'ข้อบังคับ';
  return 'ประกาศ';
}
