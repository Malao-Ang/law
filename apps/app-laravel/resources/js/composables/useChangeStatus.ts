import { computed } from 'vue';
import { useLookups } from './useLookups';

// ─── Seed types ──────────────────────────────────────────────────────────────

export type ChangeStatusRole = 'new' | 'whole' | 'section' | 'general' | null;
export type ChangeDetailRole = 'repeals' | 'amends' | null;

export interface ChangeStatusOption {
  title: string;
  value: string;
  code: string;
  source: string;
  has_details: boolean;
  role: string;
}

export interface ChangeDetailOption {
  title: string;
  value: string;
  code: string;
  source: string;
  role: string;
  color: string | null;
  icon: string | null;
}

// ─── Seeds (4 CHG + 4 CHD — authoritative fallback) ──────────────────────────

const SEED_TYPES: ChangeStatusOption[] = [
  { title: 'กฎหมายใหม่',       value: 'CHG01', code: 'CHG01', source: 'both',     has_details: false, role: 'new' },
  { title: 'ปรับปรุงทั้งฉบับ', value: 'CHG02', code: 'CHG02', source: 'both',     has_details: false, role: 'whole' },
  { title: 'ปรับปรุงรายข้อ',   value: 'CHG03', code: 'CHG03', source: 'internal', has_details: true,  role: 'section' },
  { title: 'ปรับปรุงรายมาตรา', value: 'CHG04', code: 'CHG04', source: 'external', has_details: true,  role: 'section' },
];

const SEED_DETAILS: ChangeDetailOption[] = [
  { title: 'ยกเลิกข้อ',    value: 'CHD01', code: 'CHD01', source: 'internal', role: 'repeals', color: 'error',   icon: 'mdi-cancel' },
  { title: 'ยกเลิกมาตรา',  value: 'CHD02', code: 'CHD02', source: 'external', role: 'repeals', color: 'error',   icon: 'mdi-cancel' },
  { title: 'เพิ่มข้อความ', value: 'CHD03', code: 'CHD03', source: 'both',     role: 'amends',  color: 'success', icon: 'mdi-plus' },
  { title: 'แก้ไขข้อความ', value: 'CHD04', code: 'CHD04', source: 'both',     role: 'amends',  color: 'teal',    icon: 'mdi-pencil' },
];

// ─── Legacy alias maps (Thai name → code) ────────────────────────────────────

const TYPE_ALIASES = new Map<string, string>([
  // Thai names (canonical)
  ['กฎหมายใหม่',       'CHG01'],
  ['ปรับปรุงทั้งฉบับ', 'CHG02'],
  ['ปรับปรุงรายข้อ',   'CHG03'],
  ['ปรับปรุงรายมาตรา', 'CHG04'],
  // Legacy stored names that map to whole/section
  ['ยกเลิกทั้งฉบับ',   'CHG02'],
  ['ยกเลิกรายมาตรา',  'CHG04'],
  // Alias that appeared in data
  ['กฎหมายล่าสุด',    'CHG01'],
]);

const DETAIL_ALIASES = new Map<string, string>([
  // Thai names (canonical)
  ['ยกเลิกข้อ',    'CHD01'],
  ['ยกเลิกมาตรา',  'CHD02'],
  ['เพิ่มข้อความ', 'CHD03'],
  ['แก้ไขข้อความ', 'CHD04'],
  // Short legacy aliases
  ['ยกเลิก', 'CHD01'],
  ['เพิ่ม',  'CHD03'],
  ['แก้ไข',  'CHD04'],
]);

// ─── Catalog factory (allows injecting items in tests) ───────────────────────

export interface ChangeStatusCatalogInput {
  types: ChangeStatusOption[];
  typesAll: ChangeStatusOption[];
  details: ChangeDetailOption[];
  detailsAll: ChangeDetailOption[];
}

function normalizeStr(value: unknown): string {
  return String(value ?? '').trim().replace(/\s+/g, ' ');
}

function normalizeCode(value: unknown): string {
  return normalizeStr(value).toLocaleUpperCase();
}

function normalizeText(value: unknown): string {
  return normalizeStr(value).toLocaleLowerCase();
}

function resolveType(
  value: unknown,
  pool: ChangeStatusOption[],
): ChangeStatusOption | null {
  const code = normalizeCode(value);
  const text = normalizeText(value);
  const all = pool.length > 0 ? pool : SEED_TYPES;

  const byCode = all.find((item) => item.code === code || item.value === code);
  if (byCode) return byCode;

  const byTitle = all.find((item) => normalizeText(item.title) === text);
  if (byTitle) return byTitle;

  // Legacy alias lookup
  const aliasCode = TYPE_ALIASES.get(normalizeStr(value));
  if (aliasCode) return all.find((item) => item.code === aliasCode) ?? null;

  return null;
}

