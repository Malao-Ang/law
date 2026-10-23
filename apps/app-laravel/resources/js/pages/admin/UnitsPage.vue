<template>
  <AppShell :breadcrumbs="['หน้าแรก', 'จัดการหน่วยงาน']" title="จัดการหน่วยงาน" subtitle="จัดการข้อมูลหน่วยงาน สมาชิก และสิทธิ์ดำเนินการ">
    <template v-if="auth.is_super_admin" #title-actions>
      <div class="d-flex ga-2 flex-wrap">
        <v-btn variant="outlined" :loading="syncing" :disabled="saving" @click="syncFaculties">ดึงรายการคณะจาก UMS</v-btn>
        <v-btn color="admin-primary" prepend-icon="mdi-plus" :disabled="saving" @click="openUnit()">เพิ่มหน่วยงาน</v-btn>
      </div>
    </template>
    <v-row>
      <v-col cols="12" md="4" lg="3">
        <v-card flat border rounded="lg" class="pa-4">
          <v-text-field v-model="query" label="ค้นหาหน่วยงาน" prepend-inner-icon="mdi-magnify" variant="outlined" density="compact" hide-details clearable />
          <v-select v-model="status" label="สถานะหน่วยงาน" :items="statusOptions" variant="outlined" density="compact" hide-details class="mt-3" />
          <v-progress-linear v-if="loading" indeterminate color="admin-primary" class="mt-3" />
          <v-list aria-label="หน่วยงาน" class="unit-tree" density="compact">
            <v-list-item v-for="row in treeRows" :key="row.unit.code" :active="selectedCode === row.unit.code"
              :disabled="saving" color="admin-primary" rounded="lg" :style="{ paddingInlineStart: `${12 + row.depth * 18}px` }"
              @click="selectedCode = row.unit.code">
              <template #prepend>
                <v-btn v-if="visibleUnits.some((u) => u.parent_code === row.unit.code)" size="x-small" variant="text"
                  :icon="collapsed.has(row.unit.code) ? 'mdi-chevron-right' : 'mdi-chevron-down'"
                  :aria-label="`${collapsed.has(row.unit.code) ? 'ขยาย' : 'ย่อ'} ${row.unit.name}`"
                  :aria-expanded="!collapsed.has(row.unit.code)" @click.stop="toggleBranch(row.unit.code)" />
              </template>
              <v-list-item-title class="text-wrap text-body-2">{{ row.unit.name }}</v-list-item-title>
              <v-list-item-subtitle>{{ row.unit.code }}{{ row.unit.is_active ? '' : ' · ปิดใช้งาน' }}</v-list-item-subtitle>
              <template v-if="auth.is_super_admin" #append>
                <v-switch :key="controlRevision" :model-value="row.unit.is_active" :disabled="saving" hide-details density="compact" color="success"
                  :aria-label="`เปิดใช้งาน ${row.unit.name}`" @click.stop @update:model-value="toggleUnit(row.unit, Boolean($event))" />
              </template>
            </v-list-item>
          </v-list>
          <p v-if="!loading && !treeRows.length" class="text-body-2 text-medium-emphasis mt-3">ไม่พบหน่วยงาน</p>
        </v-card>
      </v-col>
      <v-col cols="12" md="8" lg="9">
        <v-card flat border rounded="lg">
          <v-card-title class="d-flex align-center ga-2 flex-wrap">
            <span>{{ selectedUnit?.name || 'เลือกหน่วยงาน' }}</span>
            <v-spacer />
            <template v-if="auth.is_super_admin && selectedUnit">
              <v-btn variant="text" size="small" :disabled="saving" @click="openUnit(selectedUnit)">แก้ไขหน่วยงาน</v-btn>
              <v-btn color="admin-primary" size="small" prepend-icon="mdi-account-plus-outline" :disabled="saving" @click="openPeople">เพิ่มคน</v-btn>
            </template>
          </v-card-title>
          <v-card-text v-if="selectedUnit" class="pb-0">
            <p>หน่วยงานแม่: {{ allUnits.find((u) => u.code === selectedUnit?.parent_code)?.name || 'ไม่มีหน่วยงานแม่' }}</p>
            <p v-if="selectedUnit.missing_in_ums" class="text-warning">ไม่พบหน่วยงานนี้ในการดึงข้อมูล UMS ครั้งล่าสุด</p>
            <p class="text-medium-emphasis mt-2">{{ permissionHint }}</p>
          </v-card-text>
          <v-progress-linear v-if="membersLoading || saving" indeterminate color="admin-primary" />
          <v-table v-if="selectedUnit" class="members-table">
            <thead><tr>
              <th>ชื่อ</th><th>ชื่อผู้ใช้</th><th>บทบาท</th>
              <th v-for="action in actions" :key="action.value">{{ action.title }}</th>
              <th>ที่มา</th><th>จัดการ</th>
            </tr></thead>
            <tbody>
              <tr v-for="member in members" :key="member.username">
                <td>{{ member.name || '-' }}<span v-if="member.is_disabled" class="text-error"> (ปิดใช้งาน)</span></td>
                <td>{{ member.username }}</td>
                <td>
                  <v-select v-if="canEdit(member)" :key="controlRevision" :model-value="member.role" :items="roleOptions" :disabled="saving || membersLoading"
                    :aria-label="`บทบาทของ ${member.username}`" variant="outlined" density="compact" hide-details
                    @update:model-value="changeRole(member, $event)" />
                  <v-chip v-else size="small" variant="tonal">{{ ROLE_LABELS[member.role] }}</v-chip>
                </td>
                <td v-for="action in actions" :key="action.value">
                  <v-checkbox-btn :key="controlRevision" :model-value="member.actions.includes(action.value)" color="admin-primary"
                    :aria-label="`${action.title} สำหรับ ${member.username}`"
                    :disabled="saving || membersLoading || !canEdit(member) || isAdmin(member.role)"
                    @update:model-value="changeAction(member, action.value, Boolean($event))" />
                </td>
                <td>{{ sourceLabel(member.source) }}</td>
                <td><v-btn v-if="auth.is_super_admin" icon="mdi-account-remove-outline" size="small" variant="text" color="error"
                  :disabled="saving || membersLoading" :aria-label="`ลบสมาชิก ${member.username}`" @click="removeMember(member)" /><span v-else>—</span></td>
              </tr>
              <tr v-if="!membersLoading && !members.length"><td colspan="10" class="text-center py-8">ยังไม่มีสมาชิกในหน่วยงานนี้</td></tr>
            </tbody>
          </v-table>
          <v-card-text v-else>เลือกหน่วยงานทางซ้ายเพื่อดูสมาชิก</v-card-text>
        </v-card>
      </v-col>
    </v-row>

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
    <v-dialog v-model="peopleDialog" max-width="650" :retain-focus="false" persistent>
      <v-card rounded="lg">
        <v-card-title>เพิ่มคนใน {{ selectedUnit?.name }}</v-card-title>
        <v-card-text>
          <v-form class="d-flex ga-2" @submit.prevent="searchPeople">
            <v-text-field v-model="peopleQuery" label="ค้นหาชื่อ นามสกุล หรือชื่อผู้ใช้" variant="outlined" density="compact" hide-details :disabled="searching || saving" />
            <v-btn type="submit" color="admin-primary" :loading="searching" :disabled="saving">ค้นหา</v-btn>
          </v-form>
          <v-list>
            <v-list-item v-for="person in people" :key="person.username" :title="person.name || person.username" :subtitle="person.username">
              <template #append>
                <v-btn size="small" variant="tonal" :disabled="saving || members.some((m) => m.username === person.username)" @click="addPerson(person)">
                  {{ members.some((m) => m.username === person.username) ? 'เป็นสมาชิกแล้ว' : 'เพิ่มเป็นผู้ใช้งาน' }}
                </v-btn>
              </template>
            </v-list-item>
          </v-list>
          <p v-if="searched && !people.length && !searching">ไม่พบผู้ใช้ที่ตรงกับคำค้นหา</p>
        </v-card-text>
        <v-card-actions><v-spacer /><v-btn :disabled="saving || searching" @click="peopleDialog = false">ปิด</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import Swal from 'sweetalert2';
