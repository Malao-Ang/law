import type { Membership, Unit } from './unit';

export type AuthRole = 'super_admin' | 'inner_admin' | 'outer_admin' | 'editor' | 'user';

/** `user` in GET /api/auth/me (profile from LDAP + UMS). */
export interface AuthUser {
  username: string;
  name: string;
  email: string | null;
  position: string | null;
  fac_id: string | null;
  fac_name: string | null;
  dep_id: string | null;
  dep_name: string | null;
  ums_fac_id: string | null;
  ums_dep_id: string | null;
  retired: boolean;
}

/** Response of GET /api/auth/me (also returned by login and dev-login). */
export interface AuthMeResponse {
  user: AuthUser;
  role: AuthRole;
  is_super_admin: boolean;
  can_act_as_super_admin: boolean;
  acting_mode: 'super_admin' | 'unit' | 'user' | null;
  needs_role_selection: boolean;
  memberships: Membership[];
  active_unit: Unit | null;
  needs_unit_selection: boolean;
  can_upload: boolean;
  auth_enforced: boolean;
}
