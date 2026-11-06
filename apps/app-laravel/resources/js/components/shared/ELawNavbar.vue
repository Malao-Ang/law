<template>
  <v-app-bar class="elaw-header" height="96" flat>
    <v-container class="elaw-header__inner" fluid>
      <!-- Logo -->
      <v-btn class="elaw-logo text-none" variant="text" :active="false" :ripple="false" to="/">
        <v-avatar class="elaw-logo__icon" rounded="lg" size="40">
          <v-icon icon="mdi-scale-balance" color="white" size="20" />
        </v-avatar>
        <span class="elaw-logo__text">
          <span class="elaw-logo__law">e-Law</span>
        </span>
      </v-btn>

      <!-- Nav -->
      <div class="elaw-header__nav">
        <MainNav />
      </div>

      <!-- Right buttons -->
      <div class="elaw-header__actions">
        <v-menu v-if="auth.isAuthenticated" location="bottom end" :close-on-content-click="false">
          <template #activator="{ props }">
            <v-btn v-bind="props" class="elaw-btn-login elaw-user-button text-none" variant="text" rounded="pill" append-icon="mdi-chevron-down" aria-label="เมนูผู้ใช้">
              <v-avatar size="28" color="#94713b" class="mr-2 text-white">{{ initial }}</v-avatar>
              <span class="elaw-user-name">{{ auth.user?.name || auth.user?.username }}</span>
            </v-btn>
          </template>
          <v-card width="320" rounded="lg" class="pa-5 text-center">
            <v-avatar size="64" color="#94713b" class="mb-3 text-white text-h4">{{ initial }}</v-avatar>
            <div class="text-subtitle-1 font-weight-bold">{{ auth.user?.name }}</div>
            <div class="text-body-2 text-medium-emphasis">{{ auth.user?.username }}</div>
            <div class="d-flex justify-center mt-3">
              <ActiveUnitMenu />
            </div>
            <v-divider class="my-4" />
            <v-btn block variant="tonal" prepend-icon="mdi-logout" @click="logout">ออกจากระบบ</v-btn>
          </v-card>
        </v-menu>
        <v-btn v-else class="elaw-btn-login text-none" prepend-icon="mdi-login" variant="text" rounded="pill" @click="router.push('/login')">เข้าสู่ระบบ</v-btn>
        <v-btn
          v-if="auth.isAuthenticated"
          class="elaw-btn-staff text-none"
          color="#343028"
          prepend-icon="mdi-shield-account-outline"
          rounded="pill"
          variant="flat"
          @click="emit('go-admin')"
        >
          สำหรับบุคลากรองค์กร
        </v-btn>
      </div>
    </v-container>
  </v-app-bar>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import ActiveUnitMenu from './ActiveUnitMenu.vue';
import { useAuthStore } from '../../stores/authStore';
import MainNav from './MainNav.vue';

const emit = defineEmits<{ 'go-admin': [] }>();
const router = useRouter();
const auth = useAuthStore();
const initial = computed(() => Array.from(auth.user?.name || auth.user?.username || '?')[0]);

function logout(): void {
  void auth.logout('/');
}
</script>

<style scoped>
.elaw-header {
  --elaw-navbar-height: 96px;
  position: sticky;
  top: 0;
  z-index: 100;
  width: 100%;
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: blur(6px);
  border-bottom: 1px solid rgba(210, 197, 179, 0.5);
  box-shadow: 0 1px 1px rgba(0, 0, 0, 0.05);
}

.elaw-header__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: var(--elaw-navbar-height);
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
  padding: 0 28px;
  gap: 24px;
  box-sizing: border-box;
}

/* Logo */
.elaw-logo {
  display: flex;
  align-items: center;
  flex-shrink: 0;
  padding: 0;
  min-width: 0;
  color: inherit;
}

.elaw-logo :deep(.v-btn__content) {
  display: flex;
  align-items: center;
  gap: 12px;
}

.elaw-logo :deep(.v-btn__overlay) {
  display: none;
}

.elaw-logo__icon {
  background: #b68d40;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.elaw-logo__text {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 20px;
  line-height: 1;
  white-space: nowrap;
}

.elaw-logo__e {
  color: #4e4538;
  font-weight: 400;
}

.elaw-logo__law {
  color: #7b580d;
  font-weight: 700;
}

/* Nav center */
.elaw-header__nav {
  flex: 1;
  display: flex;
  justify-content: center;
}

/* Action buttons */
.elaw-header__actions {
  display: flex;
  align-items: center;
  gap: 24px;
  flex-shrink: 0;
}

.elaw-user-name { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.elaw-user-button { min-width: 0; }
@media (min-width: 769px) and (max-width: 1400px) {
  .elaw-header__inner { gap: 12px; padding-inline: 20px; }
  .elaw-header__actions { gap: 8px; }
  .elaw-btn-staff { padding-inline: 16px; }
  .elaw-user-name { max-width: 120px; }
}

.elaw-btn-login {
  gap: 5px;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 16px;
  font-weight: 400;
  color: #4e4538;
  letter-spacing: 0.36px;
  white-space: nowrap;
}

.elaw-btn-staff {
  gap: 8px;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 16px;
  font-weight: 400;
  color: #f9efe3;
  letter-spacing: 0.36px;
  white-space: nowrap;
  padding: 12px 24px;
  box-shadow: 0 1px 1px rgba(0, 0, 0, 0.05);
}

@media (max-width: 768px) {
  .elaw-header {
    height: auto !important;
  }

  .elaw-header__inner {
    flex-wrap: wrap;
    height: auto;
    min-height: var(--elaw-navbar-height);
    padding: 12px 16px;
    gap: 12px;
  }
  .elaw-btn-login,
  .elaw-btn-staff {
    font-size: 16px;
    padding: 8px 14px;
  }
}
</style>
