import { defineStore } from 'pinia';
import { clearReviewCache } from './reviewCache';
import { computed, ref } from 'vue';
import { ensureCsrfCookie, jsonRequest, setSessionActivityHandler, setUnauthorizedHandler } from '../api/client';

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
  const reauthRequired = ref(false);
  const sessionWarning = ref(false);
  const sessionExpiresAt = ref(0);
  const sessionIdleUntil = ref(0);
  let idleDuration = 0;
  let lastInteraction = Date.now();
  let lastHeartbeat = Date.now();
  let pendingReauth: Promise<boolean> | null = null;
  let loginRedirectPending: Promise<boolean> | null = null;
  let finishReauth: ((resumed: boolean) => void) | null = null;
  let reauthenticating = false;
  let monitorStarted = false;
  let warningDismissedFor = 0;
  const tabId = Math.random().toString(36).slice(2);
  const SESSION_EVENT = 'lawspace.session-event';

  function broadcast(kind: 'refresh' | 'logout'): void {
    try {
      localStorage.setItem(SESSION_EVENT, JSON.stringify({ kind, tabId, at: Date.now(), idleUntil: sessionIdleUntil.value, expiresAt: sessionExpiresAt.value }));
    } catch { /* Storage may be unavailable; this tab still functions. */ }
  }

  function updateDeadline(payload: AuthMeResponse): void {
    sessionExpiresAt.value = payload.session_expires_at;
    sessionIdleUntil.value = payload.session_idle_expires_at;
    idleDuration = Math.max(0, payload.session_idle_expires_at - Date.now());
    lastHeartbeat = Date.now();
    sessionWarning.value = false;
    broadcast('refresh');
  }

  function serverActivity(): void {
    if (!user.value || reauthRequired.value || !idleDuration) return;
    sessionIdleUntil.value = Date.now() + idleDuration;
    lastHeartbeat = Date.now();
    broadcast('refresh');
  }

  setSessionActivityHandler(serverActivity);

  const isAuthenticated = computed(() => user.value !== null);
  const isAdminRole = computed(() => role.value !== null && role.value !== 'user');
  const roleLabel = computed(() => (role.value ? ROLE_LABELS[role.value] ?? role.value : ''));

  function apply(payload: AuthMeResponse, preserveView = false): void {
    if (!preserveView && (user.value?.username !== payload.user?.username || active_unit.value?.code !== payload.active_unit?.code
      || role.value !== payload.role || acting_mode.value !== (payload.acting_mode ?? null)
      || is_super_admin.value !== Boolean(payload.is_super_admin) || JSON.stringify(memberships.value) !== JSON.stringify(payload.memberships ?? []))) { clearReviewCache(); scopeRevision.value++; }
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
    updateDeadline(payload);
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
    sessionExpiresAt.value = 0;
    sessionIdleUntil.value = 0;
    sessionWarning.value = false;
    reauthRequired.value = false;
    finishReauth?.(false);
    finishReauth = null;
    pendingReauth = null;
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
          if (user.value) {
            reauthRequired.value = true;
            sessionWarning.value = false;
          } else {
            clear();
          }
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

  function markInteraction(): void { lastInteraction = Date.now(); }

  function startSessionMonitoring(): void {
    if (monitorStarted) return;
    monitorStarted = true;
    for (const event of ['pointermove', 'keydown', 'scroll', 'click']) window.addEventListener(event, markInteraction, { passive: true });
    window.addEventListener('storage', (event) => {
      if (event.key !== SESSION_EVENT || !event.newValue) return;
      try {
        const data = JSON.parse(event.newValue) as { kind: string; tabId: string; idleUntil: number; expiresAt: number };
        if (data.tabId === tabId) return;
        if (data.kind === 'logout') {
          clear();
          void import('../router').then(({ router }) => router.replace('/'));
        } else if (data.kind === 'refresh' && user.value) {
          sessionIdleUntil.value = Math.max(sessionIdleUntil.value, data.idleUntil);
          sessionExpiresAt.value = Math.min(sessionExpiresAt.value, data.expiresAt);
        }
      } catch { /* Ignore unrelated storage values. */ }
    });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible' && user.value && !reauthRequired.value) void fetchMe(true);
    });
    window.setInterval(() => {
      if (!user.value || reauthRequired.value) return;
      const now = Date.now();
      const until = Math.min(sessionExpiresAt.value, sessionIdleUntil.value);
      if (until && until <= now) { reauthRequired.value = true; sessionWarning.value = false; return; }
      if (document.visibilityState === 'visible' && now - lastInteraction < 10 * 60_000 && now - lastHeartbeat >= 10 * 60_000) {
        lastHeartbeat = now;
        void fetchMe(true);
      }
      sessionWarning.value = Boolean(until && until - now <= 5 * 60_000 && warningDismissedFor !== until);
    }, 30_000);
  }

  function acknowledgeWarning(): void {
    warningDismissedFor = Math.min(sessionExpiresAt.value, sessionIdleUntil.value);
    sessionWarning.value = false;
  }

  async function continueSession(): Promise<void> {
    await fetchMe(true);
  }

  async function resumeSession(username: string, password: string): Promise<void> {
    if (!user.value || reauthenticating) return;
    const original = user.value.username;
    const originalRole = role.value;
    const originalMode = acting_mode.value;
    const originalUnit = active_unit.value?.code;
    reauthenticating = true;
    try {
      await ensureCsrfCookie(true);
      const loginResult = await jsonRequest<AuthMeResponse>('/api/auth/login', { method: 'POST', body: JSON.stringify({ username, password }) });
      if (loginResult.user.username !== original) {
        await jsonRequest<void>('/api/auth/logout', { method: 'POST' });
        throw new Error('กรุณาเข้าสู่ระบบด้วยบัญชีเดิมเพื่อเก็บงานที่ค้างไว้');
      }
      const body = originalUnit ? { unit_code: originalUnit } : originalMode === 'user' ? { mode: 'user' } : originalMode === 'super_admin' ? { mode: 'super_admin' } : null;
      if (body) await jsonRequest('/api/auth/active-unit', { method: 'POST', body: JSON.stringify(body) });
      const response = await fetch('/api/auth/me', { credentials: 'include', headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('ไม่สามารถตรวจสอบสิทธิ์หลังเข้าสู่ระบบได้');
      const restored = await response.json() as AuthMeResponse;
      if (restored.user.username !== original || restored.role !== originalRole || restored.acting_mode !== originalMode || restored.active_unit?.code !== originalUnit) {
        throw new Error('บทบาทหรือหน่วยงานเปลี่ยนไป กรุณาออกจากหน้านี้และเข้าสู่ระบบใหม่');
      }
      apply(restored, true);
      reauthRequired.value = false;
      lastInteraction = Date.now();
      finishReauth?.(true);
      finishReauth = null;
      pendingReauth = null;
    } finally {
      reauthenticating = false;
    }
  }

  async function logout(redirectTo = '/'): Promise<void> {
    try {
      await jsonRequest<void>('/api/auth/logout', { method: 'POST' });
    } catch {
      // session may already be gone
    }
    clear();
    broadcast('logout');
    window.location.assign(redirectTo);
  }

  function redirectToLoginOnce(): Promise<boolean> {
    loginRedirectPending ??= (async () => {
      try {
        const { default: Swal } = await import('sweetalert2');
        await Swal.fire({ icon: 'error', titleText: 'เซสชันหมดอายุ', text: 'กรุณาเข้าสู่ระบบใหม่',
          confirmButtonText: 'ตกลง', confirmButtonColor: '#475467', allowOutsideClick: false, allowEscapeKey: false });
      } finally {
        window.location.assign('/login');
      }
      // Keep concurrent 401 callers suspended until the old page unloads.
      return new Promise<boolean>(() => {});
    })();
    return loginRedirectPending;
  }

  setUnauthorizedHandler(() => {
    if (reauthenticating) return Promise.resolve(false);
    if (!user.value) return redirectToLoginOnce();
    reauthRequired.value = true;
    sessionWarning.value = false;
    pendingReauth ??= new Promise<boolean>((resolve) => { finishReauth = resolve; });
    return pendingReauth;
  });

  return { selectUser, scopeRevision, can_act_as_super_admin, acting_mode, selectSuperAdmin, canViewAudit, canUpload, is_super_admin, memberships, active_unit, needs_unit_selection, canManageUnits, selectUnit, user, role, authEnforced, loaded, isAuthenticated, isAdminRole, roleLabel, fetchMe, login, devLogin, logout, sessionWarning, reauthRequired, sessionExpiresAt, sessionIdleUntil, startSessionMonitoring, acknowledgeWarning, continueSession, resumeSession };
});
