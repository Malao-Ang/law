// Maps ELawLawCard domain enums to the eLaw home design-system badge labels.
import { createLawTypeCatalog } from '../../composables/useLawType';

export type DocType = 'rabiap' | 'kho-bangkhab' | 'prakat' | 'kotmai-phaainok' | 'other';
export type ChangeStatus = 'new' | 'amended' | 'repealed';

export type LawTypeBadge =
  | 'ระเบียบ' | 'ข้อบังคับ' | 'ประกาศ'
  | 'พระราชบัญญัติ' | 'พระราชกำหนด' | 'กฎกระทรวง' | 'ประกาศกระทรวง'
  | 'กฎหมายภายนอก';

export type StatusBadge = 'ใหม่ล่าสุด' | 'ปรับปรุงรายมาตรา' | 'ปรับปรุงทั้งฉบับ' | 'ยกเลิกบางส่วน' | 'ยกเลิกแล้ว';
export type LawTypeCardClass = DocType | 'prb' | 'phrk' | 'kotmai-krw' | 'prakat-krw' | 'command' | 'resolution';

const FAMILY_CODES_BY_DOC_TYPE: Record<Exclude<DocType, 'other'>, string> = {
  rabiap: 'LFM02',
  'kho-bangkhab': 'LFM01',
  prakat: 'LFM03',
  'kotmai-phaainok': 'LFM04',
};

const EXTERNAL_BADGE_TYPE_CODES = new Set(['LTY04', 'LTY05', 'LTY06', 'LTY07']);

const TYPE_CARD_CLASSES: Record<string, LawTypeCardClass> = {
  LTY01: 'prakat',
  LTY02: 'rabiap',
  LTY03: 'kho-bangkhab',
  LTY04: 'phrk',
  LTY05: 'prb',
  LTY06: 'kotmai-krw',
  LTY07: 'prakat-krw',
  LTY08: 'kotmai-phaainok',
  LTY09: 'prakat',
};
const CHANGE_STATUS_BADGES: Record<ChangeStatus, StatusBadge> = {
  new: 'ใหม่ล่าสุด',
  amended: 'ปรับปรุงรายมาตรา',
  repealed: 'ยกเลิกแล้ว',
};

const lawTypes = createLawTypeCatalog();

function badge(value: string | null | undefined): LawTypeBadge | null {
  return (value || null) as LawTypeBadge | null;
}

export function docTypeToBadge(type: DocType): LawTypeBadge | null {
  if (type === 'other') return null;
  return badge(lawTypes.familyItem(FAMILY_CODES_BY_DOC_TYPE[type])?.title);
}

export function lawTypeToBadge(lawType: string): LawTypeBadge | null {
  const item = lawTypes.typeItem(lawType);
  const family = lawTypes.familyItem(item?.family_code ?? lawType);
  const familyCode = item?.family_code ?? family?.code;

  if (familyCode === 'LFM04' && item?.code && EXTERNAL_BADGE_TYPE_CODES.has(item.code)) return badge(item.title);
  if (familyCode === 'LFM04') return badge(family?.title);
  if (familyCode) return badge(family?.title ?? item?.title);

  return null;
}

export function lawTypeToCardClass(lawType: string | null | undefined, fallback: LawTypeCardClass = 'other'): LawTypeCardClass {
  const item = lawTypes.typeItem(lawType);
  if (item?.code && TYPE_CARD_CLASSES[item.code]) return TYPE_CARD_CLASSES[item.code];

  const familyCode = lawTypes.typeFamily(lawType);
  if (familyCode === 'LFM01') return 'kho-bangkhab';
  if (familyCode === 'LFM02') return 'rabiap';
  if (familyCode === 'LFM03') return 'prakat';
  if (familyCode === 'LFM04') return 'kotmai-phaainok';

  return fallback;
}
export function changeStatusToBadge(status: ChangeStatus): StatusBadge {
  return CHANGE_STATUS_BADGES[status];
}
