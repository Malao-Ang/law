<template>
  <v-dialog :model-value="open" persistent scrollable max-width="960">
    <v-card rounded="lg">
      <v-card-title class="pt-5 px-6 text-h6 font-weight-bold">เลือกบทบาทการใช้งาน</v-card-title>
      <v-card-subtitle class="px-6">เลือกบทบาทที่ต้องการใช้งานในครั้งนี้</v-card-subtitle>
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" class="mt-3" />
      <v-card-text class="px-6 pt-4">
        <v-row>
          <v-col cols="12" sm="6" md="4">
            <v-card border flat rounded="lg" class="pa-5 h-100 d-flex flex-column">
              <h2 class="text-h6 mb-2">{{ ROLE_LABELS.user }}</h2>
              <p class="mb-4">ค้นหาและอ่านเอกสารตามสิทธิ์ โดยไม่ใช้สิทธิ์จัดการของหน่วยงาน</p>
              <v-spacer />
              <v-btn color="admin-primary" block class="flex-grow-0" :disabled="busy"
                :loading="selected === 'user'" @click="select('user')">เข้าใช้งานในบทบาทนี้</v-btn>
            </v-card>
          </v-col>
          <v-col v-if="auth.can_act_as_super_admin" cols="12" sm="6" md="4">
            <v-card border flat rounded="lg" class="pa-5 h-100 d-flex flex-column">
              <h2 class="text-h6 mb-2">{{ ROLE_LABELS.super_admin }}</h2>
              <p class="mb-4">จัดการข้อมูลและสิทธิ์ของทุกหน่วยงาน</p>
              <v-spacer />
              <v-btn color="admin-primary" block class="flex-grow-0" :disabled="loading || busy"
                :loading="selected === 'super_admin'" @click="select('super_admin')">
                เข้าใช้งานในบทบาทนี้
              </v-btn>
            </v-card>
          </v-col>
          <v-col v-for="membership in auth.memberships" :key="membership.unit_code" cols="12" sm="6" md="4">
            <v-card border flat rounded="lg" class="pa-5 h-100 d-flex flex-column">
              <h2 class="text-h6 mb-2">{{ membership.unit_name || membership.unit_code }}</h2>
              <p v-if="membership.unit?.parent_code" class="text-body-2 mb-3">หน่วยงานแม่: {{ parentName(membership.unit.parent_code) }}</p>
              <div><v-chip size="small" color="admin-primary" class="mb-3">{{ ROLE_LABELS[membership.role] }}</v-chip></div>
              <p class="mb-4">สิทธิ์ดำเนินการ: {{ membership.actions.map((action) => ACTION_LABELS[action]).join(', ') || 'ดูเอกสารตามสิทธิ์การเข้าถึง' }}</p>
              <v-spacer />
              <v-btn color="admin-primary" block class="flex-grow-0" :disabled="loading || busy || !membership.unit?.is_active"
                :loading="selected === membership.unit_code" @click="select(membership.unit_code)">
                {{ membership.unit?.is_active ? 'เข้าใช้งานหน่วยงานนี้' : 'หน่วยงานปิดใช้งาน' }}
              </v-btn>
            </v-card>
          </v-col>
        </v-row>
      </v-card-text>
      <v-card-actions class="px-6 pb-5">
        <v-btn variant="text" :disabled="busy" prepend-icon="mdi-logout" @click="logout">ออกจากระบบ</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore, ROLE_LABELS } from '../../stores/authStore';
import { jsonRequest } from '../../api/client';
import { alertError } from '../../components/master/masterDialogs';
import type { Unit, UnitAction } from '../../types/unit';
const ACTION_LABELS: Record<UnitAction, string> = {
  upload: 'อัปโหลด', edit: 'แก้ไข', delete: 'ลบ', esign: 'ส่งลงนาม', publish: 'เผยแพร่',
};
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const open = computed(() => auth.isAuthenticated && auth.needs_unit_selection);
const units = ref<Unit[]>([]);
const loading = ref(false);
const busy = ref(false);
const selected = ref('');
function parentName(code: string): string {
  return units.value.find((u) => u.code === code)?.name || code;
}
watch(open, async (value) => {
  if (!value || units.value.length > 0) return;
  loading.value = true;
  try { units.value = (await jsonRequest<{ items: Unit[] }>('/api/units')).items; }
  catch { await alertError('ไม่สามารถโหลดรายชื่อหน่วยงานแม่ได้ กรุณาโหลดหน้าใหม่'); }
  finally { loading.value = false; }
}, { immediate: true });
function targetPath(): string {
  const value = route.query.redirect;
  if (route.path === '/login') {
    return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : '/admin';
  }
  return route.fullPath;
}
async function logout(): Promise<void> {
  await auth.logout('/');
}
async function select(code: string): Promise<void> {
  busy.value = true;
  selected.value = code;
  try {
    if (code === 'user') await auth.selectUser();
    else if (code === 'super_admin') await auth.selectSuperAdmin();
    else await auth.selectUnit(code);
    window.location.assign(code === 'user' ? '/' : targetPath());
  }
  catch { await alertError('ไม่สามารถเลือกบทบาทการใช้งานได้ กรุณาลองอีกครั้ง'); }
  finally { busy.value = false; selected.value = ''; }
}
</script>
