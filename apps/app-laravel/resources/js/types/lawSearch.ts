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

export interface LawSearchMeta {
  engine: 'elastic' | 'file' | 'mixed' | string;
  mode: 'exact' | 'fuzzy' | 'file_exact' | 'file_fuzzy' | 'file_browse' | 'mixed' | 'none' | string;
  confidence: number;
  suggestions: string[];
}

export interface LawSearchResult {
  law_id: string;
  title: string | null;
  title_highlighted?: string | null;
  law_type: string | null;
  status: string | null;
  change_status?: string | null;
  summary: string | null;
  published_date: string | null;
  agency: string | null;
  law_group?: string | null;
  signer_group?: string | null;
  restricted?: boolean;
  requires_permission?: boolean;
  child_types?: Record<string, number>;
  confidence?: number;
  match_mode?: string;
  snippets: string[];
  keywords?: string[];
  source?: 'internal' | 'external';
  issuer?: string | null;
}

export interface FacetBucket {
  value: string;
  count: number;
}

export interface YearBucket {
  year: number;
  count: number;
}

export interface LawStats {
  total_laws: number;
  new_laws: number;
  amended_laws: number;
  repealed_laws: number;
  parent_laws: number;
  child_laws: number;
}

export interface LawSearchFacets {
  law_type: FacetBucket[];
  status: FacetBucket[];
  change_status: FacetBucket[];
  agency: FacetBucket[];
  law_group: FacetBucket[];
  signer_group: FacetBucket[];
  years: YearBucket[];
  stats?: LawStats;
}

export interface LawSearchResponse {
  total: number;
  results: LawSearchResult[];
  facets: LawSearchFacets;
  meta?: LawSearchMeta;
}
