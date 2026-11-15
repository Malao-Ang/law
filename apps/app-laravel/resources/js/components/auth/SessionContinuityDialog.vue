<template>
  <v-dialog :model-value="auth.sessionWarning || auth.reauthRequired" persistent max-width="460" :retain-focus="false">
    <v-card rounded="lg">
      <v-card-title class="pt-5 px-6">{{ auth.reauthRequired ? 'เซสชันหมดอายุ' : 'เซสชันใกล้หมดอายุ' }}</v-card-title>
      <v-card-text class="px-6">
        <template v-if="auth.reauthRequired">
          <p class="mb-4">งานบนหน้านี้ยังอยู่ กรุณาเข้าสู่ระบบด้วยบัญชีเดิมเพื่อทำต่อ</p>
          <v-alert v-if="errorMessage" type="error" variant="tonal" class="mb-4">{{ errorMessage }}</v-alert>
          <v-form @submit.prevent="submit">
            <v-text-field :model-value="auth.user?.username" label="ชื่อผู้ใช้" variant="outlined" readonly autocomplete="username" />
            <v-text-field v-model="password" label="รหัสผ่าน" type="password" variant="outlined" autocomplete="current-password" :disabled="loading" autofocus />
            <div class="d-flex justify-end ga-2 mt-2">
              <v-btn variant="text" :disabled="loading" @click="auth.logout('/login')">ออกจากหน้านี้</v-btn>
              <v-btn type="submit" color="primary" :loading="loading" :disabled="!password">เข้าสู่ระบบอีกครั้ง</v-btn>
            </div>
          </v-form>
        </template>
        <template v-else>
          <p class="mb-4">{{ absoluteLimit ? 'กำลังครบกำหนด 12 ชั่วโมง กรุณาบันทึกงานก่อนเข้าสู่ระบบใหม่' : 'หากยังใช้งานอยู่ กดใช้งานต่อเพื่อต่ออายุเซสชัน' }}</p>
          <div class="d-flex justify-end">
            <v-btn color="primary" :loading="loading" @click="continueWorking">{{ absoluteLimit ? 'รับทราบ' : 'ใช้งานต่อ' }}</v-btn>
          </div>
        </template>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useAuthStore } from '../../stores/authStore';

const auth = useAuthStore();
const password = ref('');
const loading = ref(false);
const errorMessage = ref('');
const absoluteLimit = computed(() => auth.sessionExpiresAt <= auth.sessionIdleUntil);

async function continueWorking(): Promise<void> {
  if (absoluteLimit.value) { auth.acknowledgeWarning(); return; }
  loading.value = true;
  try { await auth.continueSession(); }
  finally { loading.value = false; }
}

async function submit(): Promise<void> {
  if (!password.value || loading.value || !auth.user) return;
  loading.value = true;
  errorMessage.value = '';
  try {
    await auth.resumeSession(auth.user.username, password.value);
    password.value = '';
  } catch (error) {
    errorMessage.value = error instanceof Error && /[ก-๙]/.test(error.message) ? error.message : 'เข้าสู่ระบบไม่สำเร็จ กรุณาตรวจสอบรหัสผ่านแล้วลองใหม่';
  } finally {
    loading.value = false;
  }
}
</script>
