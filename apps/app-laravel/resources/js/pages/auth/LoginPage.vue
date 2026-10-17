<template>
  <div class="login-page">
    <ELawNavbar @go-admin="router.push('/admin')" />
    <v-main class="login-page__main">
      <section class="login-shell">
        <div class="login-visual" aria-hidden="true">
          <div class="login-visual__mark">
            <v-icon icon="mdi-lock-check-outline" size="54" />
          </div>
          <div class="login-visual__text">
            <span>Private Access</span>
            <strong>e-Law</strong>
          </div>
        </div>

        <v-card class="login-card" elevation="0">
          <div class="login-card__icon">
            <v-icon icon="mdi-account-key-outline" size="28" />
          </div>
          <h1 class="login-card__title">เข้าสู่ระบบ</h1>
          <p class="login-card__subtitle">
            ใช้บัญชีผู้ใช้มหาวิทยาลัย (BUU) เพื่อเข้าสู่ระบบ
          </p>

          <template v-if="auth.isAuthenticated && auth.user">
            <div class="login-status">
              <v-icon icon="mdi-check-circle-outline" size="18" />
              เข้าสู่ระบบแล้ว: {{ auth.user.name }}
            </div>

            <div class="login-profile" data-testid="login-profile">
              <p class="login-profile__title">ข้อมูลบุคลากร</p>
              <dl class="login-profile__list">
                <dt>รหัสคณะ/หน่วยงาน (fac_id)</dt>
                <dd>{{ auth.user.fac_id || '-' }}</dd>
                <dt>คณะ/หน่วยงาน</dt>
                <dd>{{ auth.user.fac_name || '-' }}</dd>
                <dt>รหัสฝ่าย/ภาควิชา (dep_id)</dt>
                <dd>{{ auth.user.dep_id || '-' }}</dd>
                <dt>ฝ่าย/ภาควิชา</dt>
                <dd>{{ auth.user.dep_name || '-' }}</dd>
                <dt>สิทธิ์ในระบบ</dt>
                <dd>{{ auth.roleLabel }}</dd>
              </dl>
            </div>

            <v-btn
              block
              color="primary"
              size="large"
              rounded="lg"
              variant="flat"
              prepend-icon="mdi-view-dashboard-outline"
              @click="router.push(redirectPath)"
            >
              ไปยังระบบ
            </v-btn>
            <v-btn
              block
              class="mt-3"
              color="grey-darken-2"
              rounded="lg"
              variant="outlined"
              prepend-icon="mdi-logout"
              @click="auth.logout()"
            >
              ออกจากระบบ
            </v-btn>
          </template>

          <v-form v-else class="login-form" @submit.prevent="submit">
            <v-alert
              v-if="errorMessage"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-4"
              data-testid="login-error"
            >
              {{ errorMessage }}
            </v-alert>

            <v-text-field
              v-model="username"
              label="ชื่อผู้ใช้"
              prepend-inner-icon="mdi-account-outline"
              autocomplete="username"
              autocapitalize="none"
              spellcheck="false"
              variant="outlined"
              density="comfortable"
              :disabled="loading"
              hide-details="auto"
              class="mb-3"
              autofocus
              data-testid="login-username"
            />
            <v-text-field
              v-model="password"
              label="รหัสผ่าน"
              prepend-inner-icon="mdi-lock-outline"
              :type="showPassword ? 'text' : 'password'"
              :append-inner-icon="showPassword ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
              autocomplete="current-password"
              variant="outlined"
              density="comfortable"
              :disabled="loading"
              hide-details="auto"
              class="mb-4"
              data-testid="login-password"
              @click:append-inner="showPassword = !showPassword"
            />

            <v-btn
              block
              type="submit"
              color="primary"
              size="large"
              rounded="lg"
              variant="flat"
              prepend-icon="mdi-login"
              :loading="loading"
              :disabled="!canSubmit"
              data-testid="login-submit"
            >
              เข้าสู่ระบบ
            </v-btn>
          </v-form>

          <v-btn
            block
            class="mt-3"
            color="grey-darken-2"
            rounded="lg"
            variant="text"
            @click="router.push('/database')"
          >
            กลับฐานข้อมูลกฎหมาย
          </v-btn>
        </v-card>
      </section>
    </v-main>
    <ELawFooter />
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiRequestError } from '../../api/client';
import ELawFooter from '../../components/shared/ELawFooter.vue';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import { useAuthStore } from '../../stores/authStore';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const username = ref('');
const password = ref('');
const showPassword = ref(false);
const loading = ref(false);
const errorMessage = ref('');

