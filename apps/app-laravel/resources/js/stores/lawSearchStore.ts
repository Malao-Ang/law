import { defineStore } from 'pinia';
import { ref } from 'vue';
import { searchLaws, suggestLaws } from '../api/client';
import type { LawSearchFacets, LawSearchFilters, LawSearchMeta, LawSearchResponse, LawSearchResult, LawSuggestion, LawSuggestResponse } from '../types/lawSearch';

const EMPTY_FACETS: LawSearchFacets = {
  law_type: [],
  status: [],
  change_status: [],
  agency: [],
  law_group: [],
  signer_group: [],
  years: [],
};

const EMPTY_META: LawSearchMeta = {
  engine: 'file',
  mode: 'none',
  confidence: 0,
  suggestions: [],
};

type SearchCacheEntry = {
  expiresAt: number;
  response: LawSearchResponse;
};

const SEARCH_CACHE_TTL_MS = 20_000;
const searchCache = new Map<string, SearchCacheEntry>();

export const useLawSearchStore = defineStore('lawSearch', () => {
  const results = ref<LawSearchResult[]>([]);
  const facets = ref<LawSearchFacets>({ ...EMPTY_FACETS });
  const meta = ref<LawSearchMeta>({ ...EMPTY_META });
  const total = ref(0);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const suggestions = ref<LawSuggestion[]>([]);
  const suggesting = ref(false);
  const suggestError = ref<string | null>(null);
  let searchController: AbortController | null = null;
  let suggestController: AbortController | null = null;

  async function search(q: string, filters: LawSearchFilters, page = 1, perPage = 20): Promise<void> {
    const query = q.trim();
    const cacheKey = searchCacheKey(query, filters, page, perPage);
    const cached = cachedSearch(cacheKey);
    if (cached) {
      searchController?.abort();
      searchController = null;
      applySearchResponse(cached);
      error.value = null;
      loading.value = false;
      return;
    }

    searchController?.abort();
    const controller = new AbortController();
    searchController = controller;
    loading.value = true;
    error.value = null;

    try {
      const response: LawSearchResponse = await searchLaws({ q: query, filters, page, per_page: perPage }, controller.signal);
      if (searchController === controller) {
        searchCache.set(cacheKey, { expiresAt: Date.now() + SEARCH_CACHE_TTL_MS, response });
        applySearchResponse(response);
      }
    } catch (errorValue) {
      if (errorValue instanceof Error && errorValue.name === 'AbortError') {
        return;
      }

      if (searchController === controller) {
        error.value = errorValue instanceof Error ? errorValue.message : 'ค้นหาไม่พร้อมใช้งาน';
        results.value = [];
        facets.value = { ...EMPTY_FACETS };
        meta.value = { ...EMPTY_META };
        total.value = 0;
      }
    } finally {
      if (searchController === controller) {
        loading.value = false;
      }
    }
  }

  async function suggest(q: string, size = 8): Promise<void> {
    const query = q.trim();
    if (query.length < 2) {
      clearSuggestions();
      return;
    }

    suggestController?.abort();
    const controller = new AbortController();
    suggestController = controller;
    suggesting.value = true;
    suggestError.value = null;

    try {
      const response: LawSuggestResponse = await suggestLaws({ q: query, size }, controller.signal);
      if (suggestController === controller) {
        suggestions.value = response.suggestions;
      }
    } catch (errorValue) {
      if (errorValue instanceof Error && errorValue.name === 'AbortError') {
        return;
      }

      if (suggestController === controller) {
        suggestError.value = errorValue instanceof Error ? errorValue.message : 'คำแนะนำการค้นหาไม่พร้อมใช้งาน';
        suggestions.value = [];
      }
    } finally {
      if (suggestController === controller) {
        suggesting.value = false;
      }
    }
  }

  function clearSuggestions(): void {
    suggestController?.abort();
    suggestController = null;
    suggestions.value = [];
    suggesting.value = false;
    suggestError.value = null;
  }

  function applySearchResponse(response: LawSearchResponse): void {
    results.value = response.results;
    facets.value = response.facets;
    total.value = response.total;
    meta.value = response.meta ?? { ...EMPTY_META };
  }

  function cachedSearch(key: string): LawSearchResponse | null {
    const entry = searchCache.get(key);
    if (!entry) return null;
    if (entry.expiresAt <= Date.now()) {
      searchCache.delete(key);
      return null;
    }

    return entry.response;
  }

  function searchCacheKey(q: string, filters: LawSearchFilters, page: number, perPage: number): string {
    return JSON.stringify({
      q,
      filters: normalizeFilters(filters),
      page,
      perPage,
    });
  }

  function normalizeFilters(filters: LawSearchFilters): LawSearchFilters {
    return Object.fromEntries(
      Object.entries(filters)
        .filter(([, value]) => value !== undefined && value !== null && (!Array.isArray(value) || value.length > 0))
        .map(([key, value]) => [key, Array.isArray(value) ? [...value].sort() : value]),
    ) as LawSearchFilters;
  }

  return {
    results,
    facets,
    meta,
    total,
    loading,
    error,
    suggestions,
    suggesting,
    suggestError,
    search,
    suggest,
    clearSuggestions,
  };
});
