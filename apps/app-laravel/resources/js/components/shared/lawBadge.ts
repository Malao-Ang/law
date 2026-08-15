// Maps ELawLawCard domain enums to the eLaw home design-system badge labels.
// Card enums are a slightly different set than the design's four document types;
// "kotmai-phaainok" covers external laws, while "other" has no badge.

export type DocType = 'rabiap' | 'kho-bangkhab' | 'prakat' | 'kotmai-phaainok' | 'other';
export type ChangeStatus = 'new' | 'amended' | 'repealed';

export type LawTypeBadge = 'กฎหมายภายนอก' | 'ระเบียบ' | 'ข้อบังคับ' | 'ประกาศ';
export type StatusBadge = 'ใหม่ล่าสุด' | 'ปรับปรุงรายมาตรา' | 'ยกเลิกแล้ว';

const DOC_TYPE_BADGES: Record<DocType, LawTypeBadge | null> = {
  rabiap: 'ระเบียบ',
  'kho-bangkhab': 'ข้อบังคับ',
  prakat: 'ประกาศ',
  'kotmai-phaainok': 'กฎหมายภายนอก',
  other: null,
};

const CHANGE_STATUS_BADGES: Record<ChangeStatus, StatusBadge> = {
  new: 'ใหม่ล่าสุด',
  amended: 'ปรับปรุงรายมาตรา',
  repealed: 'ยกเลิกแล้ว',
};

export function docTypeToBadge(type: DocType): LawTypeBadge | null {
  return DOC_TYPE_BADGES[type];
}

export function changeStatusToBadge(status: ChangeStatus): StatusBadge {
  return CHANGE_STATUS_BADGES[status];
}
