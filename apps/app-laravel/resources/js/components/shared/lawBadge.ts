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

const DOC_TYPE_BADGES: Record<DocType, LawTypeBadge | null> = {
  rabiap: 'ระเบียบ',
  'kho-bangkhab': 'ข้อบังคับ',
  prakat: 'ประกาศ',
  'kotmai-phaainok': 'กฎหมายภายนอก',
  other: null,
};

export const LAW_TYPE_TO_BADGE: Record<string, LawTypeBadge> = {
  ระเบียบ: 'ระเบียบ',
  LTY02: 'ระเบียบ',
  ข้อบังคับ: 'ข้อบังคับ',
  LTY03: 'ข้อบังคับ',
  ประกาศ: 'ประกาศ',
  LTY01: 'ประกาศ',
  'ประกาศที่ออกโดยมหาวิทยาลัย': 'ประกาศ',
  'ประกาศที่ออกโดยสภามหาวิทยาลัย': 'ประกาศ',
  พระราชบัญญัติ: 'พระราชบัญญัติ',
  LTY05: 'พระราชบัญญัติ',
  'พ.ร.บ.': 'พระราชบัญญัติ',
  phrb: 'พระราชบัญญัติ',
  พระราชกำหนด: 'พระราชกำหนด',
  LTY04: 'พระราชกำหนด',
  กฎกระทรวง: 'กฎกระทรวง',
  LTY06: 'กฎกระทรวง',
  ประกาศกระทรวง: 'ประกาศกระทรวง',
  LTY07: 'ประกาศกระทรวง',
  'kotmai-phaainok': 'กฎหมายภายนอก',
  LTY08: 'กฎหมายภายนอก',
  คำสั่ง: 'ประกาศ',
  command: 'ประกาศ',
  มติ: 'ประกาศ',
  resolution: 'ประกาศ',
};

export const LAW_TYPE_TO_DOC_TYPE: Record<string, LawTypeCardClass> = {
  ระเบียบ: 'rabiap',
  rabiap: 'rabiap',
  ข้อบังคับ: 'kho-bangkhab',
  'kho-bangkhab': 'kho-bangkhab',
  ประกาศ: 'prakat',
  prakat: 'prakat',
  'ประกาศที่ออกโดยมหาวิทยาลัย': 'prakat',
  'ประกาศที่ออกโดยสภามหาวิทยาลัย': 'prakat',
  พระราชบัญญัติ: 'prb',
  'พ.ร.บ.': 'prb',
  phrb: 'prb',
  พระราชกำหนด: 'phrk',
  กฎกระทรวง: 'kotmai-krw',
  ประกาศกระทรวง: 'prakat-krw',
  'kotmai-phaainok': 'kotmai-phaainok',
  'kotmai-krung': 'kotmai-phaainok',
  กฎหมายภายนอก: 'kotmai-phaainok',
  คำสั่ง: 'command',
  command: 'command',
  มติ: 'resolution',
  resolution: 'resolution',
};

const CHANGE_STATUS_BADGES: Record<ChangeStatus, StatusBadge> = {
  new: 'ใหม่ล่าสุด',
  amended: 'ปรับปรุงรายมาตรา',
  repealed: 'ยกเลิกแล้ว',
};

const lawTypes = createLawTypeCatalog();

export function docTypeToBadge(type: DocType): LawTypeBadge | null {
  return DOC_TYPE_BADGES[type];
}

export function lawTypeToBadge(lawType: string): LawTypeBadge | null {
  const direct = LAW_TYPE_TO_BADGE[lawType];
  if (direct) return direct;
  const familyCode = lawTypes.typeFamily(lawType);
  if (familyCode === 'LFM04') return 'กฎหมายภายนอก';
  if (familyCode === 'LFM01') return 'ข้อบังคับ';
  if (familyCode === 'LFM02') return 'ระเบียบ';
  if (familyCode === 'LFM03') return 'ประกาศ';
  return null;
}

export function changeStatusToBadge(status: ChangeStatus): StatusBadge {
  return CHANGE_STATUS_BADGES[status];
}
