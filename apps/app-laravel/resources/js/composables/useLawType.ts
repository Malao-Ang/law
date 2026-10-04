import { computed, type ComputedRef, type Ref } from 'vue';
import type { DocumentTypeOption, IssuerOption, LawFamilyOption, LawSource } from '../api/client';
import { useLookups } from './useLookups';

export type UnitWord = 'ข้อ' | 'มาตรา';

const ANNOUNCEMENT_CODE = 'LTY01';
const UNIVERSITY_ISSUER_CODE = 'ISS01';
const COUNCIL_ISSUER_CODE = 'ISS02';
const EXTERNAL_FAMILY_CODE = 'LFM04';

const FALLBACK_FAMILIES: LawFamilyOption[] = [
  { title: 'ข้อบังคับ', value: 'LFM01', code: 'LFM01', source: 'internal', color: '#10B981', sort_order: 1 },
  { title: 'ระเบียบ', value: 'LFM02', code: 'LFM02', source: 'internal', color: '#3B82F6', sort_order: 2 },
  { title: 'ประกาศ', value: 'LFM03', code: 'LFM03', source: 'internal', color: '#FB923C', sort_order: 3 },
  { title: 'กฎหมายภายนอก', value: 'LFM04', code: 'LFM04', source: 'external', color: '#854D0E', sort_order: 4 },
];

const FALLBACK_TYPES: DocumentTypeOption[] = [
  { title: 'ประกาศ', value: 'LTY01', code: 'LTY01', family_code: 'LFM03', source: 'internal', requires_issuer: true },
  { title: 'ระเบียบ', value: 'LTY02', code: 'LTY02', family_code: 'LFM02', source: 'internal', requires_issuer: false },
  { title: 'ข้อบังคับ', value: 'LTY03', code: 'LTY03', family_code: 'LFM01', source: 'internal', requires_issuer: false },
  { title: 'พระราชกำหนด', value: 'LTY04', code: 'LTY04', family_code: 'LFM04', source: 'external', requires_issuer: false },
  { title: 'พระราชบัญญัติ', value: 'LTY05', code: 'LTY05', family_code: 'LFM04', source: 'external', requires_issuer: false },
  { title: 'กฎกระทรวง', value: 'LTY06', code: 'LTY06', family_code: 'LFM04', source: 'external', requires_issuer: false },
  { title: 'ประกาศกระทรวง', value: 'LTY07', code: 'LTY07', family_code: 'LFM04', source: 'external', requires_issuer: false },
  { title: 'กฎหมายภายนอกอื่น ๆ', value: 'LTY08', code: 'LTY08', family_code: 'LFM04', source: 'external', requires_issuer: false },
];

const FALLBACK_ISSUERS: IssuerOption[] = [
  { title: 'มหาวิทยาลัย', value: 'ISS01', code: 'ISS01', sort_order: 1 },
  { title: 'สภามหาวิทยาลัย', value: 'ISS02', code: 'ISS02', sort_order: 2 },
];

const LEGACY_TYPE_ALIASES: Record<string, string> = {
  ประกาศ: ANNOUNCEMENT_CODE,
  'ประกาศที่ออกโดยมหาวิทยาลัย': ANNOUNCEMENT_CODE,
  'ประกาศที่ออกโดยสภามหาวิทยาลัย': ANNOUNCEMENT_CODE,
  คำสั่ง: ANNOUNCEMENT_CODE,
  มติ: ANNOUNCEMENT_CODE,
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

const LEGACY_ISSUER_ALIASES: Record<string, string> = {
  มหาวิทยาลัย: UNIVERSITY_ISSUER_CODE,
  สภามหาวิทยาลัย: COUNCIL_ISSUER_CODE,
  'ประกาศที่ออกโดยมหาวิทยาลัย': UNIVERSITY_ISSUER_CODE,
  คำสั่ง: UNIVERSITY_ISSUER_CODE,
  'ประกาศที่ออกโดยสภามหาวิทยาลัย': COUNCIL_ISSUER_CODE,
  มติ: COUNCIL_ISSUER_CODE,
};

export type LawTypeCatalogInput = {
  documentTypes?: Ref<DocumentTypeOption[]> | DocumentTypeOption[];
  lawFamilies?: Ref<LawFamilyOption[]> | LawFamilyOption[];
  issuers?: Ref<IssuerOption[]> | IssuerOption[];
};

function readArray<T>(value: Ref<T[]> | T[] | undefined, fallback: T[]): T[] {
  if (!value) return fallback;
  return Array.isArray(value) ? value : value.value;
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

export function legacyIssuerForType(value: string | null | undefined): string | null {
  return LEGACY_ISSUER_ALIASES[normalize(value)] ?? null;
}

export function createLawTypeCatalog(input: LawTypeCatalogInput = {}) {
  const documentTypes = computed(() => readArray(input.documentTypes, FALLBACK_TYPES));
  const lawFamilies = computed(() => readArray(input.lawFamilies, FALLBACK_FAMILIES));
  const issuers = computed(() => readArray(input.issuers, FALLBACK_ISSUERS));

  const typeItem = (value: string | null | undefined): DocumentTypeOption | null => {
    const text = normalize(value);
    if (!text) return null;
    const code = LEGACY_TYPE_ALIASES[text] ?? text;
    return documentTypes.value.find((item) => item.code === code || item.value === code || item.title === text) ?? null;
  };

  const familyItem = (value: string | null | undefined): LawFamilyOption | null => {
    const text = normalize(value);
    if (!text) return null;
    const type = typeItem(text);
    const code = type?.family_code ?? text;
    return lawFamilies.value.find((item) => item.code === code || item.value === code || item.title === text) ?? null;
  };

  const issuerItem = (value: string | null | undefined): IssuerOption | null => {
    const text = normalize(value);
    if (!text) return null;
    const code = LEGACY_ISSUER_ALIASES[text] ?? text;
    return issuers.value.find((item) => item.code === code || item.value === code || item.title === text) ?? null;
  };

  const typeLabel = (value: string | null | undefined): string => typeItem(value)?.title ?? normalize(value);
  const typeFamily = (value: string | null | undefined): string => typeItem(value)?.family_code ?? familyItem(value)?.code ?? '';
  const familyColor = (value: string | null | undefined): string => familyItem(value)?.color ?? '#6B7280';
  const typeSource = (value: string | null | undefined): LawSource => familyItem(value)?.source ?? typeItem(value)?.source ?? 'internal';
  const unitWord = (value: string | null | undefined): UnitWord => typeSource(value) === 'external' ? 'มาตรา' : 'ข้อ';
  const requiresIssuer = (value: string | null | undefined): boolean => typeItem(value)?.requires_issuer === true;
  const issuerLabel = (value: string | null | undefined): string => issuerItem(value)?.title ?? normalize(value);
  const issuerRank = (value: string | null | undefined): number => issuerItem(value)?.sort_order ?? 9999;

  const familiesOrdered: ComputedRef<LawFamilyOption[]> = computed(() => bySortOrder(lawFamilies.value));
  const typesGroupedByFamily = computed(() => {
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
        types,
      }))
      .filter((group) => group.types.length > 0);
  });

  return {
    typeItem,
    typeLabel,
    typeFamily,
    familyItem,
    familyColor,
    typeSource,
    unitWord,
    requiresIssuer,
    issuerItem,
    issuerLabel,
    issuerRank,
    familiesOrdered,
    typesGroupedByFamily,
  };
}

export function useLawType() {
  const { documentTypes, lawFamilies, issuers } = useLookups();
  return createLawTypeCatalog({ documentTypes, lawFamilies, issuers });
}
