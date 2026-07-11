import { defineStore } from 'pinia';
import { ref } from 'vue';
import { searchLaws, suggestLaws } from '../api/client';
import type { LawSearchFacets, LawSearchFilters, LawSearchResponse, LawSearchResult, LawSuggestion, LawSuggestResponse } from '../types/lawSearch';

const EMPTY_FACETS: LawSearchFacets = {
  law_type: [],
  status: [],
  change_status: [],
  agency: [],
  law_group: [],
  signer_group: [],
  years: [],
};

export const useLawSearchStore = defineStore('lawSearch', () => {
  const results = ref<LawSearchResult[]>([]);
  const facets = ref<LawSearchFacets>({ ...EMPTY_FACETS });
  const total = ref(0);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const suggestions = ref<LawSuggestion[]>([]);
  const suggesting = ref(false);
  const suggestError = ref<string | null>(null);
  let suggestController: AbortController | null = null;

  async function search(q: string, filters: LawSearchFilters, page = 1, perPage = 20): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
      const response: LawSearchResponse = await searchLaws({ q, filters, page, per_page: perPage });
      results.value = response.results;
      facets.value = response.facets;
      total.value = response.total;
    } catch (errorValue) {
      error.value = errorValue instanceof Error ? errorValue.message : 'ค้นหาไม่พร้อมใช้งาน';
      results.value = [];
      facets.value = { ...EMPTY_FACETS };
      total.value = 0;
    } finally {
      loading.value = false;
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

  return {
    results,
    facets,
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
