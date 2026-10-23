<template>
  <div v-if="auth.isAuthenticated" class="d-flex align-center ga-2">
    <span v-if="auth.is_super_admin" class="text-body-2">ทุกหน่วยงาน</span>
    <v-menu v-else-if="auth.memberships.length > 1">
      <template #activator="{ props }">
        <v-btn v-bind="props" variant="text" append-icon="mdi-chevron-down" class="text-none" :loading="busy">
          {{ auth.active_unit?.name || 'เลือกหน่วยงาน' }}
        </v-btn>
      </template>
      <v-list aria-label="สลับหน่วยงาน">
        <v-list-item v-for="membership in auth.memberships" :key="membership.unit_code"
          :title="membership.unit_name || membership.unit_code" :subtitle="ROLE_LABELS[membership.role]"
          :active="membership.unit_code === auth.active_unit?.code"
          :disabled="busy || !membership.unit?.is_active" @click="switchUnit(membership.unit_code)" />
      </v-list>
    </v-menu>
    <span v-else class="text-body-2">{{ auth.active_unit?.name || 'ไม่มีหน่วยงานที่ใช้งาน' }}</span>
    <v-chip size="small" color="admin-primary" variant="tonal">{{ auth.roleLabel }}</v-chip>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore, ROLE_LABELS } from '../../stores/authStore';
import { alertError } from '../master/masterDialogs';
const auth = useAuthStore();
const router = useRouter();
const busy = ref(false);
async function switchUnit(code: string): Promise<void> {
  busy.value = true;
  try {
    await auth.selectUnit(code);
    await router.push('/');
  } catch {
    await alertError('ไม่สามารถสลับหน่วยงานได้ กรุณาลองอีกครั้ง');
  } finally { busy.value = false; }
}
</script>
