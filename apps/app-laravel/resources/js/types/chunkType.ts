export const CHUNK_TYPES = [
  'TITLE', 'PREAMBLE', 'BOOK', 'PART', 'CHAPTER', 'SECTION',
  'ARTICLE', 'PARAGRAPH', 'ITEM', 'DEFINITION', 'TRANSITIONAL_PROVISION',
  'ANNEX', 'TABLE', 'NOTE', 'FOOTNOTE', 'SIGNATURE_BLOCK', 'OTHER',
] as const;

export type ChunkType = typeof CHUNK_TYPES[number];

// Structural heading types — the ones isHead() treats as section heads.
export const HEAD_CHUNK_TYPES: readonly ChunkType[] = [
  'TITLE', 'PREAMBLE', 'BOOK', 'PART', 'CHAPTER', 'SECTION', 'ARTICLE', 'ANNEX', 'TRANSITIONAL_PROVISION',
];

export const CHUNK_TYPE_LABELS: Record<ChunkType, string> = {
  TITLE: 'ชื่อกฎหมาย',
  PREAMBLE: 'คำปรารภ',
  BOOK: 'ภาค',
  PART: 'ลักษณะ',
  CHAPTER: 'หมวด',
  SECTION: 'ส่วน',
  ARTICLE: 'มาตรา',
  PARAGRAPH: 'วรรค',
  ITEM: 'รายการ',
  DEFINITION: 'นิยาม',
  TRANSITIONAL_PROVISION: 'บทเฉพาะกาล',
  ANNEX: 'ภาคผนวก',
  TABLE: 'ตาราง',
  NOTE: 'หมายเหตุ',
  FOOTNOTE: 'เชิงอรรถ',
  SIGNATURE_BLOCK: 'ลายเซ็น',
  OTHER: 'อื่นๆ',
};

export const CHUNK_TYPE_COLORS: Record<ChunkType, string> = {
  TITLE: 'indigo',
  PREAMBLE: 'success',
  BOOK: 'pink',
  PART: 'purple',
  CHAPTER: 'deep-purple',
  SECTION: 'red',
  ARTICLE: 'primary',
  PARAGRAPH: 'teal',
  ITEM: 'warning',
  DEFINITION: 'amber',
  TRANSITIONAL_PROVISION: 'cyan',
  ANNEX: 'light-blue',
  TABLE: 'blue-grey',
  NOTE: 'grey',
  FOOTNOTE: 'grey',
  SIGNATURE_BLOCK: 'brown',
  OTHER: 'grey',
};
