import { computed, type Ref } from 'vue';
import type { SelectableOption } from '../api/client';
import { useLookups } from './useLookups';

export type LawCategoryOption = SelectableOption & {
  code: string;
  sort_order: number;
};

const FALLBACK_CATEGORIES: LawCategoryOption[] = [
  { title: 'ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร', value: 'DCT001', code: 'DCT001', sort_order: 1 },
  { title: 'ด้านกิจการนิสิต', value: 'DCT002', code: 'DCT002', sort_order: 2 },
  { title: 'ด้านการวิจัย นวัตกรรม และการนำไปใช้ประโยชน์', value: 'DCT003', code: 'DCT003', sort_order: 3 },
  { title: 'ด้านบริการวิชาการ', value: 'DCT004', code: 'DCT004', sort_order: 4 },
  { title: 'ด้านการทะนุบำรุงศิลปวัฒนธรรม', value: 'DCT005', code: 'DCT005', sort_order: 5 },
  { title: 'ด้านโครงสร้างองค์กรและระบบการบริหาร', value: 'DCT006', code: 'DCT006', sort_order: 6 },
  { title: 'ด้านการบริหารงานบุคคล สิทธิประโยชน์ วินัยและจรรยาบรรณ', value: 'DCT007', code: 'DCT007', sort_order: 7 },
  { title: 'ด้านการเงินและทรัพย์สิน พัสดุ การตรวจสอบ และการบริหารความเสี่ยง', value: 'DCT008', code: 'DCT008', sort_order: 8 },
  { title: 'ด้านการพัฒนารายได้', value: 'DCT009', code: 'DCT009', sort_order: 9 },
  { title: 'ด้านการรักษาพยาบาล', value: 'DCT010', code: 'DCT010', sort_order: 10 },
  { title: 'ด้านการบริการเฉพาะด้าน เช่น ทันตกรรม', value: 'DCT011', code: 'DCT011', sort_order: 11 },
  { title: 'ด้านอื่น ๆ', value: 'DCT012', code: 'DCT012', sort_order: 12 },
];

// Slug aliases used in old URL query params (e.g. ?group=academic)
const SLUG_ALIASES: Record<string, string> = {
  academic: 'DCT001',
  'student-affairs': 'DCT002',
  'research-innovation': 'DCT003',
  'academic-service': 'DCT004',
  'organization-admin': 'DCT006',
  'hr-discipline': 'DCT007',
  'finance-assets-risk': 'DCT008',
  other: 'DCT012',
};

export type LawCategoryCatalogInput = {
  /** Active categories: used for select/filter options. */
  lawGroups?: Ref<LawCategoryOption[]> | LawCategoryOption[];
  /** All categories incl. inactive: used to resolve labels of existing documents. */
  lawGroupsAll?: Ref<LawCategoryOption[]> | LawCategoryOption[];
};

function readArray<T>(value: Ref<T[]> | T[] | undefined, fallback: T[]): T[] {
  if (!value) return fallback;
  const items = Array.isArray(value) ? value : value.value;
  return items.length > 0 ? items : fallback;
}

function normalize(value: string | null | undefined): string {
  return (value ?? '').trim();
}

function bySortOrder(items: LawCategoryOption[]): LawCategoryOption[] {
  return [...items].sort((a, b) => {
    const sort = (a.sort_order ?? 9999) - (b.sort_order ?? 9999);
    if (sort !== 0) return sort;
    return a.code.localeCompare(b.code, 'th');
  });
}

export function createLawCategoryCatalog(input: LawCategoryCatalogInput = {}) {
  const categories = computed(() => readArray(input.lawGroups, FALLBACK_CATEGORIES));
  const allCategories = computed(() => readArray(input.lawGroupsAll, categories.value));

  const categoryItem = (value: string | null | undefined): LawCategoryOption | null => {
    const text = normalize(value);
    if (!text) return null;
    // Try slug alias first
    const viaSlug = SLUG_ALIASES[text];
    const code = viaSlug ?? text;
    return (
      allCategories.value.find(
        (item) => item.code === code || item.value === code || item.title === text,
      ) ?? FALLBACK_CATEGORIES.find(
        (item) => item.code === code || item.title === text,
      ) ?? null
    );
  };

  const categoryLabel = (value: string | null | undefined): string =>
    categoryItem(value)?.title ?? normalize(value);

  const categoryLabels = (values: string[] | null | undefined): string[] => {
    if (!values || values.length === 0) return [];
    const seen = new Set<string>();
    const result: string[] = [];
    for (const v of values) {
      const label = categoryLabel(v);
      if (label && !seen.has(label)) {
        seen.add(label);
        result.push(label);
      }
    }
    return result;
  };

  const categoryOptions = computed<LawCategoryOption[]>(() => bySortOrder(categories.value));

  const normalizeCategory = (value: string | null | undefined): string => {
    const text = normalize(value);
    if (!text) return text;
    return categoryItem(text)?.code ?? text;
  };

  return {
    categoryItem,
    categoryLabel,
    categoryLabels,
    categoryOptions,
    normalizeCategory,
  };
}

/** Live catalog backed by /api/lookups (renames and new categories show up without a deploy). */
export function useLawCategory() {
  const { lawGroups, lawGroupsAll, load } = useLookups();
  void load().catch(() => { /* fallback list stays in use */ });
  return createLawCategoryCatalog({
    lawGroups: lawGroups as Ref<LawCategoryOption[]>,
    lawGroupsAll: lawGroupsAll as Ref<LawCategoryOption[]>,
  });
}
