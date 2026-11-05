export interface AuditLogItem {
  id: string;
  at: string;
  action: 'create' | 'update' | 'delete' | string;
  action_label: string;
  resource: string;
  resource_label: string;
  resource_id: string;
  document_id: string | null;
  label: string;
  summary: string;
  changes: string[];
  actor_name: string;
  actor_role: string;
  ip: string;
  actor_username: string;
  unit_code: string | null;
  active_unit_code: string | null;
  result: 'success' | 'denied' | 'failed';
  before: Record<string, unknown>;
  after: Record<string, unknown>;
  on_behalf_of: string | null;
}

export interface AuditLogOption {
  value: string;
  label: string;
}

export interface AuditLogResponse {
  items: AuditLogItem[];
  total: number;
  page: number;
  per_page: number;
  last_page: number;
  units: { code: string; name: string }[];
  actions: AuditLogOption[];
  resources: AuditLogOption[];
}
