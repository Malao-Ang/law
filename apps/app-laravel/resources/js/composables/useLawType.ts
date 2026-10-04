import { computed, type ComputedRef, type Ref } from 'vue';
import type { DocumentTypeOption, LawFamilyOption, LawSource } from '../api/client';
import { useLookups } from './useLookups';

export type UnitWord = 'ข้อ' | 'มาตรา';
export type GroupedTypeOption =
  | (DocumentTypeOption & { option_type?: 'item' })
  | { option_type: 'subheader'; title: string; value: string; disabled: true };

const UNIVERSITY_ANNOUNCEMENT_CODE = 'LTY01';
const COUNCIL_ANNOUNCEMENT_CODE = 'LTY09';
const ANNOUNCEMENT_FAMILY_CODE = 'LFM03';
const EXTERNAL_FAMILY_CODE = 'LFM04';
const UNSPECIFIED_ANNOUNCEMENT = 'ประกาศ';

const FALLBACK_FAMILIES: LawFamilyOption[] = [
  { title: 'ข้อบังคับ', value: 'LFM01', code: 'LFM01', source: 'internal', color: '#10B981', sort_order: 1 },
  { title: 'ระเบียบ', value: 'LFM02', code: 'LFM02', source: 'internal', color: '#3B82F6', sort_order: 2 },
  { title: UNSPECIFIED_ANNOUNCEMENT, value: ANNOUNCEMENT_FAMILY_CODE, code: ANNOUNCEMENT_FAMILY_CODE, source: 'internal', color: '#FB923C', sort_order: 3 },
  { title: 'กฎหมายภายนอก', value: EXTERNAL_FAMILY_CODE, code: EXTERNAL_FAMILY_CODE, source: 'external', color: '#854D0E', sort_order: 4 },
];

const FALLBACK_TYPES: DocumentTypeOption[] = [
  { title: 'ประกาศที่ออกโดยมหาวิทยาลัย', value: UNIVERSITY_ANNOUNCEMENT_CODE, code: UNIVERSITY_ANNOUNCEMENT_CODE, family_code: ANNOUNCEMENT_FAMILY_CODE, source: 'internal', sort_order: 1 },
  { title: 'ประกาศที่ออกโดยสภามหาวิทยาลัย', value: COUNCIL_ANNOUNCEMENT_CODE, code: COUNCIL_ANNOUNCEMENT_CODE, family_code: ANNOUNCEMENT_FAMILY_CODE, source: 'internal', sort_order: 2 },
  { title: 'ระเบียบ', value: 'LTY02', code: 'LTY02', family_code: 'LFM02', source: 'internal', sort_order: 3 },
  { title: 'ข้อบังคับ', value: 'LTY03', code: 'LTY03', family_code: 'LFM01', source: 'internal', sort_order: 4 },
  { title: 'พระราชกำหนด', value: 'LTY04', code: 'LTY04', family_code: EXTERNAL_FAMILY_CODE, source: 'external', sort_order: 5 },
  { title: 'พระราชบัญญัติ', value: 'LTY05', code: 'LTY05', family_code: EXTERNAL_FAMILY_CODE, source: 'external', sort_order: 6 },
  { title: 'กฎกระทรวง', value: 'LTY06', code: 'LTY06', family_code: EXTERNAL_FAMILY_CODE, source: 'external', sort_order: 7 },
  { title: 'ประกาศกระทรวง', value: 'LTY07', code: 'LTY07', family_code: EXTERNAL_FAMILY_CODE, source: 'external', sort_order: 8 },
  { title: 'กฎหมายภายนอกอื่น ๆ', value: 'LTY08', code: 'LTY08', family_code: EXTERNAL_FAMILY_CODE, source: 'external', sort_order: 9 },
];

const LEGACY_TYPE_ALIASES: Record<string, string> = {
  ประกาศที่ออกโดยมหาวิทยาลัย: UNIVERSITY_ANNOUNCEMENT_CODE,
  คำสั่ง: UNIVERSITY_ANNOUNCEMENT_CODE,
  ประกาศที่ออกโดยสภามหาวิทยาลัย: COUNCIL_ANNOUNCEMENT_CODE,
  มติ: COUNCIL_ANNOUNCEMENT_CODE,
  ระเบียบ: 'LTY02',
  ข้อบังคับ: 'LTY03',
  พระราชกำหนด: 'LTY04',
  'พ.ร.ก.': 'LTY04',
  พระราชบัญญัติ: 'LTY05',
  'พ.ร.บ.': 'LTY05',
  พรบ: 'LTY05',
  กฎกระทรวง: 'LTY06',
  ประกาศกระทรวง: 'LTY07',
  กฎหมายภายนอก: 'LTY08',
  'กฎหมายภายนอกอื่น ๆ': 'LTY08',
};

