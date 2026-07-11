export interface PermissionDirectoryUnit {
  id: string;
  name: string;
}

export interface PermissionDirectoryPosition {
  id: string;
  name: string;
}

export interface PermissionDirectoryUser {
  id: string;
  name: string;
  email?: string | null;
}

export interface PermissionDirectoryResponse {
  units: PermissionDirectoryUnit[];
  positions: PermissionDirectoryPosition[];
  users: PermissionDirectoryUser[];
}

export interface PermissionGroupCounts {
  units: number;
  positions: number;
  users: number;
  total: number;
}

export interface PermissionGroup {
  id: string;
  name: string;
  description?: string | null;
  unit_ids: string[];
  position_ids: string[];
  user_ids: string[];
  units: PermissionDirectoryUnit[];
  positions: PermissionDirectoryPosition[];
  users: PermissionDirectoryUser[];
  counts: PermissionGroupCounts;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface UpsertPermissionGroupPayload {
  name: string;
  description?: string | null;
  unit_ids: string[];
  position_ids: string[];
  user_ids: string[];
}
