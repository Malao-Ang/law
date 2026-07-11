export interface LawSearchFilters {
  law_type?: string[];
  status?: string[];
  change_status?: string[];
  agency?: string[];
  law_group?: string[];
  signer_group?: string[];
  year_from?: number | null;
  year_to?: number | null;
}

export interface LawSearchParams {
  q: string;
  filters: LawSearchFilters;
  page?: number;
  per_page?: number;
}

export interface LawSuggestParams {
  q: string;
  size?: number;
}

export interface LawSuggestion {
  law_id: string;
  title: string | null;
  law_type: string | null;
  agency: string | null;
  published_date: string | null;
  keywords: string[];
}

export interface LawSuggestResponse {
  suggestions: LawSuggestion[];
}

export interface LawSearchResult {
  law_id: string;
  title: string | null;
  law_type: string | null;
  status: string | null;
  change_status?: string | null;
  summary: string | null;
  published_date: string | null;
  agency: string | null;
  signer_group?: string | null;
  snippets: string[];
}

export interface FacetBucket {
  value: string;
  count: number;
}

export interface YearBucket {
  year: number;
  count: number;
}

export interface LawSearchFacets {
  law_type: FacetBucket[];
  status: FacetBucket[];
  change_status: FacetBucket[];
  agency: FacetBucket[];
  law_group: FacetBucket[];
  signer_group: FacetBucket[];
  years: YearBucket[];
}

export interface LawSearchResponse {
  total: number;
  results: LawSearchResult[];
  facets: LawSearchFacets;
}