export type LawTypeCatalogInput = {
  documentTypes?: Ref<DocumentTypeOption[]> | DocumentTypeOption[];
  lawFamilies?: Ref<LawFamilyOption[]> | LawFamilyOption[];
};

function readArray<T>(value: Ref<T[]> | T[] | undefined, fallback: T[]): T[] {
  if (!value) return fallback;
  const items = Array.isArray(value) ? value : value.value;
  return items.length > 0 ? items : fallback;
}

function normalize(value: string | null | undefined): string {
  return (value ?? '').trim();
}

function bySortOrder<T extends { sort_order?: number; title: string; code?: string; value: string }>(items: T[]): T[] {
  return [...items].sort((a, b) => {
    const sort = (a.sort_order ?? 9999) - (b.sort_order ?? 9999);
    if (sort !== 0) return sort;
    return (a.code ?? a.value).localeCompare(b.code ?? b.value, 'th');
  });
}

function sameTitle(a: string, b: string): boolean {
  return normalize(a) === normalize(b);
}

export function createLawTypeCatalog(input: LawTypeCatalogInput = {}) {
  const documentTypes = computed(() => bySortOrder(readArray(input.documentTypes, FALLBACK_TYPES)));
  const lawFamilies = computed(() => readArray(input.lawFamilies, FALLBACK_FAMILIES));

  const typeItem = (value: string | null | undefined): DocumentTypeOption | null => {
    const text = normalize(value);
    if (!text || text === UNSPECIFIED_ANNOUNCEMENT) return null;
    const code = LEGACY_TYPE_ALIASES[text] ?? text;
    return documentTypes.value.find((item) => item.code === code || item.value === code || item.title === text) ?? null;
  };

  const familyItem = (value: string | null | undefined): LawFamilyOption | null => {
    const text = normalize(value);
    if (!text) return null;
    const type = typeItem(text);
    const code = text === UNSPECIFIED_ANNOUNCEMENT ? ANNOUNCEMENT_FAMILY_CODE : (type?.family_code ?? text);
    return lawFamilies.value.find((item) => item.code === code || item.value === code || item.title === text) ?? null;
  };

  const typeLabel = (value: string | null | undefined): string => {
    const text = normalize(value);
    if (text === UNSPECIFIED_ANNOUNCEMENT) return 'ประกาศ (ยังไม่ระบุผู้ออก)';
    return typeItem(text)?.title ?? text;
  };
  const typeFamily = (value: string | null | undefined): string => typeItem(value)?.family_code ?? familyItem(value)?.code ?? '';
  const familyColor = (value: string | null | undefined): string => familyItem(value)?.color ?? '#6B7280';
  const typeSource = (value: string | null | undefined): LawSource => familyItem(value)?.source ?? typeItem(value)?.source ?? 'internal';
  const unitWord = (value: string | null | undefined): UnitWord => typeSource(value) === 'external' ? 'มาตรา' : 'ข้อ';

  const familiesOrdered: ComputedRef<LawFamilyOption[]> = computed(() => bySortOrder(lawFamilies.value));
  const typesByFamily = computed(() => {
    const groups = new Map<string, DocumentTypeOption[]>();
    for (const family of familiesOrdered.value) groups.set(family.code, []);
    for (const type of documentTypes.value) {
      const list = groups.get(type.family_code) ?? [];
      list.push(type);
      groups.set(type.family_code, list);
    }
    return [...groups.entries()]
      .map(([familyCode, types]) => ({
        family: familyItem(familyCode) ?? FALLBACK_FAMILIES.find((family) => family.code === EXTERNAL_FAMILY_CODE)!,
        types: bySortOrder(types),
      }))
      .filter((group) => group.types.length > 0);
  });

  const groupedTypeOptions = computed<GroupedTypeOption[]>(() => {
    const options: GroupedTypeOption[] = [];
    for (const group of typesByFamily.value) {
      const onlyType = group.types.length === 1 ? group.types[0] : null;
      if (!onlyType || !sameTitle(onlyType.title, group.family.title)) {
        options.push({
          option_type: 'subheader',
          title: group.family.title,
          value: `family:${group.family.code}`,
          disabled: true,
        });
      }
      options.push(...group.types.map((type) => ({ ...type, option_type: 'item' as const })));
    }
    return options;
  });

  return {
    typeItem,
    typeLabel,
    typeFamily,
    familyItem,
    familyColor,
    typeSource,
    unitWord,
    familiesOrdered,
    typesByFamily,
    groupedTypeOptions,
  };
}

export function useLawType() {
  const { documentTypes, lawFamilies } = useLookups();
  return createLawTypeCatalog({ documentTypes, lawFamilies });
}
