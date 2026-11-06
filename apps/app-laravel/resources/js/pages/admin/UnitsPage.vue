<template>
  <AppShell :breadcrumbs="[{ title: 'หน้าแรก', to: '/admin' }, { title: 'จัดการหน่วยงาน' }]" title="จัดการหน่วยงาน" subtitle="จัดการข้อมูลหน่วยงาน สมาชิก และสิทธิ์ดำเนินการ">
    <template v-if="auth.is_super_admin" #title-actions>
      <div class="d-flex ga-2 flex-wrap">
        <v-btn variant="outlined" :loading="syncing" :disabled="saving" @click="syncFaculties">ดึงรายการคณะจาก UMS</v-btn>
        <v-btn color="admin-primary" prepend-icon="mdi-plus" :disabled="saving" @click="openUnit()">เพิ่มหน่วยงาน</v-btn>
      </div>
    </template>
    <v-card flat border rounded="lg" class="pa-4">
      <div class="d-flex ga-3 flex-wrap mb-4">
        <v-text-field v-model="query" label="ค้นหาหน่วยงาน" prepend-inner-icon="mdi-magnify" variant="outlined" density="compact" hide-details clearable />
        <v-select v-model="type" label="ชนิด" :items="[{ title: 'ทุกชนิด', value: 'all' }, ...typeOptions]" variant="outlined" density="compact" hide-details />
        <v-select v-model="status" label="สถานะ" :items="statusOptions" variant="outlined" density="compact" hide-details />
      </div>
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" />
      <v-table aria-label="หน่วยงาน">
        <thead><tr><th>รหัส</th><th>ชื่อหน่วยงาน</th><th>ชนิด</th><th>หน่วยงานแม่</th><th>จำนวนสมาชิก</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
        <tbody>
          <tr v-for="unit in rows" :key="unit.code" :data-unit="unit.code" class="unit-row" tabindex="0" @click="router.push(`/admin/units/${unit.code}`)" @keydown.enter.self="router.push(`/admin/units/${unit.code}`)">
            <td class="text-center">{{ tableValue(unit.code) }}</td><td :class="isEmptyTableValue(unit.name) ? 'text-center' : 'text-start'">{{ tableValue(unit.name) }}</td><td class="text-center">{{ tableValue(typeOptions.find((t) => t.value === unit.type)?.title) }}</td>
            <td :class="isEmptyTableValue(allUnits.find((u) => u.code === unit.parent_code)?.name || '-') ? 'text-center' : 'text-start'">{{ tableValue(allUnits.find((u) => u.code === unit.parent_code)?.name || '-') }}</td><td class="text-center">{{ unit.member_count ?? 0 }}</td>
            <td @click.stop @keydown.stop class="text-center"><div class="d-flex justify-center"><v-switch :key="controlRevision" :model-value="unit.is_active" :disabled="saving || !auth.is_super_admin" :aria-label="`เปิดใช้งาน ${unit.name}`" color="success" hide-details density="compact" @update:model-value="toggleUnit(unit, Boolean($event))" /></div></td>
            <td @click.stop @keydown.stop class="text-center">
              <v-btn v-if="auth.is_super_admin" variant="text" size="small" :disabled="saving" @click="openUnit(unit)">แก้ไขหน่วยงาน</v-btn>
              <v-btn variant="text" size="small" :to="`/admin/units/${unit.code}`">จัดการสมาชิก</v-btn>
            </td>
          </tr>
          <tr v-if="!loading && !rows.length"><td colspan="7" class="text-center pa-6">ไม่พบหน่วยงาน</td></tr>
        </tbody>
      </v-table>
      <div class="d-flex align-center ga-4 mt-4 flex-wrap">
        <span>ทั้งหมด {{ filtered.length }} หน่วยงาน</span>
        <v-select v-model="perPage" label="รายการต่อหน้า" :items="[10, 25, 50]" hide-details density="compact" style="max-width: 160px" />
        <v-pagination v-model="page" :length="pageCount" :total-visible="5" aria-label="หน้า" />
      </div>
    </v-card>
    <v-dialog v-model="unitDialog" max-width="600" :retain-focus="false" persistent>
      <v-card rounded="lg">
        <v-card-title>{{ editingCode ? 'แก้ไขหน่วยงาน' : 'เพิ่มหน่วยงาน' }}</v-card-title>
        <v-card-text>
          <v-form @submit.prevent="saveUnit">
            <v-text-field :model-value="editingCode || 'ระบบกำหนดรหัส DEP อัตโนมัติตามชนิดหน่วยงาน'" label="รหัสหน่วยงาน" variant="outlined" readonly />
            <v-text-field v-model="unitForm.name" label="ชื่อหน่วยงาน" variant="outlined" :disabled="saving" maxlength="255" />
            <v-select v-model="unitForm.type" label="ชนิดหน่วยงาน" :items="typeOptions" variant="outlined" :disabled="saving || Boolean(editingCode)" />
            <v-select v-if="unitForm.type === 'department'" v-model="unitForm.parent_code" label="คณะ / สำนักแม่"
              :items="allUnits.filter((u) => u.type === 'faculty')" item-title="name" item-value="code" variant="outlined" :disabled="saving" />
            <p v-else-if="unitForm.type === 'division'" class="mb-4">หน่วยงานแม่: สำนักงานอธิการบดี</p>
            <p v-else-if="unitForm.type === 'law_subunit'" class="mb-4">หน่วยงานแม่: กองกฎหมาย</p>
            <v-text-field v-if="unitForm.type === 'faculty'" v-model="unitForm.ums_fac_id" label="รหัสคณะใน UMS" variant="outlined" :disabled="saving" />
            <v-text-field v-model="unitForm.ums_dep_id" label="รหัสฝ่าย / หน่วยงานใน UMS" variant="outlined" :disabled="saving" />
            <v-text-field v-model.number="unitForm.sort_order" label="ลำดับแสดงผล" type="number" min="0" variant="outlined" :disabled="saving" />
            <v-switch v-model="unitForm.is_active" label="เปิดใช้งาน" color="success" hide-details :disabled="saving" />
            <div class="d-flex justify-end ga-2 mt-4">
              <v-btn variant="text" :disabled="saving" @click="unitDialog = false">ยกเลิก</v-btn>
              <v-btn type="submit" color="admin-primary" :loading="saving">บันทึก</v-btn>
            </div>
          </v-form>
        </v-card-text>
      </v-card>
    </v-dialog>
  </AppShell>
