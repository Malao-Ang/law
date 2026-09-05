// Maps ELawLawCard domain enums to the eLaw home design-system badge labels.

export type DocType = 'rabiap' | 'kho-bangkhab' | 'prakat' | 'kotmai-phaainok' | 'other';
export type ChangeStatus = 'new' | 'amended' | 'repealed';

export type LawTypeBadge =
  | 'ระเบียบ' | 'ข้อบังคับ' | 'ประกาศ' | 'ประกาศมหาวิทยาลัย' | 'ประกาศสภา'
  | 'พระราชบัญญัติ' | 'พระราชกำหนด' | 'กฎกระทรวง' | 'ประกาศกระทรวง'
  | 'กฎหมายภายนอก' | 'คำสั่ง' | 'มติ';

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
  ข้อบังคับ: 'ข้อบังคับ',
  ประกาศ: 'ประกาศ',
  'ประกาศที่ออกโดยมหาวิทยาลัย': 'ประกาศมหาวิทยาลัย',
  'ประกาศที่ออกโดยสภามหาวิทยาลัย': 'ประกาศสภา',
  พระราชบัญญัติ: 'พระราชบัญญัติ',
  'พ.ร.บ.': 'พระราชบัญญัติ',
  phrb: 'พระราชบัญญัติ',
  พระราชกำหนด: 'พระราชกำหนด',
  กฎกระทรวง: 'กฎกระทรวง',
  ประกาศกระทรวง: 'ประกาศกระทรวง',
  'kotmai-phaainok': 'กฎหมายภายนอก',
  คำสั่ง: 'คำสั่ง',
  command: 'คำสั่ง',
  มติ: 'มติ',
  resolution: 'มติ',
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

export function docTypeToBadge(type: DocType): LawTypeBadge | null {
  return DOC_TYPE_BADGES[type];
}

export function lawTypeToBadge(lawType: string): LawTypeBadge | null {
  return LAW_TYPE_TO_BADGE[lawType] ?? null;
}

export function changeStatusToBadge(status: ChangeStatus): StatusBadge {
  return CHANGE_STATUS_BADGES[status];
}
