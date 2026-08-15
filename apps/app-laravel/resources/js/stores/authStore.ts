import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

const AUTH_KEY = 'elaw_mock_auth';

interface MockUser {
  name: string;
  role: string;
}

function readUser(): MockUser | null {
  try {
    const raw = localStorage.getItem(AUTH_KEY);
    return raw ? JSON.parse(raw) as MockUser : null;
  } catch {
    return null;
  }
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<MockUser | null>(readUser());
  const isAuthenticated = computed(() => user.value !== null);

  function login(): void {
    user.value = {
      name: 'ผู้ใช้ทดสอบ',
      role: 'Mock user',
    };
    localStorage.setItem(AUTH_KEY, JSON.stringify(user.value));
  }

  function logout(): void {
    user.value = null;
    localStorage.removeItem(AUTH_KEY);
  }

  return { user, isAuthenticated, login, logout };
});
