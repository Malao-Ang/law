import { defineStore } from 'pinia';
import { clearReviewCache } from './reviewCache';
import { computed, ref } from 'vue';
import { ensureCsrfCookie, jsonRequest, setUnauthorizedHandler } from '../api/client';

import type { Membership, Unit } from '../types/unit';
import type { AuthMeResponse, AuthRole, AuthUser } from '../types/auth';

export type { AuthMeResponse, AuthRole, AuthUser };

const LEGACY_MOCK_KEY = 'elaw_mock_auth';

export const ROLE_LABELS: Record<AuthRole, string> = {
  super_admin: 'Super Admin',
  inner_admin: 'Admin',
  outer_admin: 'Outer Admin',
  editor: 'Editor',
  user: 'User',
};

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null);
  const role = ref<AuthRole | null>(null);
  const authEnforced = ref(false);
  const loaded = ref(false);
  const canUpload = ref(false);
  const is_super_admin = ref(false);
  const can_act_as_super_admin = ref(false);
  const acting_mode = ref<AuthMeResponse['acting_mode']>(null);
  const memberships = ref<Membership[]>([]);
  const active_unit = ref<Unit | null>(null);
  const needs_unit_selection = ref(false);
  const canViewAudit = computed(() => is_super_admin.value || role.value === 'inner_admin');
  const scopeRevision = ref(0);
  const canManageUnits = computed(() => is_super_admin.value || ['inner_admin', 'outer_admin'].includes(role.value ?? ''));
  let pendingFetch: Promise<void> | null = null;

  const isAuthenticated = computed(() => user.value !== null);
  const isAdminRole = computed(() => role.value !== null && role.value !== 'user');
  const roleLabel = computed(() => (role.value ? ROLE_LABELS[role.value] ?? role.value : ''));

  function apply(payload: AuthMeResponse): void {
    if (user.value?.username !== payload.user?.username || active_unit.value?.code !== payload.active_unit?.code
      || role.value !== payload.role || acting_mode.value !== (payload.acting_mode ?? null)
      || is_super_admin.value !== Boolean(payload.is_super_admin) || JSON.stringify(memberships.value) !== JSON.stringify(payload.memberships ?? [])) { clearReviewCache(); scopeRevision.value++; }
    user.value = payload.user;
    role.value = payload.role;
    authEnforced.value = payload.auth_enforced;
    canUpload.value = payload.can_upload;
    is_super_admin.value = Boolean(payload.is_super_admin);
    can_act_as_super_admin.value = Boolean(payload.can_act_as_super_admin);
    acting_mode.value = payload.acting_mode ?? null;
    memberships.value = payload.memberships ?? [];
    active_unit.value = payload.active_unit ?? null;
    needs_unit_selection.value = Boolean(payload.needs_unit_selection);
  }

  function clear(): void {
    scopeRevision.value++;
    clearReviewCache();
    user.value = null;
    canUpload.value = false;
    role.value = null;
    is_super_admin.value = false;
    can_act_as_super_admin.value = false;
    acting_mode.value = null;
    memberships.value = [];
    active_unit.value = null;
    needs_unit_selection.value = false;
  }

  async function fetchMe(force = false): Promise<void> {
    if (loaded.value && !force) return;
    pendingFetch ??= (async () => {
      try {
        localStorage.removeItem(LEGACY_MOCK_KEY);
        const response = await fetch('/api/auth/me', { credentials: 'include', headers: { Accept: 'application/json' } });
        const body = (await response.json().catch(() => ({}))) as Partial<AuthMeResponse>;
        if (response.ok && body.user && body.role) {
          apply(body as AuthMeResponse);
        } else if (response.status === 401) {
          clear();
          authEnforced.value = Boolean(body.auth_enforced);
        }
      } catch {
        // network error: leave state as is; guards treat unknown state as not enforced
      } finally {
        loaded.value = true;
        pendingFetch = null;
      }
    })();
    await pendingFetch;
  }

  async function login(username: string, password: string): Promise<void> {
    await ensureCsrfCookie();
    apply(await jsonRequest<AuthMeResponse>('/api/auth/login', {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    }));
    loaded.value = true;
  }

  async function devLogin(username: string): Promise<void> {
    await ensureCsrfCookie();
    apply(await jsonRequest<AuthMeResponse>('/api/auth/dev-login', {
      method: 'POST',
      body: JSON.stringify({ username }),
    }));
    loaded.value = true;
  }

  async function selectUnit(unit_code: string): Promise<void> {
    await jsonRequest('/api/auth/active-unit', { method: 'POST', body: JSON.stringify({ unit_code }) });
    await fetchMe(true);
    if (needs_unit_selection.value || active_unit.value?.code !== unit_code) {
      throw new Error('ไม่สามารถเลือกหน่วยงานได้ กรุณาลองอีกครั้ง');
    }
  }

  async function selectSuperAdmin(): Promise<void> {
    await jsonRequest('/api/auth/active-unit', { method: 'POST', body: JSON.stringify({ mode: 'super_admin' }) });
    await fetchMe(true);
    if (!is_super_admin.value || acting_mode.value !== 'super_admin') {
      throw new Error('ไม่สามารถเลือกบทบาทได้ กรุณาลองอีกครั้ง');
    }
  }

  async function selectUser(): Promise<void> {
    await jsonRequest('/api/auth/active-unit', { method: 'POST', body: JSON.stringify({ mode: 'user' }) });
    await fetchMe(true);
    if (acting_mode.value !== 'user' || is_super_admin.value || active_unit.value !== null || role.value !== 'user') {
      throw new Error('ไม่สามารถเลือกบทบาท User ได้ กรุณาลองอีกครั้ง');
    }
  }

  async function logout(redirectTo = '/login'): Promise<void> {
    try {
      await jsonRequest<void>('/api/auth/logout', { method: 'POST' });
    } catch {
      // session may already be gone
    }
    clear();
    const { router } = await import('../router');
    await router.push(redirectTo);
  }

  setUnauthorizedHandler(() => {
    clear();
    void import('../router').then(({ router }) => {
      const current = router.currentRoute.value;
      if (current.path !== '/login') {
        void router.push({ path: '/login', query: { redirect: current.fullPath } });
      }
    });
  });

  return { selectUser, scopeRevision, can_act_as_super_admin, acting_mode, selectSuperAdmin, canViewAudit, canUpload, is_super_admin, memberships, active_unit, needs_unit_selection, canManageUnits, selectUnit, user, role, authEnforced, loaded, isAuthenticated, isAdminRole, roleLabel, fetchMe, login, devLogin, logout };
});
