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
}

export interface AuditLogOption {
  value: string;
  label: string;
}

export interface AuditLogResponse {
  items: AuditLogItem[];
  actions: AuditLogOption[];
  resources: AuditLogOption[];
}
