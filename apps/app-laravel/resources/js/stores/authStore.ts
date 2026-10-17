import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { ensureCsrfCookie, jsonRequest, setUnauthorizedHandler } from '../api/client';

const LEGACY_MOCK_KEY = 'elaw_mock_auth';

export type AuthRole = 'super_admin' | 'inner_admin' | 'outer_admin' | 'editor' | 'user';

export const ROLE_LABELS: Record<AuthRole, string> = {
  super_admin: 'ผู้ดูแลระบบสูงสุด',
  inner_admin: 'ผู้ดูแลหน่วยงาน',
  outer_admin: 'ผู้ดูแลภายนอก',
  editor: 'ผู้แก้ไข',
  user: 'ผู้ใช้งานทั่วไป',
};

export interface AuthUser {
  username: string;
  name: string;
  email: string | null;
  position: string | null;
  fac_id: string | null;
  fac_name: string | null;
  dep_id: string | null;
  dep_name: string | null;
  retired: boolean;
}

interface MePayload {
  user: AuthUser;
  role: AuthRole;
  auth_enforced: boolean;
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null);
  const role = ref<AuthRole | null>(null);
  const authEnforced = ref(false);
  const loaded = ref(false);
  let pendingFetch: Promise<void> | null = null;

  const isAuthenticated = computed(() => user.value !== null);
  const isAdminRole = computed(() => role.value !== null && role.value !== 'user');
  const roleLabel = computed(() => (role.value ? ROLE_LABELS[role.value] ?? role.value : ''));

  function apply(payload: MePayload): void {
    user.value = payload.user;
    role.value = payload.role;
    authEnforced.value = payload.auth_enforced;
  }

  function clear(): void {
    user.value = null;
    role.value = null;
  }

  async function fetchMe(force = false): Promise<void> {
    if (loaded.value && !force) return;
    pendingFetch ??= (async () => {
      try {
        localStorage.removeItem(LEGACY_MOCK_KEY);
        const response = await fetch('/api/auth/me', { credentials: 'include', headers: { Accept: 'application/json' } });
        const body = (await response.json().catch(() => ({}))) as Partial<MePayload>;
        if (response.ok && body.user && body.role) {
          apply(body as MePayload);
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
    apply(await jsonRequest<MePayload>('/api/auth/login', {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    }));
    loaded.value = true;
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
    if (!authEnforced.value || !user.value) return;
    clear();
    void import('../router').then(({ router }) => {
      const current = router.currentRoute.value;
      if (current.path !== '/login') {
        void router.push({ path: '/login', query: { redirect: current.fullPath } });
      }
    });
  });

  return { user, role, authEnforced, loaded, isAuthenticated, isAdminRole, roleLabel, fetchMe, login, logout };
});
