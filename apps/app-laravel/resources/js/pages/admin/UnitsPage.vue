<template>
  <AppShell :breadcrumbs="[{ title: 'หน้าแรก', to: '/admin' }, { title: 'จัดการหน่วยงาน' }]" title="จัดการหน่วยงาน" subtitle="จัดการข้อมูลหน่วยงาน สมาชิก และสิทธิ์ดำเนินการ">
    <template v-if="auth.is_super_admin" #title-actions>
      <div class="d-flex ga-2 flex-wrap">
        <v-btn variant="outlined" :loading="syncing" :disabled="saving || syncing" @click="syncFaculties">ดึงรายการคณะจาก UMS</v-btn>
        <v-btn color="admin-primary" prepend-icon="mdi-plus" :disabled="saving || syncing" @click="openUnit()">เพิ่มหน่วยงาน</v-btn>
      </div>
    </template>
    <v-card flat border rounded="lg" class="pa-4">
      <div class="d-flex ga-3 flex-wrap mb-4">
        <v-text-field v-model="query" label="ค้นหาหน่วยงาน" prepend-inner-icon="mdi-magnify" variant="outlined" density="compact" hide-details clearable />
        <v-select v-model="type" label="ชนิด" :items="[{ title: 'ทุกชนิด', value: 'all' }, ...typeOptions]" variant="outlined" density="compact" hide-details />
        <v-select v-model="status" label="สถานะ" :items="statusOptions" variant="outlined" density="compact" hide-details />
      </div>
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" />
      <v-table class="units-table" aria-label="หน่วยงาน" :aria-busy="loading">
        <thead><tr><th>รหัส</th><th>ชื่อหน่วยงาน</th><th>ชนิด</th><th>หน่วยงานแม่</th><th>จำนวนสมาชิก</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
        <tbody>
          <tr v-for="unit in rows" :key="unit.code" :data-unit="unit.code" class="unit-row" tabindex="0" @click="router.push(`/admin/units/${unit.code}`)" @keydown.enter.self="router.push(`/admin/units/${unit.code}`)">
            <td class="text-center"><v-chip class="unit-code-chip" label size="small">{{ tableValue(unit.code) }}</v-chip></td><td :class="isEmptyTableValue(unit.name) ? 'text-center' : 'text-start'">{{ tableValue(unit.name) }}</td><td class="text-center">{{ tableValue(typeOptions.find((t) => t.value === unit.type)?.title) }}</td>
            <td :class="isEmptyTableValue(allUnits.find((u) => u.code === unit.parent_code)?.name || '-') ? 'text-center' : 'text-start'">{{ tableValue(allUnits.find((u) => u.code === unit.parent_code)?.name || '-') }}</td><td class="text-center">{{ unit.member_count ?? 0 }}</td>
            <td @click.stop @keydown.stop class="text-center"><div class="d-flex justify-center master-toggle"><v-switch :key="controlRevision" :model-value="unit.is_active" :disabled="saving || syncing || !auth.is_super_admin" :aria-label="`เปิดใช้งาน ${unit.name}`" color="success" hide-details density="compact" @update:model-value="toggleUnit(unit, Boolean($event))" /></div></td>
            <td @click.stop @keydown.stop class="text-center">
              <v-btn v-if="auth.is_super_admin" class="unit-action text-none" color="primary" variant="tonal" size="small" prepend-icon="mdi-pencil-outline" :disabled="saving || syncing" @click="openUnit(unit)">แก้ไขหน่วยงาน</v-btn>
              <v-btn class="unit-action text-none" color="#94713b" variant="tonal" size="small" prepend-icon="mdi-account-group-outline" :to="`/admin/units/${unit.code}`">จัดการสมาชิก</v-btn>
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
      <v-card rounded="lg" class="unit-dialog">
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
            <v-switch class="master-dialog__switch" v-model="unitForm.is_active" label="เปิดใช้งาน" color="success" hide-details :disabled="saving" />
            <div class="unit-dialog__actions d-flex justify-end ga-2 mt-4">
              <v-btn variant="text" :disabled="saving" @click="unitDialog = false">ยกเลิก</v-btn>
              <v-btn type="submit" color="admin-primary" :loading="saving">บันทึก</v-btn>
            </div>
          </v-form>
        </v-card-text>
      </v-card>
    </v-dialog>
    <v-dialog v-model="syncDialog" max-width="760" persistent :retain-focus="false">
      <v-card rounded="lg" class="unit-dialog">
        <v-card-title>รายการคณะจาก UMS ที่แตกต่างจากระบบ</v-card-title>
        <v-card-text>
          <v-alert :type="syncPreview?.fallback ? 'warning' : 'info'" variant="tonal" density="compact" class="mb-3">{{ syncPreview?.message }} (ทั้งหมด {{ syncPreview?.count }} รายการ)</v-alert>
          <p v-if="!syncPreview?.changes.length" class="py-4 text-center">ข้อมูลคณะตรงกับระบบแล้ว ไม่มีรายการที่ต้องอัปเดต</p>
          <v-table v-else density="compact">
            <thead><tr><th style="width:48px" /><th>สถานะ</th><th>รหัส UMS</th><th>ชื่อใน UMS</th><th>ชื่อในระบบ</th></tr></thead>
            <tbody>
              <tr v-for="row in syncPreview.changes" :key="`${row.status}-${row.fac_id}`">
                <td><v-checkbox-btn v-model="syncSelected" :value="`${row.status}:${row.fac_id}`" :aria-label="`เลือก ${row.name}`" /></td>
                <td><v-chip size="small" variant="tonal" :color="SYNC_COLORS[row.status]">{{ SYNC_LABELS[row.status] }}</v-chip></td>
                <td>{{ row.fac_id }}</td>
                <td>{{ row.status === 'missing' ? '-' : row.name }}</td>
                <td>{{ row.current_name || '-' }}</td>
              </tr>
            </tbody>
          </v-table>
          <p v-if="syncPreview?.changes.some((row) => row.status === 'renamed')" class="text-caption mt-3">
            คณะที่เปลี่ยนชื่อจะสร้างเป็นหน่วยงานใหม่ (รหัส DEP ใหม่) ส่วนหน่วยงานเดิมยังอยู่และเอกสารเดิมไม่ถูกเปลี่ยน สามารถปิดใช้งานหน่วยงานเดิมได้ภายหลัง
          </p>
          <div class="d-flex justify-end ga-2 mt-4">
            <v-btn variant="text" :disabled="saving" @click="syncDialog = false">ปิด</v-btn>
            <v-btn color="admin-primary" :loading="saving" :disabled="!syncSelected.length" @click="applySync">นำเข้าที่เลือก ({{ syncSelected.length }})</v-btn>
          </div>
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
import { jsonRequest, type ApiRequestError } from '../../api/client';
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
interface SyncChange { fac_id: string; status: 'new' | 'renamed' | 'missing'; name: string; current_code: string | null; current_name: string | null }
interface SyncPreview { token: string; fallback: boolean; count: number; message: string; changes: SyncChange[] }
const SYNC_LABELS = { new: 'ใหม่', renamed: 'ชื่อเปลี่ยน', missing: 'ไม่พบใน UMS' } as const;
const SYNC_COLORS = { new: 'success', renamed: 'warning', missing: 'error' } as const;
const syncDialog = ref(false);
const syncPreview = ref<SyncPreview | null>(null);
const syncSelected = ref<string[]>([]);
const controlRevision = ref(0);
const unitDialog = ref(false);
const editingCode = ref('');
const unitForm = reactive({ name: '', type: 'faculty' as UnitType, parent_code: null as string | null, ums_fac_id: '', ums_dep_id: '', is_active: true, sort_order: 0 });
const statusOptions = [{ title: 'ทุกสถานะ', value: 'all' }, { title: 'เปิดใช้งาน', value: 'active' }, { title: 'ปิดใช้งาน', value: 'inactive' }];
const typeOptions = [
  { title: 'คณะ / สำนัก', value: 'faculty' }, { title: 'กอง / ศูนย์', value: 'division' },
  { title: 'ฝ่าย / ภาควิชา', value: 'department' }, { title: 'หน่วยย่อยกองกฎหมาย', value: 'law_subunit' },
];
const visibleUnits = computed(() => allUnits.value.filter((u) => auth.is_super_admin || auth.active_unit?.code === u.code && ['inner_admin', 'outer_admin'].includes(auth.role ?? '')));
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
  if ((error as ApiRequestError)?.status === 403) return;
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
  if (!auth.is_super_admin || saving.value || syncing.value) return;
  editingCode.value = unit?.code || '';
  Object.assign(unitForm, { name: unit?.name || '', type: unit?.type || 'faculty', parent_code: unit?.parent_code || null,
    ums_fac_id: unit?.ums_fac_id || '', ums_dep_id: unit?.ums_dep_id || '', is_active: unit?.is_active ?? true, sort_order: unit?.sort_order || 0 });
  unitDialog.value = true;
}
async function saveUnit(): Promise<void> {
  if (saving.value || syncing.value || !auth.is_super_admin) return;
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
  if (saving.value || syncing.value || !auth.is_super_admin) return;
  if (!await confirm('ยืนยันการเปลี่ยนสถานะ', `${active ? 'เปิด' : 'ปิด'}ใช้งาน ${unit.name}?`)) { controlRevision.value++; return; }
  if (saving.value || syncing.value || !auth.is_super_admin) { controlRevision.value++; return; }
  saving.value = true;
  try {
    const { item } = await jsonRequest<{ item: Unit }>(`/api/units/${unit.code}/active`, { method: 'PATCH', body: JSON.stringify({ is_active: active }) });
    allUnits.value = allUnits.value.map((u) => u.code === item.code ? { ...u, ...item } : u);
    await auth.fetchMe(true);
  } catch (error) { await showError(error); }
  finally { controlRevision.value++; saving.value = false; }
}
async function syncFaculties(): Promise<void> {
  if (syncing.value || saving.value || !auth.is_super_admin) return;
  syncing.value = true;
  try {
    syncPreview.value = await jsonRequest<SyncPreview>('/api/units/sync-faculties', { method: 'POST' });
    syncSelected.value = syncPreview.value.changes.filter((row) => row.status !== 'missing').map((row) => `${row.status}:${row.fac_id}`);
    syncDialog.value = true;
  } catch (error) { await showError(error); }
  finally { syncing.value = false; }
}
async function applySync(): Promise<void> {
  if (!syncPreview.value || saving.value || syncSelected.value.length === 0) return;
  saving.value = true;
  try {
    const selected = [...new Set(syncSelected.value.map((key) => key.slice(key.indexOf(':') + 1)))];
    await jsonRequest('/api/units/sync-faculties/apply', { method: 'POST', body: JSON.stringify({ token: syncPreview.value.token, selected }) });
    syncDialog.value = false;
    await loadUnits();
    await Swal.fire({ icon: 'success', titleText: 'นำเข้ารายการคณะสำเร็จ', confirmButtonText: 'ตกลง', confirmButtonColor: '#475467' });
  } catch (error) { await showError(error); }
  finally { saving.value = false; }
}
</script>

<style scoped>
.unit-row { cursor: pointer; }
.unit-row:hover { background: #f1f5f9; }
.unit-dialog :deep(.v-card-title) { padding: 20px 24px 8px; }
.unit-dialog :deep(.v-card-text) { padding: 12px 24px 8px; }
.unit-dialog :deep(.v-input) { margin-bottom: 16px; }
.unit-dialog :deep(.v-input__details) { min-height: 0; padding-top: 2px; }
.unit-dialog :deep(.master-dialog__switch) { margin-bottom: 8px; }
.unit-action { font-weight: 600; }
.unit-action + .unit-action { margin-left: 12px; }
.unit-row:focus-visible { outline: 2px solid #1e3a8a; outline-offset: -2px; }
</style>