</template>

<script setup lang="ts">
import { tableValue, isEmptyTableValue } from '../../utils/tableValue';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Swal from 'sweetalert2';
import AppShell from '../../components/shared/AppShell.vue';
import { alertError } from '../../components/master/masterDialogs';
import { jsonRequest } from '../../api/client';
import { useAuthStore } from '../../stores/authStore';
import type { Unit, UnitType } from '../../types/unit';
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const allUnits = ref<Unit[]>([]);
const query = ref(String(route.query.q || ''));
const status = ref(String(route.query.status || 'all'));
const type = ref(String(route.query.type || 'all'));
const positive = (value: unknown, fallback: number) => /^\d+$/.test(String(value)) && Number(value) > 0 ? Number(value) : fallback;
const page = ref(positive(route.query.page, 1));
const perPage = ref(positive(route.query.per_page, 10));
const loading = ref(false);
const saving = ref(false);
const syncing = ref(false);
const controlRevision = ref(0);
const unitDialog = ref(false);
const editingCode = ref('');
const unitForm = reactive({ name: '', type: 'faculty' as UnitType, parent_code: null as string | null, ums_fac_id: '', ums_dep_id: '', is_active: true, sort_order: 0 });
const statusOptions = [{ title: 'ทุกสถานะ', value: 'all' }, { title: 'เปิดใช้งาน', value: 'active' }, { title: 'ปิดใช้งาน', value: 'inactive' }];
const typeOptions = [
  { title: 'คณะ / สำนัก', value: 'faculty' }, { title: 'กอง / ศูนย์', value: 'division' },
  { title: 'ฝ่าย / ภาควิชา', value: 'department' }, { title: 'หน่วยย่อยกองกฎหมาย', value: 'law_subunit' },
];
const visibleUnits = computed(() => allUnits.value.filter((u) => auth.is_super_admin || auth.memberships.some((m) => m.unit_code === u.code && ['inner_admin', 'outer_admin'].includes(m.role))));
// Status changes keep the current rows mounted until the viewer changes filters.
const statusSnapshot = ref(new Map<string, boolean>());
function snapshotStatus(): void { statusSnapshot.value = new Map(allUnits.value.map((u) => [u.code, u.is_active])); }
const filtered = computed(() => visibleUnits.value.filter((u) => `${u.code} ${u.name}`.toLocaleLowerCase().includes((query.value || '').trim().toLocaleLowerCase())
  && (type.value === 'all' || u.type === type.value) && (status.value === 'all' || statusSnapshot.value.get(u.code) === (status.value === 'active'))));
const pageCount = computed(() => Math.max(1, Math.ceil(filtered.value.length / perPage.value)));
const rows = computed(() => filtered.value.slice((page.value - 1) * perPage.value, page.value * perPage.value));
watch([query, status, type, perPage], () => { page.value = 1; });
watch([query, status, type], () => { snapshotStatus(); });
watch(pageCount, (count) => { if (page.value > count) page.value = count; });
watch([query, status, type, page, perPage], () => { void router.replace({ query: { q: query.value || undefined, status: status.value === 'all' ? undefined : status.value,
  type: type.value === 'all' ? undefined : type.value, page: page.value > 1 ? String(page.value) : undefined, per_page: perPage.value !== 10 ? String(perPage.value) : undefined } }); });
