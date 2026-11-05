<template>
  <AppShell :breadcrumbs="[{ title: 'หน้าแรก', to: '/admin' }, { title: 'จัดการหน่วยงาน', to: '/admin/units' }, { title: selectedUnit?.name || 'สมาชิกหน่วยงาน' }]" :title="selectedUnit?.name || 'สมาชิกหน่วยงาน'" subtitle="จัดการสมาชิกและสิทธิ์ดำเนินการ">
    <template #title-actions><v-btn variant="outlined" prepend-icon="mdi-arrow-left" :disabled="saving" to="/admin/units">กลับไปตารางหน่วยงาน</v-btn></template>
    <v-autocomplete :key="selectorRevision" :model-value="code" :items="visibleUnits" item-title="name" item-value="code" no-data-text="ไม่พบหน่วยงาน" label="เลือกหน่วยงาน" variant="outlined" :disabled="saving" @update:model-value="switchUnit" />
    <v-card v-if="selectedUnit" flat border rounded="lg" class="mb-4">
      <v-card-title class="d-flex align-center ga-3 flex-wrap"><span>สมาชิกหน่วยงาน</span><v-spacer /><v-btn v-if="auth.is_super_admin" color="admin-primary" :disabled="saving" @click="openPeople">เพิ่มคน</v-btn></v-card-title>
      <v-card-text>
        <p v-if="selectedUnit.missing_in_ums" class="text-warning">ไม่พบหน่วยงานนี้ในการดึงข้อมูล UMS ครั้งล่าสุด</p>
        <p>{{ canWrite ? 'แก้ไขสิทธิ์ในรายการ แล้วกดปุ่ม บันทึก เพื่อบันทึกการเปลี่ยนแปลง' : 'ดูข้อมูลสมาชิกและสิทธิ์ได้อย่างเดียว' }}</p>
        <p class="text-warning mt-1">หากไม่มีสิทธิ์อัปโหลด สิทธิ์ช่องอื่นจะไม่มีผล</p>
        <div v-if="canWrite" class="d-flex align-center ga-3 mt-3">
          <span>เลือก {{ selected.length }} คน</span><v-btn variant="tonal" :disabled="saving || !selected.length" @click="openPermissions">กำหนดสิทธิ์ให้ที่เลือก</v-btn>
        </div>
      </v-card-text>
      <v-progress-linear v-if="loading || saving" indeterminate color="admin-primary" />
      <v-table class="members-table" aria-label="สมาชิกหน่วยงาน">
        <thead><tr><th v-if="canWrite"><v-checkbox-btn :model-value="allSelected" :indeterminate="selected.length > 0 && !allSelected" :disabled="saving || !editableRows.length" aria-label="เลือกสมาชิกทั้งหมด" @update:model-value="selectAll(Boolean($event))" /></th><th>ชื่อ</th><th>ชื่อผู้ใช้</th><th>บทบาท</th><th v-for="action in actions" :key="action.value">{{ action.title }}</th><th>ที่มา</th><th>จัดการ</th></tr></thead>
        <tbody>
          <tr v-for="member in draft" :key="member.username" :data-username="member.username" :class="{ 'draft-row': state(member), 'removed-row': member.remove }">
            <td v-if="canWrite"><v-checkbox-btn v-model="selected" :value="member.username" :disabled="saving || member.remove || !canEdit(member)" :aria-label="`เลือก ${member.username}`" /></td>
            <td>{{ member.name || '—' }}<span v-if="member.is_disabled" class="text-error"> (ปิดใช้งาน)</span><v-chip v-if="state(member)" size="small" :color="member.remove ? 'error' : 'warning'" class="ml-2">{{ state(member) }}</v-chip></td>
            <td>{{ member.username }}</td>
            <td><v-select v-if="canEdit(member)" :model-value="member.role" :items="roleOptions" :disabled="saving || member.remove" :aria-label="`บทบาทของ ${member.username}`" variant="outlined" density="compact" hide-details @update:model-value="changeRole(member, $event)" /><span v-else>{{ ROLE_LABELS[member.role] }}</span></td>
            <td v-for="action in actions" :key="action.value"><v-checkbox-btn :model-value="member.actions.includes(action.value)" :disabled="saving || member.remove || !canEdit(member) || isAdmin(member.role)" :aria-label="`${action.title} ${member.username}`" @update:model-value="changeAction(member, action.value, Boolean($event))" /></td>
            <td>{{ sourceLabel(member.source) }}</td>
            <td><v-btn v-if="auth.is_super_admin" variant="text" size="small" :color="member.remove ? 'primary' : 'error'" :disabled="saving" @click="toggleRemove(member)">{{ member.remove ? 'เลิกทำ' : 'ลบสมาชิก' }}</v-btn></td>
          </tr>
          <tr v-if="!loading && !draft.length"><td colspan="11" class="text-center pa-6">ยังไม่มีสมาชิก</td></tr>
        </tbody>
      </v-table>
    </v-card>
    <div v-if="canWrite && changed.length" class="draft-bar d-flex align-center ga-3 pa-4" data-testid="draft-bar">
      <strong>มีการเปลี่ยนแปลง {{ changed.length }} คน</strong><v-spacer /><v-btn :disabled="saving" variant="text" @click="revert">ยกเลิก</v-btn><v-btn color="admin-primary" :loading="saving" @click="save">บันทึก</v-btn>
    </div>
    <v-dialog v-model="peopleDialog" max-width="800" :retain-focus="false" persistent>
      <v-card rounded="lg"><v-card-title>เพิ่มคนใน {{ selectedUnit?.name }}</v-card-title><v-card-text>
        <v-form class="d-flex ga-2" @submit.prevent="searchPeople"><v-text-field v-model="peopleQuery" label="ค้นหาชื่อ นามสกุล หรือชื่อผู้ใช้" variant="outlined" density="compact" hide-details :disabled="saving" /><v-btn type="submit" color="admin-primary" :loading="searching" :disabled="saving">ค้นหา</v-btn></v-form>
        <v-list class="people-results"><v-list-item v-for="person in people" :key="person.username" :title="person.name || person.username" :subtitle="person.username"><template #prepend><v-checkbox-btn v-model="picked" :value="person.username" :disabled="saving || draft.some((m) => m.username === person.username)" :aria-label="`เพิ่ม ${person.username}`" /></template></v-list-item></v-list>
        <p v-if="searched && !people.length && !searching">ไม่พบผู้ใช้ที่ตรงกับคำค้นหา</p>
        <p class="mb-3">เลือก {{ picked.length }} คน</p>
        <v-select v-model="assignment.role" label="บทบาท" :items="roleOptions" :disabled="saving" variant="outlined" />
        <div class="d-flex ga-2 flex-wrap"><v-checkbox v-for="action in actions" :key="action.value" v-model="assignment.actions" :value="action.value" :label="action.title" :disabled="saving || assignment.role === 'user' || isAdmin(assignment.role)" hide-details /></div>
      </v-card-text><v-card-actions><v-spacer /><v-btn :disabled="saving" @click="peopleDialog = false">ปิด</v-btn><v-btn color="admin-primary" :disabled="saving || !picked.length" @click="addPeople">เพิ่มเข้ารายการ</v-btn></v-card-actions></v-card>
    </v-dialog>
    <v-dialog v-model="permissionsDialog" max-width="650" :retain-focus="false" persistent>
      <v-card><v-card-title>กำหนดสิทธิ์ให้ที่เลือก {{ selected.length }} คน</v-card-title><v-card-text>
        <v-select v-model="assignment.role" label="บทบาท" :items="roleOptions" :disabled="saving" variant="outlined" />
        <div class="d-flex ga-2 flex-wrap"><v-checkbox v-for="action in actions" :key="action.value" v-model="assignment.actions" :value="action.value" :label="action.title" :disabled="saving || assignment.role === 'user' || isAdmin(assignment.role)" hide-details /></div>
      </v-card-text><v-card-actions><v-spacer /><v-btn :disabled="saving" @click="permissionsDialog = false">ยกเลิก</v-btn><v-btn color="admin-primary" :disabled="saving" @click="applyPermissions">ใช้กับรายการ</v-btn></v-card-actions></v-card>
    </v-dialog>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router';
