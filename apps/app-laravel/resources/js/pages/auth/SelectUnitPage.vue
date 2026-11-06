<template>
  <v-main class="bg-grey-lighten-4">
    <v-container style="max-width: 1100px" class="py-10">
      <v-breadcrumbs :items="['หน้าแรก', 'เลือกหน่วยงาน']" class="px-0" />
      <h1 class="text-h5 font-weight-bold mb-2">เลือกหน่วยงาน</h1>
      <p class="mb-6">เลือกหน่วยงานที่ต้องการใช้งานในครั้งนี้</p>
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" />
      <v-row>
        <v-col v-for="membership in auth.memberships" :key="membership.unit_code" cols="12" md="6" lg="4">
          <v-card border flat rounded="lg" class="pa-5 h-100">
            <h2 class="text-h6 mb-2">{{ membership.unit_name || membership.unit_code }}</h2>
            <p class="text-body-2 mb-3">หน่วยงานแม่: {{ parentName(membership.unit?.parent_code) }}</p>
            <v-chip size="small" color="admin-primary" class="mb-3">{{ ROLE_LABELS[membership.role] }}</v-chip>
            <p class="mb-4">สิทธิ์ดำเนินการ {{ membership.actions.length }} รายการ</p>
            <v-btn color="admin-primary" block :disabled="loading || busy || !membership.unit?.is_active"
              :loading="selected === membership.unit_code" @click="select(membership.unit_code)">
              {{ membership.unit?.is_active ? 'เข้าใช้งานหน่วยงานนี้' : 'หน่วยงานปิดใช้งาน' }}
            </v-btn>
          </v-card>
        </v-col>
      </v-row>
      <v-btn class="mt-6" variant="text" @click="auth.logout()">ออกจากระบบ</v-btn>
    </v-container>
  </v-main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore, ROLE_LABELS } from '../../stores/authStore';
import { jsonRequest } from '../../api/client';
import { alertError } from '../../components/master/masterDialogs';
import type { Unit } from '../../types/unit';
const auth = useAuthStore();
const router = useRouter();
const units = ref<Unit[]>([]);
const loading = ref(true);
const busy = ref(false);
const selected = ref('');
function parentName(code?: string | null): string {
  return code ? units.value.find((u) => u.code === code)?.name || code : 'ไม่มีหน่วยงานแม่';
}
onMounted(async () => {
  try { units.value = (await jsonRequest<{ items: Unit[] }>('/api/units')).items; }
  catch { await alertError('ไม่สามารถโหลดรายชื่อหน่วยงานแม่ได้ กรุณาโหลดหน้าใหม่'); }
  finally { loading.value = false; }
});
async function select(code: string): Promise<void> {
  busy.value = true;
  selected.value = code;
  try { await auth.selectUnit(code); await router.push('/'); }
  catch { await alertError('ไม่สามารถเลือกหน่วยงานได้ กรุณาลองอีกครั้ง'); }
  finally { busy.value = false; selected.value = ''; }
}
</script>