import AppShell from '../../components/shared/AppShell.vue';
import { alertError } from '../../components/master/masterDialogs';
import { jsonRequest } from '../../api/client';
import { useAuthStore, ROLE_LABELS } from '../../stores/authStore';
import type { Membership, Unit, UnitAction, UnitRole, UnitType } from '../../types/unit';

interface Member extends Membership { username: string; name: string | null; is_disabled: boolean; }
interface Person { username: string; name: string | null; }
const auth = useAuthStore();
const allUnits = ref<Unit[]>([]);
const members = ref<Member[]>([]);
const selectedCode = ref('');
const query = ref('');
const collapsed = ref(new Set<string>());
function toggleBranch(code: string): void {
  const next = new Set(collapsed.value);
  if (next.has(code)) next.delete(code); else next.add(code);
  collapsed.value = next;
}
const status = ref('all');
const loading = ref(false);
const membersLoading = ref(false);
const saving = ref(false);
const syncing = ref(false);
const controlRevision = ref(0);
const unitDialog = ref(false);
const editingCode = ref('');
const peopleDialog = ref(false);
const peopleQuery = ref('');
const people = ref<Person[]>([]);
const searching = ref(false);
const searched = ref(false);
const unitForm = reactive({ name: '', type: 'faculty' as UnitType, parent_code: null as string | null, ums_fac_id: '', ums_dep_id: '', is_active: true, sort_order: 0 });
const actions: { title: string; value: UnitAction }[] = [
  { title: 'อัปโหลด', value: 'upload' }, { title: 'แก้ไข', value: 'edit' }, { title: 'ลบ', value: 'delete' },
  { title: 'ส่ง e-sign', value: 'esign' }, { title: 'เผยแพร่', value: 'publish' },
];
const statusOptions = [{ title: 'ทุกสถานะ', value: 'all' }, { title: 'เปิดใช้งาน', value: 'active' }, { title: 'ปิดใช้งาน', value: 'inactive' }];
const typeOptions = [
  { title: 'คณะ / สำนัก (DEP001–099)', value: 'faculty' }, { title: 'กอง / ศูนย์ (DEP100–199)', value: 'division' },
  { title: 'ฝ่าย / ภาควิชา (DEP200–499)', value: 'department' }, { title: 'หน่วยย่อยกองกฎหมาย (DEP500–999)', value: 'law_subunit' },
];
function isAdmin(role: string): boolean { return role === 'inner_admin' || role === 'outer_admin'; }
const visibleUnits = computed(() => allUnits.value.filter((u) => auth.is_super_admin || auth.memberships.some((m) => m.unit_code === u.code && isAdmin(m.role))));
const selectedUnit = computed(() => visibleUnits.value.find((u) => u.code === selectedCode.value));
const ownRole = computed(() => auth.memberships.find((m) => m.unit_code === selectedCode.value)?.role);
const roleOptions = computed(() => (['user', 'editor', ...(auth.is_super_admin ? ['inner_admin', 'outer_admin'] : [])] as UnitRole[]).map((role) => ({ title: ROLE_LABELS[role], value: role })));
const permissionHint = computed(() => auth.is_super_admin
  ? 'บันทึกสิทธิ์ทันทีเมื่อติ๊กช่อง หากติ๊กสิทธิ์ให้ผู้ใช้งานทั่วไป ระบบจะเปลี่ยนเป็นผู้แก้ไข'
  : ownRole.value === 'inner_admin' ? 'ปรับได้เฉพาะผู้ใช้งานทั่วไปและผู้แก้ไขในหน่วยงานนี้ ติ๊กสิทธิ์เพื่อเปลี่ยนเป็นผู้แก้ไขและบันทึกทันที' : 'ดูข้อมูลสมาชิกและสิทธิ์ได้อย่างเดียว');