const redirectPath = computed(() => {
  const value = route.query.redirect;
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : '/admin';
});

const canSubmit = computed(() => username.value.trim() !== '' && password.value !== '');

function messageFor(error: ApiRequestError): string {
  switch (error.status) {
    case 401:
      return 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    case 403:
      return 'บัญชีนี้ถูกปิดการใช้งาน';
    case 429:
      return 'พยายามเข้าสู่ระบบบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่';
    case 503:
      return 'ระบบยืนยันตัวตนของมหาวิทยาลัยไม่พร้อมใช้งาน';
    default:
      return error.message || 'เข้าสู่ระบบไม่สำเร็จ กรุณาลองใหม่';
  }
}

async function submit(): Promise<void> {
  if (!canSubmit.value || loading.value) return;
  loading.value = true;
  errorMessage.value = '';
  try {
    await auth.login(username.value.trim(), password.value);
    password.value = '';
    await router.push(redirectPath.value);
  } catch (error) {
    errorMessage.value = messageFor(error as ApiRequestError);
  } finally {
    loading.value = false;
  }
}
</script>

<style scoped>
.login-page {
  background: #f7f4ef;
  min-height: 100vh;
}

.login-page__main {
  min-height: calc(100vh - 96px);
}

.login-shell {
  align-items: stretch;
  display: grid;
  gap: 0;
  grid-template-columns: minmax(0, 1fr) 420px;
  margin: 0 auto;
  max-width: 1040px;
  padding: 72px 24px;
}

.login-visual {
  background: linear-gradient(135deg, #26364f 0%, #7b5a22 100%);
  border-radius: 18px 0 0 18px;
  color: #ffffff;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 430px;
  padding: 42px;
}

.login-visual__mark {
  align-items: center;
  background: rgba(255, 255, 255, 0.14);
  border: 1px solid rgba(255, 255, 255, 0.22);
  border-radius: 18px;
  display: inline-flex;
  height: 92px;
  justify-content: center;
  width: 92px;
}

.login-visual__text {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.login-visual__text span {
  color: rgba(255, 255, 255, 0.78);
  font-size: 18px;
}

.login-visual__text strong {
  font-size: 44px;
  line-height: 1;
}

.login-card {
  border: 1px solid #eadfce;
  border-left: 0;
  border-radius: 0 18px 18px 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 42px;
}

.login-card__icon {
  align-items: center;
  background: #f2e7d4;
  border-radius: 14px;
  color: #8a640f;
  display: inline-flex;
  height: 54px;
  justify-content: center;
  margin-bottom: 22px;
  width: 54px;
}

.login-card__title {
  color: #1f2937;
  font-size: 30px;
  font-weight: 800;
  line-height: 1.2;
  margin: 0 0 10px;
}

.login-card__subtitle {
  color: #64748b;
  font-size: 15px;
  line-height: 1.7;
  margin: 0 0 24px;
}

.login-status {
  align-items: center;
  background: #ecfdf5;
  border: 1px solid #bbf7d0;
  border-radius: 10px;
  color: #047857;
  display: flex;
  font-size: 14px;
  gap: 8px;
  margin-bottom: 18px;
  padding: 10px 12px;
}

.login-profile {
  background: #faf6ee;
  border: 1px solid #eadfce;
  border-radius: 12px;
  margin-bottom: 18px;
  padding: 14px 16px;
}

.login-profile__title {
  color: #1f2937;
  font-size: 15px;
  font-weight: 700;
  margin: 0 0 8px;
}

.login-profile__list {
  display: grid;
  font-size: 13px;
  gap: 4px 12px;
  grid-template-columns: auto 1fr;
  margin: 0;
}

.login-profile__list dt {
  color: #64748b;
}

.login-profile__list dd {
  color: #1f2937;
  font-weight: 600;
  margin: 0;
  text-align: right;
}

@media (max-width: 820px) {
  .login-shell {
    grid-template-columns: 1fr;
    padding: 32px 16px;
  }

  .login-visual {
    border-radius: 18px 18px 0 0;
    min-height: 220px;
  }

  .login-card {
    border-left: 1px solid #eadfce;
    border-radius: 0 0 18px 18px;
    padding: 28px;
  }
}
</style>
