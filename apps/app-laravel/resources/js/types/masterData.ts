export type MasterKind = 'enforcement_status';

export type MasterItemAttrs = Record<string, unknown> & {
  color?: 'success' | 'error' | 'grey' | 'info' | 'warning' | null;
};

export interface MasterItem {
  code: string;
  name: string;
  description: string;
  is_active: boolean;
  is_system: boolean;
  sort_order: number;
  aliases: string[];
  attrs: MasterItemAttrs;
  usage_count?: number;
  created_at: string;
  updated_at: string;
}

export interface MasterStats {
  total: number;
  active: number;
  inactive: number;
}

export interface MasterListResponse {
  items: MasterItem[];
  total: number;
  stats: MasterStats;
  next_code: string;
}

export type MasterActiveFilter = 'all' | '1' | '0';

export interface MasterListParams {
  q?: string;
  active?: MasterActiveFilter;
  page?: number;
  per_page?: number;
}

export interface UpsertMasterItemPayload {
  name: string;
  description?: string | null;
  is_active?: boolean;
  sort_order?: number | null;
  attrs?: MasterItemAttrs;
}