function resolveDetail(
  value: unknown,
  pool: ChangeDetailOption[],
): ChangeDetailOption | null {
  const code = normalizeCode(value);
  const text = normalizeText(value);
  const all = pool.length > 0 ? pool : SEED_DETAILS;

  const byCode = all.find((item) => item.code === code || item.value === code);
  if (byCode) return byCode;

  const byTitle = all.find((item) => normalizeText(item.title) === text);
  if (byTitle) return byTitle;

  const aliasCode = DETAIL_ALIASES.get(normalizeStr(value));
  if (aliasCode) return all.find((item) => item.code === aliasCode) ?? null;

  return null;
}

export function createChangeStatusCatalog(input: ChangeStatusCatalogInput) {
  const pool = input.typesAll.length > 0 ? input.typesAll : input.types.length > 0 ? input.types : SEED_TYPES;
  const detailPool = input.detailsAll.length > 0 ? input.detailsAll : input.details.length > 0 ? input.details : SEED_DETAILS;

  function typeItem(value: unknown): ChangeStatusOption | null {
    return resolveType(value, pool);
  }

  function normalize(value: unknown): string {
    return typeItem(value)?.code ?? '';
  }

  function label(value: unknown): string {
    return typeItem(value)?.title ?? normalizeStr(value);
  }

  function role(value: unknown): ChangeStatusRole {
    const r = typeItem(value)?.role ?? null;
    if (r === 'new' || r === 'whole' || r === 'section' || r === 'general') return r;
    return r ? 'general' : null;
  }

  function isNew(value: unknown): boolean { return role(value) === 'new'; }
  function isWhole(value: unknown): boolean { return role(value) === 'whole'; }
  function isSection(value: unknown): boolean { return role(value) === 'section'; }
  function hasDetails(value: unknown): boolean { return typeItem(value)?.has_details === true; }

  function detailItem(value: unknown): ChangeDetailOption | null {
    return resolveDetail(value, detailPool);
  }

  function normalizeDetail(value: unknown): string {
    return detailItem(value)?.code ?? '';
  }

  function detailLabel(value: unknown): string {
    return detailItem(value)?.title ?? normalizeStr(value);
  }

  function detailRole(value: unknown): ChangeDetailRole {
    const r = detailItem(value)?.role ?? null;
    if (r === 'repeals' || r === 'amends') return r;
    return null;
  }

  function detailMeta(value: unknown): Pick<ChangeDetailOption, 'title' | 'color' | 'icon'> | null {
    const item = detailItem(value);
    if (!item) return null;
    return { title: item.title, color: item.color, icon: item.icon };
  }

  function typeOptions(source?: string): ChangeStatusOption[] {
    const active = input.types.length > 0 ? input.types : SEED_TYPES;
    if (!source) return active;
    return active.filter((item) => item.source === 'both' || item.source === source);
  }

  function detailOptions(source?: string): ChangeDetailOption[] {
    const active = input.details.length > 0 ? input.details : SEED_DETAILS;
    if (!source) return active;
    return active.filter((item) => item.source === 'both' || item.source === source);
  }

  return {
    normalize,
    label,
    role,
    isNew,
    isWhole,
    isSection,
    hasDetails,
    normalizeDetail,
    detailLabel,
    detailRole,
    detailMeta,
    typeOptions,
    detailOptions,
  };
}

// ─── Composable (singleton with live reactivity) ──────────────────────────────

export function useChangeStatus() {
  const { changeStatusTypes, changeStatusTypesAll, changeStatusDetails, changeStatusDetailsAll, load } = useLookups();
  void load().catch(() => { /* fallback seeds stay in use */ });

  const catalog = computed(() =>
    createChangeStatusCatalog({
      types: changeStatusTypes.value as ChangeStatusOption[],
      typesAll: changeStatusTypesAll.value as ChangeStatusOption[],
      details: changeStatusDetails.value as ChangeDetailOption[],
      detailsAll: changeStatusDetailsAll.value as ChangeDetailOption[],
    }),
  );

  return {
    normalize: (value: unknown) => catalog.value.normalize(value),
    label: (value: unknown) => catalog.value.label(value),
    role: (value: unknown) => catalog.value.role(value),
    isNew: (value: unknown) => catalog.value.isNew(value),
    isWhole: (value: unknown) => catalog.value.isWhole(value),
    isSection: (value: unknown) => catalog.value.isSection(value),
    hasDetails: (value: unknown) => catalog.value.hasDetails(value),
    normalizeDetail: (value: unknown) => catalog.value.normalizeDetail(value),
    detailLabel: (value: unknown) => catalog.value.detailLabel(value),
    detailRole: (value: unknown) => catalog.value.detailRole(value),
    detailMeta: (value: unknown) => catalog.value.detailMeta(value),
    typeOptions: (source?: string) => catalog.value.typeOptions(source),
    detailOptions: (source?: string) => catalog.value.detailOptions(source),
  };
}
