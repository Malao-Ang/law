export const CHUNK_TYPES = [
  'TITLE',
  'PREAMBLE',
  'AUTHORITY',
  'CLAUSE',
  'EFFECTIVE_DATE',
  'REPEAL',
  'DEFINITION_SECTION',
  'DEFINITION',
  'CUSTODIAN',
  'TRANSITIONAL_PROVISION',
] as const;

export type ChunkType = typeof CHUNK_TYPES[number];

// Structural heading types — the ones isHead() treats as section heads.
export const HEAD_CHUNK_TYPES: readonly ChunkType[] = [
  'TITLE',
  'PREAMBLE',
  'AUTHORITY',
  'CLAUSE',
  'EFFECTIVE_DATE',
  'REPEAL',
  'DEFINITION_SECTION',
  'CUSTODIAN',
  'TRANSITIONAL_PROVISION',
];

export const CHUNK_TYPE_LABELS: Record<ChunkType, string> = {
  TITLE: 'ชื่อประกาศ',
  PREAMBLE: 'คำปรารภ',
  AUTHORITY: 'บทอาศัยอำนาจ',
  CLAUSE: 'ข้อ',
  EFFECTIVE_DATE: 'วันบังคับใช้',
  REPEAL: 'บทยกเลิก',
  DEFINITION_SECTION: 'บทนิยาม',
  DEFINITION: 'คำนิยาม',
  CUSTODIAN: 'บทรักษาการ',
  TRANSITIONAL_PROVISION: 'บทเฉพาะกาล',
};

export const CHUNK_TYPE_COLORS: Record<ChunkType, string> = {
  TITLE: 'indigo',
  PREAMBLE: 'success',
  AUTHORITY: 'deep-purple',
  CLAUSE: 'orange',
  EFFECTIVE_DATE: 'teal',
  REPEAL: 'red',
  DEFINITION_SECTION: 'amber-darken-2',
  DEFINITION: 'amber',
  CUSTODIAN: 'brown',
  TRANSITIONAL_PROVISION: 'cyan',
};

const LEGACY_CHUNK_TYPE_MAP: Partial<Record<string, ChunkType>> = {
  TITLE: 'TITLE',
  PREAMBLE: 'PREAMBLE',
  AUTHORITY: 'AUTHORITY',
  CLAUSE: 'CLAUSE',
  EFFECTIVE_DATE: 'EFFECTIVE_DATE',
  REPEAL: 'REPEAL',
  DEFINITION_SECTION: 'DEFINITION_SECTION',
  DEFINITION: 'DEFINITION',
  CUSTODIAN: 'CUSTODIAN',
  TRANSITIONAL_PROVISION: 'TRANSITIONAL_PROVISION',
  BOOK: 'CLAUSE',
  PART: 'CLAUSE',
  CHAPTER: 'CLAUSE',
  SECTION: 'CLAUSE',
  ARTICLE: 'CLAUSE',
  PARAGRAPH: 'CLAUSE',
  ITEM: 'CLAUSE',
};

export function normalizeChunkType(value: string | null | undefined): ChunkType | undefined {
  if (!value) return undefined;
  return LEGACY_CHUNK_TYPE_MAP[value.trim().toUpperCase()];
}