watch(() => route.query, (q) => {
  query.value = String(q.q || ''); status.value = String(q.status || 'all'); type.value = String(q.type || 'all');
  page.value = positive(q.page, 1); perPage.value = positive(q.per_page, 10);
});
async function showError(error: unknown): Promise<void> {
  await alertError(error instanceof Error && /[ก-๙]/.test(error.message) ? error.message : 'ไม่สามารถดำเนินการได้ กรุณาลองอีกครั้ง');
}
async function confirm(title: string, text: string): Promise<boolean> {
  return (await Swal.fire({ icon: 'warning', titleText: title, text, showCancelButton: true, confirmButtonText: 'ยืนยัน', cancelButtonText: 'ยกเลิก' })).isConfirmed;
}
async function loadUnits(): Promise<void> {
  loading.value = !allUnits.value.length;
  try { allUnits.value = (await jsonRequest<{ items: Unit[] }>('/api/units')).items; snapshotStatus(); }
  finally { loading.value = false; }
}
onMounted(async () => { try { await loadUnits(); } catch (error) { await showError(error); } });
function openUnit(unit?: Unit): void {
  editingCode.value = unit?.code || '';
  Object.assign(unitForm, { name: unit?.name || '', type: unit?.type || 'faculty', parent_code: unit?.parent_code || null,
    ums_fac_id: unit?.ums_fac_id || '', ums_dep_id: unit?.ums_dep_id || '', is_active: unit?.is_active ?? true, sort_order: unit?.sort_order || 0 });
  unitDialog.value = true;
}
async function saveUnit(): Promise<void> {
  if (saving.value || !auth.is_super_admin) return;
  if (!unitForm.name.trim()) { await alertError('กรุณาระบุชื่อหน่วยงาน'); return; }
  if (unitForm.type === 'department' && !unitForm.parent_code) { await alertError('กรุณาเลือกคณะหรือสำนักแม่'); return; }
  if (!Number.isInteger(unitForm.sort_order) || unitForm.sort_order < 0) { await alertError('ลำดับต้องเป็นจำนวนเต็มตั้งแต่ศูนย์ขึ้นไป'); return; }
  saving.value = true;
  try {
    const { item } = await jsonRequest<{ item: Unit }>(`/api/units${editingCode.value ? `/${editingCode.value}` : ''}`, {
      method: editingCode.value ? 'PUT' : 'POST', body: JSON.stringify({ ...unitForm, name: unitForm.name.trim(), ums_fac_id: unitForm.ums_fac_id.trim() || null, ums_dep_id: unitForm.ums_dep_id.trim() || null }),
    });
    unitDialog.value = false;
    const index = allUnits.value.findIndex((u) => u.code === item.code);
    if (index >= 0) allUnits.value[index] = { ...allUnits.value[index]!, ...item };
    else allUnits.value.push({ ...item, member_count: 0 });
    await auth.fetchMe(true);
  } catch (error) { await showError(error); }
  finally { controlRevision.value++; saving.value = false; }
}
async function toggleUnit(unit: Unit, active: boolean): Promise<void> {
  if (saving.value || !auth.is_super_admin) return;
  if (!await confirm('ยืนยันการเปลี่ยนสถานะ', `${active ? 'เปิด' : 'ปิด'}ใช้งาน ${unit.name}?`)) { controlRevision.value++; return; }
  saving.value = true;
  try {
    const { item } = await jsonRequest<{ item: Unit }>(`/api/units/${unit.code}/active`, { method: 'PATCH', body: JSON.stringify({ is_active: active }) });
    allUnits.value = allUnits.value.map((u) => u.code === item.code ? { ...u, ...item } : u);
    await auth.fetchMe(true);
  } catch (error) { await showError(error); }
  finally { controlRevision.value++; saving.value = false; }
}
async function syncFaculties(): Promise<void> {
  syncing.value = true;
  try {
    const result = await jsonRequest<{ count: number; fallback: boolean; message: string }>('/api/units/sync-faculties', { method: 'POST' });
    await loadUnits();
    await Swal.fire({ icon: result.fallback ? 'warning' : 'success', titleText: result.fallback ? 'ใช้ข้อมูลคณะสำรอง' : 'ดึงรายการคณะสำเร็จ',
      text: `${result.message} จำนวน ${result.count} รายการ`, confirmButtonText: 'ตกลง', confirmButtonColor: '#475467' });
  } catch (error) { await showError(error); }
  finally { syncing.value = false; }
}
</script>

<style scoped>
.unit-row { cursor: pointer; }
.unit-row:hover { background: #f1f5f9; }
</style>
