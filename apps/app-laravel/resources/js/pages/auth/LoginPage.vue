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
            ใช้บัญชีจำลองเพื่อดูเอกสารที่กำหนดสิทธิ์เป็น Private
          </p>

          <div v-if="auth.isAuthenticated" class="login-status">
            <v-icon icon="mdi-check-circle-outline" size="18" />
            เข้าสู่ระบบแล้ว: {{ auth.user?.name }}
          </div>

          <v-btn
            block
            color="primary"
            size="large"
            rounded="lg"
            variant="flat"
            prepend-icon="mdi-login"
            @click="mockLogin"
          >
            เข้าสู่ระบบแบบจำลอง
          </v-btn>

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
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ELawFooter from '../../components/shared/ELawFooter.vue';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import { useAuthStore } from '../../stores/authStore';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const redirectPath = computed(() => {
  const value = route.query.redirect;
  return typeof value === 'string' && value.startsWith('/') ? value : '/database';
});

function mockLogin(): void {
  auth.login();
  router.push(redirectPath.value);
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
