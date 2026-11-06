<template>
  <div v-if="auth.isAuthenticated" class="d-flex align-center ga-2">
    <v-menu v-if="auth.can_act_as_super_admin || auth.memberships.length > 1">
      <template #activator="{ props }">
        <v-btn v-bind="props" variant="text" append-icon="mdi-chevron-down" class="text-none" :loading="busy">
          {{ auth.acting_mode === 'super_admin' ? ROLE_LABELS.super_admin : auth.active_unit?.name || 'เลือกบทบาทการใช้งาน' }}
        </v-btn>
      </template>
      <v-list aria-label="สลับบทบาทการใช้งาน">
        <v-list-item v-if="auth.can_act_as_super_admin" :title="ROLE_LABELS.super_admin"
          :active="auth.acting_mode === 'super_admin'" :disabled="busy" @click="switchUnit('super_admin')" />
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
import Swal from 'sweetalert2';
import { alertError } from '../master/masterDialogs';
const auth = useAuthStore();
const router = useRouter();
const busy = ref(false);
function isCurrent(code: string): boolean {
  return code === 'super_admin' ? auth.acting_mode === 'super_admin' : auth.acting_mode !== 'super_admin' && auth.active_unit?.code === code;
}
function targetLabel(code: string): string {
  if (code === 'super_admin') return ROLE_LABELS.super_admin;
  const membership = auth.memberships.find((item) => item.unit_code === code);
  const name = membership?.unit_name || code;
  return membership ? `${name} (${ROLE_LABELS[membership.role]})` : name;
}
async function confirmSwitch(code: string): Promise<boolean> {
  const result = await Swal.fire({
    icon: 'question',
    title: 'เปลี่ยนบทบาทการใช้งาน',
    text: `ต้องการเปลี่ยนไปเป็น ${targetLabel(code)} ใช่หรือไม่`,
    showCancelButton: true,
    confirmButtonText: 'ใช่, เปลี่ยน',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#1a3673',
    cancelButtonColor: '#64748b',
    reverseButtons: true,
  });
  return result.isConfirmed;
}
async function switchUnit(code: string): Promise<void> {
  if (isCurrent(code) || !(await confirmSwitch(code))) return;
  busy.value = true;
  try {
    if (code === 'super_admin') await auth.selectSuperAdmin();
    else await auth.selectUnit(code);
    window.location.assign('/admin');
  } catch {
    await alertError('ไม่สามารถสลับบทบาทได้ กรุณาลองอีกครั้ง');
  } finally { busy.value = false; }
}
</script>
