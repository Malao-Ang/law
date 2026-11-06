export type UnitType = 'faculty' | 'division' | 'department' | 'law_subunit';
export type UnitRole = 'inner_admin' | 'outer_admin' | 'editor' | 'user';
export type UnitAction = 'upload' | 'edit' | 'delete' | 'esign' | 'publish';
export interface Unit {
  code: string;
  name: string;
  type: UnitType;
  parent_code: string | null;
  ums_fac_id: string | null;
  ums_dep_id: string | null;
  source: string;
  missing_in_ums: boolean;
  is_active: boolean;
  sort_order: number;
}
export interface Membership {
  unit_code: string;
  role: UnitRole;
  actions: UnitAction[];
  source: string;
  unit_name?: string | null;
  unit?: Unit | null;
}
