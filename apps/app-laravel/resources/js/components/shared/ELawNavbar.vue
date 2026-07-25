<template>
  <header class="elaw-header">
    <div class="elaw-header__inner">
      <!-- Logo -->
      <div class="elaw-logo">
        <div class="elaw-logo__icon">
          <v-icon icon="mdi-scale-balance" color="white" size="20" />
        </div>
        <span class="elaw-logo__text">
          <span class="elaw-logo__e">e-</span><span class="elaw-logo__law">Law</span>
        </span>
      </div>

      <!-- Nav -->
      <div class="elaw-header__nav">
        <MainNav />
      </div>

      <!-- Right buttons -->
      <div class="elaw-header__actions">
        <button type="button" class="elaw-btn-login" @click="router.push('/login')">
          <v-icon icon="mdi-login" size="17" />
          เข้าสู่ระบบ
        </button>
        <button type="button" class="elaw-btn-staff" @click="$emit('go-admin')">
          <v-icon icon="mdi-shield-account-outline" size="15" color="white" />
          สำหรับบุคลากรองค์กร
        </button>
      </div>
    </div>
  </header>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router';
import MainNav from './MainNav.vue';

defineEmits<{ 'go-admin': [] }>();
const router = useRouter();
</script>

<style scoped>
.elaw-header {
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
  padding: 14px 28px;
  min-height: 96px;
  gap: 24px;
}

/* Logo */
.elaw-logo {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}

.elaw-logo__icon {
  width: 40px;
  height: 40px;
  background: #b68d40;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.elaw-logo__text {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 32px;
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

.elaw-btn-login {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: none;
  border: none;
  cursor: pointer;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 20px;
  font-weight: 400;
  color: #4e4538;
  letter-spacing: 0.36px;
  white-space: nowrap;
  padding: 4px 8px;
}

.elaw-btn-staff {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: #343028;
  border: none;
  cursor: pointer;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 20px;
  font-weight: 400;
  color: #f9efe3;
  letter-spacing: 0.36px;
  white-space: nowrap;
  padding: 12px 24px;
  border-radius: 9999px;
  box-shadow: 0 1px 1px rgba(0, 0, 0, 0.05);
}

@media (max-width: 768px) {
  .elaw-header__inner {
    flex-wrap: wrap;
    min-height: auto;
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
