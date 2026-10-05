import { computed, onMounted, type Ref } from 'vue';
import { useLookups } from './useLookups';
import {
  FALLBACK_LEGAL_STRUCTURES,
  LEGACY_CHUNK_TYPE_MAP,
  type LegalStructureFileType,
} from '../types/chunkType';
import type { LegalStructureOption } from '../api/client';

export type LegalStructureCatalogInput = {
  items?: Ref<LegalStructureOption[]> | LegalStructureOption[];
  itemsAll?: Ref<LegalStructureOption[]> | LegalStructureOption[];
};

function readArray<T>(value: Ref<T[]> | T[] | undefined, fallback: T[]): T[] {
  if (!value) return fallback;
  const items = Array.isArray(value) ? value : value.value;
  return items.length > 0 ? items : fallback;
}

function normalizeText(value: string | null | undefined): string {
  return (value ?? '').trim();
}

function normalizeCode(value: string | null | undefined): string {
  return normalizeText(value).toUpperCase();
}

function sortLegalStructures(items: LegalStructureOption[]): LegalStructureOption[] {
  return [...items].sort((a, b) => {
    const sort = (a.sort_order ?? 9999) - (b.sort_order ?? 9999);
    if (sort !== 0) return sort;
    return a.code.localeCompare(b.code, 'th');
  });
}

function fallbackItem(code: string): LegalStructureOption | null {
  const item = FALLBACK_LEGAL_STRUCTURES.find((candidate) => candidate.code === code);
  return item ? toLegalStructureOption(item) : null;
}

function toLegalStructureOption(item: typeof FALLBACK_LEGAL_STRUCTURES[number]): LegalStructureOption {
  return {
    ...item,
    family_codes: [...item.family_codes],
    file_types: [...item.file_types],
  };
}

export function createLegalStructureCatalog(input: LegalStructureCatalogInput = {}) {
  const fallback = FALLBACK_LEGAL_STRUCTURES.map(toLegalStructureOption);
  const activeItems = computed(() => sortLegalStructures(readArray(input.items, fallback)));
  const allItems = computed(() => sortLegalStructures(readArray(input.itemsAll, activeItems.value)));

  const item = (codeOrLegacy: string | null | undefined): LegalStructureOption | null => {
    const code = normalize(codeOrLegacy);
    if (!code) return null;
    return allItems.value.find((candidate) => candidate.code === code) ?? fallbackItem(code);
  };

  const label = (codeOrLegacy: string | null | undefined): string => {
    const raw = normalizeText(codeOrLegacy);
    return item(raw)?.title ?? raw;
  };

  const color = (codeOrLegacy: string | null | undefined): string => item(codeOrLegacy)?.color ?? 'blue-grey';
  const isHead = (codeOrLegacy: string | null | undefined): boolean => item(codeOrLegacy)?.is_head ?? false;
  const countsAsSection = (codeOrLegacy: string | null | undefined): boolean => item(codeOrLegacy)?.counts_as_section ?? false;
  const isRequired = (codeOrLegacy: string | null | undefined): boolean => item(codeOrLegacy)?.is_required ?? false;

  function normalize(codeOrLegacy: string | null | undefined): string {
    const raw = normalizeCode(codeOrLegacy);
    if (!raw) return '';
    if (/^LST\d{3}$/u.test(raw)) return raw;
    return LEGACY_CHUNK_TYPE_MAP[raw] ?? allItems.value.find((candidate) => candidate.export_key === raw)?.code ?? '';
  }

  function optionsFor(familyCode: string | null | undefined, fileType: LegalStructureFileType | null | undefined): LegalStructureOption[] {
    const family = normalizeCode(familyCode);
    const file = normalizeText(fileType).toLowerCase();
    if (!family || (file !== 'word' && file !== 'pdf')) return [];
    return activeItems.value.filter((candidate) =>
      candidate.family_codes.includes(family) &&
      candidate.file_types.includes(file as LegalStructureFileType),
    );
  }

  function supports(codeOrLegacy: string | null | undefined, familyCode: string | null | undefined, fileType: LegalStructureFileType | null | undefined): boolean {
    const code = normalize(codeOrLegacy);
    return optionsFor(familyCode, fileType).some((candidate) => candidate.code === code);
  }

  function fileTypeOf(sourceType: string | null | undefined): LegalStructureFileType | null {
    const value = normalizeText(sourceType).toLowerCase();
    if (value === 'doc' || value === 'docx') return 'word';
    if (value === 'pdf' || value === 'pdf_text' || value === 'pdf_scan' || value === 'pdf_mixed') return 'pdf';
    return null;
  }

  return {
    items: activeItems,
    itemsAll: allItems,
    item,
    label,
    color,
    isHead,
    countsAsSection,
    isRequired,
    normalize,
    optionsFor,
    supports,
    fileTypeOf,
  };
}

export function useLegalStructure() {
  const lookups = useLookups();
  onMounted(() => {
    void lookups.load();
  });
  return createLegalStructureCatalog({
    items: lookups.legalStructures,
    itemsAll: lookups.legalStructuresAll,
  });
}