const treeRows = computed(() => {
  const units = visibleUnits.value;
  const rows: { unit: Unit; depth: number }[] = [];
  const visited = new Set<string>();
  function hideBranch(unit: Unit): void {
    if (visited.has(unit.code)) return;
    visited.add(unit.code);
    units.filter((u) => u.parent_code === unit.code).forEach(hideBranch);
  }
  function visit(unit: Unit, depth: number): void {
    if (visited.has(unit.code)) return;
    visited.add(unit.code);
    const match = `${unit.code} ${unit.name}`.toLocaleLowerCase().includes((query.value || '').trim().toLocaleLowerCase());
    if (match && (status.value === 'all' || unit.is_active === (status.value === 'active'))) rows.push({ unit, depth });
    units.filter((u) => u.parent_code === unit.code).forEach((u) => {
      if (collapsed.value.has(unit.code) && !query.value?.trim()) hideBranch(u);
      else visit(u, depth + 1);
    });
  }
  units.filter((u) => !units.some((p) => p.code === u.parent_code)).forEach((u) => visit(u, 0));
  units.forEach((u) => visit(u, 0));
  return rows;
});
function canEdit(member: Member): boolean { return auth.is_super_admin || (ownRole.value === 'inner_admin' && ['user', 'editor'].includes(member.role)); }
function sourceLabel(source: string): string { return ({ ums: 'UMS', manual: 'เพิ่มโดยผู้ดูแล', seed: 'ข้อมูลเริ่มต้น' } as Record<string, string>)[source] || 'ไม่ระบุ'; }
async function showError(error: unknown): Promise<void> {
  const message = error instanceof Error && /[ก-๙]/.test(error.message) ? error.message : 'ไม่สามารถดำเนินการได้ กรุณาลองอีกครั้ง';
  await alertError(message);
}
async function loadUnits(): Promise<void> {
  loading.value = true;
  try {
    allUnits.value = (await jsonRequest<{ items: Unit[] }>('/api/units')).items;
    if (!selectedUnit.value) selectedCode.value = visibleUnits.value[0]?.code || '';
  } finally { loading.value = false; }
}
let memberRequest = 0;
async function loadMembers(): Promise<void> {
  const request = ++memberRequest;
  const code = selectedCode.value;
  members.value = [];
  if (!code || !selectedUnit.value) { membersLoading.value = false; return; }
  membersLoading.value = true;
  try {
    const result = await jsonRequest<{ items: Member[] }>(`/api/units/${code}/members`);
    if (request === memberRequest) members.value = result.items;
  } catch (error) { if (request === memberRequest) await showError(error); }
  finally { if (request === memberRequest) membersLoading.value = false; }
}
watch(selectedCode, loadMembers);
onMounted(async () => { try { await loadUnits(); } catch (error) { await showError(error); } });
async function confirm(title: string, text: string): Promise<boolean> {
  return (await Swal.fire({ icon: 'warning', titleText: title, text, showCancelButton: true,
    confirmButtonText: 'ยืนยัน', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#b42318', cancelButtonColor: '#64748b' })).isConfirmed;
}
async function updateMember(member: Member | Person, role: UnitRole, memberActions: UnitAction[]): Promise<void> {
  if (saving.value) return;
  saving.value = true;
  const code = selectedCode.value;
  try {
    await jsonRequest(`/api/units/${code}/members/${encodeURIComponent(member.username)}`, { method: 'PUT', body: JSON.stringify({ role, actions: memberActions }) });
    await loadMembers();
    await auth.fetchMe(true);
  } catch (error) { await showError(error); }
  finally { controlRevision.value++; saving.value = false; }
}
async function changeRole(member: Member, role: UnitRole): Promise<void> {
  if (role === member.role || !canEdit(member) || saving.value) return;
  if (isAdmin(role) && !await confirm('ยืนยันการตั้งผู้ดูแลหน่วยงาน', `กำหนดให้ ${member.name || member.username} เป็น${ROLE_LABELS[role]}?`)) { controlRevision.value++; return; }
  await updateMember(member, role, role === 'user' ? [] : member.actions);
}
async function changeAction(member: Member, action: UnitAction, checked: boolean): Promise<void> {
  if (!canEdit(member) || isAdmin(member.role)) return;
  const next = member.actions.filter((a) => a !== action);
  if (checked) next.push(action);
  await updateMember(member, checked && member.role === 'user' ? 'editor' : member.role, next);
}
async function removeMember(member: Member): Promise<void> {
  if (!auth.is_super_admin || saving.value || !await confirm('ยืนยันการลบสมาชิก', `ลบ ${member.name || member.username} ออกจากหน่วยงานนี้?`)) return;
  saving.value = true;
  try {
    await jsonRequest(`/api/units/${selectedCode.value}/members/${encodeURIComponent(member.username)}`, { method: 'DELETE' });
    await loadMembers();
    await auth.fetchMe(true);
  } catch (error) { await showError(error); }
  finally { controlRevision.value++; saving.value = false; }
}
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
    await loadUnits();
    selectedCode.value = item.code;
    await auth.fetchMe(true);
  } catch (error) { await showError(error); }
  finally { controlRevision.value++; saving.value = false; }
}
async function toggleUnit(unit: Unit, active: boolean): Promise<void> {
  if (saving.value || !auth.is_super_admin) return;
  saving.value = true;
  try {
    const { item } = await jsonRequest<{ item: Unit }>(`/api/units/${unit.code}/active`, { method: 'PATCH', body: JSON.stringify({ is_active: active }) });
    allUnits.value = allUnits.value.map((u) => u.code === item.code ? item : u);
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
function openPeople(): void { peopleQuery.value = ''; people.value = []; searched.value = false; peopleDialog.value = true; }
async function searchPeople(): Promise<void> {
  if (peopleQuery.value.trim().length < 2) { await alertError('กรุณาระบุคำค้นหาอย่างน้อย 2 ตัวอักษร'); return; }
  searching.value = true;
  people.value = [];
  try {
    const result = await jsonRequest<{ items: Person[]; ums_unavailable: boolean; message: string | null }>(`/api/users/search?q=${encodeURIComponent(peopleQuery.value.trim())}`);
    people.value = result.items;
    searched.value = true;
    if (result.ums_unavailable) await Swal.fire({ icon: 'warning', titleText: 'ค้นหาจาก UMS ไม่สำเร็จ', text: result.message || 'แสดงเฉพาะผู้ใช้ที่เคยเข้าสู่ระบบ', confirmButtonText: 'ตกลง', confirmButtonColor: '#475467' });
  } catch (error) { await showError(error); }
  finally { searching.value = false; }
}
async function addPerson(person: Person): Promise<void> {
  if (!auth.is_super_admin || members.value.some((m) => m.username === person.username)) return;
  await updateMember(person, 'user', []);
}
</script>

<style scoped>
.unit-tree { max-height: 65vh; overflow-y: auto; }
.members-table :deep(th), .members-table :deep(td) { white-space: nowrap; padding: 8px 12px; }
.members-table :deep(.v-select) { min-width: 180px; }
</style>