import Swal from 'sweetalert2';
import AppShell from '../../components/shared/AppShell.vue';
import { alertError } from '../../components/master/masterDialogs';
import { jsonRequest } from '../../api/client';
import { useAuthStore, ROLE_LABELS } from '../../stores/authStore';
import type { Membership, Unit, UnitAction, UnitRole } from '../../types/unit';
interface Member extends Membership { username: string; name: string | null; is_disabled: boolean; remove?: boolean; }
interface Person { username: string; name: string | null; }
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const code = computed(() => String(route.params.code));
const units = ref<Unit[]>([]);
const baseline = ref<Member[]>([]);
const draft = ref<Member[]>([]);
const selected = ref<string[]>([]);
const loading = ref(false);
const saving = ref(false);
const selectorRevision = ref(0);
const peopleDialog = ref(false);
const permissionsDialog = ref(false);
const peopleQuery = ref('');
const people = ref<Person[]>([]);
const picked = ref<string[]>([]);
const searching = ref(false);
const searched = ref(false);
const assignment = reactive({ role: 'user' as UnitRole, actions: [] as UnitAction[] });
const actions: { title: string; value: UnitAction }[] = [
  { title: 'อัปโหลด', value: 'upload' }, { title: 'แก้ไข', value: 'edit' }, { title: 'ลบ', value: 'delete' },
  { title: 'ส่งลงนาม', value: 'esign' }, { title: 'เผยแพร่', value: 'publish' },
];
function isAdmin(role: string): boolean { return ['inner_admin', 'outer_admin'].includes(role); }
const visibleUnits = computed(() => units.value.filter((u) => auth.is_super_admin || auth.memberships.some((m) => m.unit_code === u.code && isAdmin(m.role))));
const selectedUnit = computed(() => visibleUnits.value.find((u) => u.code === code.value));
const ownRole = computed(() => auth.memberships.find((m) => m.unit_code === code.value)?.role);
const canWrite = computed(() => auth.is_super_admin || ownRole.value === 'inner_admin');
const roleOptions = computed(() => (['user', 'editor', ...(auth.is_super_admin ? ['inner_admin', 'outer_admin'] : [])] as UnitRole[]).map((role) => ({ title: ROLE_LABELS[role], value: role })));
function canEdit(member: Member): boolean {
  const original = baseline.value.find((m) => m.username === member.username);
  return auth.is_super_admin || (ownRole.value === 'inner_admin' && Boolean(original) && ['user', 'editor'].includes(original!.role));
}
function signature(member: Member): string { return JSON.stringify([member.role, [...member.actions].sort()]); }
function state(member: Member): string {
  if (member.remove) return 'จะลบ';
  const original = baseline.value.find((m) => m.username === member.username);
  return !original ? 'ใหม่' : signature(original) !== signature(member) ? 'แก้ไข' : '';
}
const changed = computed(() => draft.value.filter((m) => state(m)));
const editableRows = computed(() => draft.value.filter((m) => canEdit(m) && !m.remove));
const allSelected = computed(() => editableRows.value.length > 0 && editableRows.value.every((m) => selected.value.includes(m.username)));
function selectAll(checked: boolean): void { selected.value = checked ? editableRows.value.map((m) => m.username) : []; }
function sourceLabel(source: string): string { return ({ ums: 'UMS', manual: 'เพิ่มโดยผู้ดูแล', seed: 'ข้อมูลเริ่มต้น' } as Record<string, string>)[source] || 'ไม่ระบุ'; }
function replaceMembers(members: Member[]): void { baseline.value = structuredClone(members); revert(); }
function revert(): void { draft.value = baseline.value.map((m) => ({ ...m, actions: [...m.actions] })); selected.value = []; }
function changeRole(member: Member, role: UnitRole): void {
  if (saving.value || !canEdit(member) || member.remove) return;
  const previous = member.role;
  member.role = role;
  member.actions = isAdmin(role) ? actions.map((a) => a.value) : role === 'user' ? [] : previous === 'user' ? ['upload', 'edit'] : member.actions;
}
function changeAction(member: Member, action: UnitAction, checked: boolean): void {
  if (saving.value || !canEdit(member) || member.remove || isAdmin(member.role)) return;
  member.actions = member.actions.filter((a) => a !== action);
  if (checked) { member.actions.push(action); if (member.role === 'user') member.role = 'editor'; }
}
function toggleRemove(member: Member): void {
  if (saving.value || !auth.is_super_admin) return;
  if (!baseline.value.some((m) => m.username === member.username)) draft.value = draft.value.filter((m) => m !== member);
  else member.remove = !member.remove;
  selected.value = selected.value.filter((u) => u !== member.username);
}
function openPermissions(): void { assignment.role = 'user'; assignment.actions = []; permissionsDialog.value = true; }
function assignedActions(): UnitAction[] { return isAdmin(assignment.role) ? actions.map((a) => a.value) : assignment.role === 'user' ? [] : [...assignment.actions]; }
function applyPermissions(): void {
  for (const member of editableRows.value.filter((m) => selected.value.includes(m.username))) { member.role = assignment.role; member.actions = assignedActions(); }
  permissionsDialog.value = false;
}
async function showError(error: unknown): Promise<void> {
  await alertError(error instanceof Error && /[ก-๙]/.test(error.message) ? error.message : 'ไม่สามารถดำเนินการได้ กรุณาลองอีกครั้ง');
}
let request = 0;
async function loadMembers(): Promise<void> {
  const seq = ++request;
  loading.value = true;
  baseline.value = []; draft.value = []; selected.value = [];
  try {
    if (!selectedUnit.value) throw new Error('ไม่พบหน่วยงานหรือไม่มีสิทธิ์ดูหน่วยงานนี้');
    const result = await jsonRequest<{ items: Member[] }>(`/api/units/${encodeURIComponent(code.value)}/members`);
    if (seq === request) replaceMembers(result.items);
  } catch (error) { if (seq === request) await showError(error); }
  finally { if (seq === request) loading.value = false; }
}
async function save(): Promise<void> {
  if (saving.value || !canWrite.value || !changed.value.length) return;
  saving.value = true;
  try {
    const changes = changed.value.map((m) => m.remove ? { username: m.username, remove: true } : { username: m.username, role: m.role, actions: m.actions });
    const result = await jsonRequest<{ items: Member[] }>(`/api/units/${encodeURIComponent(code.value)}/members`, { method: 'PUT', body: JSON.stringify({ changes }) });
    replaceMembers(result.items);
    await auth.fetchMe(true);
  } catch (error) { await showError(error); }
  finally { saving.value = false; }
}
async function mayLeave(): Promise<boolean> {
  if (saving.value) return false;
  if (!changed.value.length) return true;
  return (await Swal.fire({ icon: 'warning', titleText: 'มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก', text: 'ต้องการออกจากหน้านี้และทิ้งการเปลี่ยนแปลงหรือไม่?', showCancelButton: true, confirmButtonText: 'ทิ้งการเปลี่ยนแปลง', cancelButtonText: 'แก้ไขต่อ' })).isConfirmed;
}
async function switchUnit(next: string | null): Promise<void> {
  if (next && next !== code.value) await router.push(`/admin/units/${encodeURIComponent(next)}`);
  selectorRevision.value++;
}
onBeforeRouteLeave(mayLeave);
onBeforeRouteUpdate(async (to, from) => to.params.code === from.params.code || await mayLeave());
function beforeUnload(event: BeforeUnloadEvent): void { if (changed.value.length) { event.preventDefault(); event.returnValue = ''; } }
onMounted(async () => {
  window.addEventListener('beforeunload', beforeUnload);
  try { units.value = (await jsonRequest<{ items: Unit[] }>('/api/units')).items; await loadMembers(); }
  catch (error) { await showError(error); }
});
onBeforeUnmount(() => { request++; window.removeEventListener('beforeunload', beforeUnload); });
watch(code, () => { peopleDialog.value = false; permissionsDialog.value = false; void loadMembers(); });
function openPeople(): void { peopleQuery.value = ''; people.value = []; picked.value = []; searched.value = false; assignment.role = 'user'; assignment.actions = []; peopleDialog.value = true; }
async function searchPeople(): Promise<void> {
  if (peopleQuery.value.trim().length < 2) { await alertError('กรุณาระบุคำค้นหาอย่างน้อย 2 ตัวอักษร'); return; }
  searching.value = true;
  try {
    const result = await jsonRequest<{ items: Person[]; ums_unavailable: boolean; message: string | null }>(`/api/users/search?q=${encodeURIComponent(peopleQuery.value.trim())}`);
    // Preserve selections from earlier searches in this dialog.
    const retained = people.value.filter((p) => picked.value.includes(p.username));
    people.value = [...retained, ...result.items.filter((p) => !retained.some((r) => r.username === p.username))]; searched.value = true;
    if (result.ums_unavailable) await Swal.fire({ icon: 'warning', titleText: 'ค้นหาจาก UMS ไม่สำเร็จ', text: result.message || 'แสดงเฉพาะผู้ใช้ที่เคยเข้าสู่ระบบ', confirmButtonText: 'ตกลง' });
  } catch (error) { await showError(error); }
  finally { searching.value = false; }
}
function addPeople(): void {
  if (!auth.is_super_admin || saving.value) return;
  for (const person of people.value.filter((p) => picked.value.includes(p.username))) {
    if (!draft.value.some((m) => m.username === person.username)) draft.value.push({ ...person, unit_code: code.value, role: assignment.role, actions: assignedActions(), source: 'manual', is_disabled: false });
  }
  peopleDialog.value = false;
}
</script>

<style scoped>
.members-table :deep(th), .members-table :deep(td) { white-space: nowrap; padding: 8px 12px; }
.members-table :deep(.v-select) { min-width: 180px; }
.draft-row { background: #fff8e1; box-shadow: inset 4px 0 #e6a000; }
.removed-row { background: #ffebee; box-shadow: inset 4px 0 #b42318; }
.draft-bar { position: sticky; bottom: 16px; z-index: 5; background: white; border: 1px solid #e6a000; border-radius: 8px; box-shadow: 0 4px 20px #0002; }
.people-results { max-height: 320px; overflow-y: auto; }
</style>
