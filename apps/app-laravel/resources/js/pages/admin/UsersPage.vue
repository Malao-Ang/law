<template>
  <AppShell :breadcrumbs="[{ title: 'หน้าแรก', to: '/admin' }, { title: 'จัดการผู้ใช้' }]" title="จัดการผู้ใช้" subtitle="ผู้ใช้ที่เคยเข้าสู่ระบบ หน่วยงาน และสิทธิ์ดำเนินการ">
    <v-card border class="pa-4">
      <v-form class="users-filters mb-4" @submit.prevent="search">
        <v-text-field v-model="filters.q" label="ค้นหาชื่อหรือชื่อผู้ใช้" maxlength="200" prepend-inner-icon="mdi-magnify" clearable hide-details />
        <v-select v-model="filters.unit" label="หน่วยงาน" :items="units" item-title="name" item-value="code" clearable hide-details />
        <v-select v-model="filters.role" label="บทบาท" :items="allRoles" clearable hide-details />
        <v-select v-model="filters.status" label="สถานะ" :items="statuses" clearable hide-details />
        <v-btn type="submit" color="primary">ค้นหา</v-btn>
      </v-form>
      <div style="height:4px"><v-progress-linear v-if="loading" indeterminate color="primary" height="3" /></div>
      <v-table class="users-table" aria-label="ผู้ใช้ในระบบ" :aria-busy="loading">
        <thead><tr><th>ชื่อ</th><th>ชื่อผู้ใช้</th><th>หน่วยงานและบทบาท</th><th>สถานะ</th><th>Super Admin</th><th>เข้าสู่ระบบล่าสุด</th></tr></thead>
        <tbody>
          <tr v-for="user in items" :key="user.username" class="user-row" tabindex="0" @click="router.push(userLink(user.username))" @keydown.enter.self="router.push(userLink(user.username))">
            <td class="user-name-cell"><router-link :to="userLink(user.username)" :title="user.name || user.username" @click.stop>{{ user.name || user.username }}</router-link></td>
            <td>{{ user.username }}</td>
            <td><div class="d-flex flex-wrap ga-1 py-2"><v-chip v-for="membership in user.memberships.slice(0, MAX_CHIPS)" :key="membership.unit_code" size="small" variant="tonal" color="primary">{{ membership.unit_name }} · {{ ROLE_LABELS[membership.role] }}</v-chip><v-chip v-if="user.memberships.length > MAX_CHIPS" size="small" variant="outlined" :title="user.memberships.slice(MAX_CHIPS).map(m => `${m.unit_name} · ${ROLE_LABELS[m.role]}`).join(', ')">+{{ user.memberships.length - MAX_CHIPS }} ...</v-chip><span v-if="!user.memberships.length">ผู้ใช้ทั่วไป</span></div></td>
            <td><v-chip size="small" :class="user.is_disabled ? 'master-status--inactive' : 'master-status--active'">{{ user.is_disabled ? 'ปิดใช้งาน' : 'ใช้งาน' }}</v-chip></td>
            <td class="text-center"><v-icon v-if="user.is_super_admin" icon="mdi-shield-crown-outline" color="primary" aria-label="Super Admin จากการตั้งค่าระบบ" /><span v-else>—</span></td>
            <td>{{ formatThaiDateTime(user.last_login_at) || '—' }}</td>
          </tr>
          <tr v-if="!loading && !items.length"><td colspan="6" class="text-center pa-6">ไม่พบผู้ใช้</td></tr>
        </tbody>
      </v-table>
      <div class="d-flex flex-wrap align-center ga-4 mt-4">
        <span>ทั้งหมด {{ total }} คน</span>
        <v-select :model-value="perPage" :items="[10, 25, 50, 100]" label="รายการต่อหน้า" hide-details density="compact" style="max-width:160px" @update:model-value="changePerPage" />
        <v-pagination :model-value="page" :length="lastPage" :total-visible="5" @update:model-value="changePage" />
      </div>
    </v-card>
    <v-dialog :model-value="Boolean(username)" max-width="1100" persistent :retain-focus="false">
      <v-card class="user-detail" :aria-busy="detailLoading || saving">
        <v-card-title class="d-flex align-center ga-2"><span>{{ detail?.user.name || username }}</span><v-spacer /><v-btn icon="mdi-close" variant="text" :disabled="saving" aria-label="ปิดรายละเอียดผู้ใช้" @click="closeDetail" /></v-card-title>
        <v-divider />
        <div class="user-detail__progress"><v-progress-linear v-if="detailLoading" indeterminate color="primary" height="3" /></div>
        <v-card-text v-if="detail">
          <v-chip size="small" :class="detail.user.is_disabled ? 'master-status--inactive' : 'master-status--active'" class="mb-3 me-2">{{ detail.user.is_disabled ? 'ปิดใช้งาน' : 'ใช้งาน' }}</v-chip>
          <v-chip v-if="detail.user.is_super_admin" color="primary" variant="tonal" class="mb-3">ผู้ดูแลระบบสูงสุด (จากการตั้งค่าระบบ)</v-chip>
          <dl class="user-profile mb-5">
            <dt>ชื่อผู้ใช้</dt><dd>{{ detail.user.username }}</dd><dt>อีเมล</dt><dd>{{ detail.user.email || '—' }}</dd>
            <dt>คณะ</dt><dd>{{ detail.user.fac_name || '—' }}</dd><dt>ฝ่าย</dt><dd>{{ detail.user.dep_name || '—' }}</dd>
            <dt>ตำแหน่ง</dt><dd>{{ detail.user.position || '—' }}</dd><dt>เข้าสู่ระบบล่าสุด</dt><dd>{{ formatThaiDateTime(detail.user.last_login_at) || '—' }}</dd>
          </dl>
          <h2 class="text-subtitle-1 font-weight-bold mb-3">หน่วยงานและสิทธิ์</h2>
          <p class="text-medium-emphasis mb-3">{{ auth.role === 'outer_admin' ? 'ดูข้อมูลได้อย่างเดียว' : 'สิทธิ์แก้ไข ลบ ส่งลงนาม และเผยแพร่จะมีผลเมื่อมีสิทธิ์อัปโหลด' }}</p>
          <v-table class="members-table">
            <thead><tr><th>หน่วยงาน</th><th>บทบาท</th><th v-for="action in actions" :key="action.value">{{ action.title }}</th><th>จัดการ</th></tr></thead>
            <tbody><tr v-for="membership in memberships" :key="membership.unit_code">
              <td>{{ membership.unit_name || membership.unit_code }}</td>
              <td><v-select v-if="canEdit(membership)" v-model="membership.role" :items="assignableRoles" density="compact" hide-details :disabled="saving || detailLoading" :aria-label="`บทบาทใน ${membership.unit_name}`" @update:model-value="normalizeActions(membership)" /><span v-else>{{ ROLE_LABELS[membership.role] }}</span></td>
              <td v-for="action in actions" :key="action.value"><v-checkbox-btn v-model="membership.actions" :value="action.value" :aria-label="`${action.title} ${membership.unit_name}`" :disabled="saving || detailLoading || !canEdit(membership) || membership.role !== 'editor'" /></td>
              <td><div class="d-flex ga-1"><v-btn v-if="canEdit(membership)" size="small" variant="tonal" :disabled="saving || detailLoading || !changed(membership)" @click="saveMembership(membership)">บันทึก</v-btn><v-btn v-if="auth.is_super_admin" size="small" color="error" variant="text" :disabled="saving || detailLoading" @click="removeMembership(membership)">ถอดออก</v-btn><span v-if="!canEdit(membership) && !auth.is_super_admin">—</span></div></td>
            </tr><tr v-if="!memberships.length"><td colspan="8" class="text-center pa-4">ยังไม่มีสมาชิกหน่วยงาน</td></tr></tbody>
          </v-table>
          <v-card v-if="auth.is_super_admin" border class="pa-4 mt-4">
            <div class="d-flex flex-wrap ga-3 align-center">
              <v-select v-model="assignment.unit_code" :items="availableUnits" item-title="name" item-value="code" label="เพิ่มเข้าหน่วยงาน" hide-details :disabled="saving" />
              <v-select v-model="assignment.role" :items="assignableRoles" label="บทบาท" hide-details :disabled="saving" @update:model-value="normalizeActions(assignment)" />
              <v-btn color="primary" :disabled="saving || !assignment.unit_code" @click="addMembership">เพิ่มเข้าหน่วยงาน</v-btn>
            </div>
            <div class="d-flex flex-wrap mt-2"><v-checkbox v-for="action in actions" :key="action.value" v-model="assignment.actions" :value="action.value" :label="action.title" :disabled="saving || assignment.role !== 'editor'" hide-details /></div>
          </v-card>
          <h2 class="text-subtitle-1 font-weight-bold mt-5 mb-2">เอกสารที่รับผิดชอบ ({{ detail.document_count }})</h2>
          <v-list v-if="detail.documents.length"><v-list-item v-for="document in detail.documents" :key="document.document_id" :to="`/documents/${encodeURIComponent(document.document_id)}/law-info`" :title="document.title || document.document_id" prepend-icon="mdi-file-document-outline" /></v-list>
          <p v-else class="text-medium-emphasis">ไม่มีเอกสารที่รับผิดชอบในขอบเขตที่ดูได้</p>
        </v-card-text>
        <v-card-actions><v-btn v-if="canDisable" :color="detail?.user.is_disabled ? 'success' : 'error'" :disabled="saving || detailLoading" @click="toggleDisabled">{{ detail?.user.is_disabled ? 'เปิดการใช้งานผู้ใช้' : 'ปิดการใช้งานผู้ใช้' }}</v-btn><v-spacer /><v-btn variant="outlined" :disabled="saving" @click="closeDetail">ปิด</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter, onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import Swal from 'sweetalert2';
import AppShell from '../../components/shared/AppShell.vue';
import { alertError } from '../../components/master/masterDialogs';
import { jsonRequest, type ApiRequestError } from '../../api/client';
import { useAuthStore, ROLE_LABELS } from '../../stores/authStore';
import { formatThaiDateTime } from '../../utils/thaiDate';
import type { Membership, Unit, UnitAction, UnitRole } from '../../types/unit';
import type { AuthRole } from '../../types/auth';
interface ManagedUser { username: string; name: string | null; email?: string | null; position?: string | null; fac_name?: string | null; dep_name?: string | null; is_super_admin: boolean; is_disabled: boolean; last_login_at: string; memberships: Membership[]; }
interface UserPage { items: ManagedUser[]; units: Unit[]; total: number; page: number; per_page: number; last_page: number; }
interface UserDetail { user: ManagedUser; documents: { document_id: string; title: string }[]; document_count: number; }
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const items = ref<ManagedUser[]>([]);
const units = ref<Unit[]>([]);
const total = ref(0);
const page = ref(1);
const perPage = ref(25);
const lastPage = ref(1);
const loading = ref(false);
const detailLoading = ref(false);
const saving = ref(false);
const detail = ref<UserDetail | null>(null);
const MAX_CHIPS = 4;
const memberships = ref<Membership[]>([]);
const filters = reactive({ q: '', unit: null as string | null, role: null as AuthRole | null, status: null as string | null });
const assignment = reactive({ unit_code: '', role: 'user' as UnitRole, actions: [] as UnitAction[] });
const allRoles = Object.entries(ROLE_LABELS).map(([value, title]) => ({ value, title }));
const assignableRoles = computed(() => allRoles.filter(r => auth.is_super_admin ? r.value !== 'super_admin' : ['user', 'editor'].includes(r.value)));
const statuses = [{ title: 'ใช้งาน', value: 'active' }, { title: 'ปิดใช้งาน', value: 'disabled' }];
const actions: { value: UnitAction; title: string }[] = [{ value: 'upload', title: 'อัปโหลด' }, { value: 'edit', title: 'แก้ไข' }, { value: 'delete', title: 'ลบ' }, { value: 'esign', title: 'ส่งลงนาม' }, { value: 'publish', title: 'เผยแพร่' }];
const username = computed(() => String(route.params.username || ''));
const availableUnits = computed(() => units.value.filter(u => u.is_active && !memberships.value.some(m => m.unit_code === u.code)));
let disposed = false;
const canDisable = computed(() => auth.is_super_admin && detail.value && !detail.value.user.is_super_admin && detail.value.user.username !== auth.user?.username);
const signature = (m: Pick<Membership, 'role' | 'actions'>) => JSON.stringify([m.role, [...m.actions].sort()]);
const assignmentDirty = computed(() => Boolean(assignment.unit_code));
const dirty = computed(() => memberships.value.some(changed) || assignmentDirty.value);
function original(m: Membership): Membership | undefined { return detail.value?.user.memberships.find(row => row.unit_code === m.unit_code); }
function changed(m: Membership): boolean { const saved = original(m); return Boolean(saved && signature(saved) !== signature(m)); }
function canEdit(m: Membership): boolean { const saved = original(m); return auth.is_super_admin || (auth.role === 'inner_admin' && auth.active_unit?.code === m.unit_code && Boolean(saved && ['user', 'editor'].includes(saved.role))); }
function normalizeActions(m: { role: UnitRole; actions: UnitAction[] }): void { if (m.role === 'user') m.actions = []; else if (['inner_admin', 'outer_admin'].includes(m.role)) m.actions = actions.map(a => a.value); }
function userLink(name: string) { return { path: `/admin/users/${encodeURIComponent(name)}`, query: route.query }; }
async function errorDialog(error: unknown): Promise<void> {
  if ((error as ApiRequestError)?.status === 403) return;
  await alertError(error instanceof Error && /[ก-๙]/.test(error.message) ? error.message : 'ไม่สามารถดำเนินการได้ กรุณาลองอีกครั้ง');
}
async function confirm(text: string): Promise<boolean> { return (await Swal.fire({ icon: 'warning', titleText: 'ยืนยันการเปลี่ยนแปลง', text, showCancelButton: true, confirmButtonText: 'ยืนยัน', cancelButtonText: 'ยกเลิก' })).isConfirmed; }
let controller: AbortController | null = null;
let detailRequest = 0;
async function load(): Promise<void> {
  controller?.abort(); const current = new AbortController(); controller = current; loading.value = true;
  Object.assign(filters, { q: String(route.query.q || ''), unit: route.query.unit ? String(route.query.unit) : null, role: route.query.role ? String(route.query.role) : null, status: route.query.status ? String(route.query.status) : null });
  const query = new URLSearchParams(); for (const key of ['q', 'unit', 'role', 'status', 'page', 'per_page']) if (route.query[key]) query.set(key, String(route.query[key]));
  try {
    const response = await jsonRequest<UserPage>(`/api/users?${query}`, { signal: current.signal });
    if (controller !== current) return;
    items.value = response.items; units.value = response.units; total.value = response.total; page.value = response.page; perPage.value = response.per_page; lastPage.value = response.last_page;
  } catch (error) { if (!current.signal.aborted) await errorDialog(error); }
  finally { if (controller === current) loading.value = false; }
}
async function loadDetail(savedUnit?: string): Promise<void> {
  const pendingDrafts = savedUnit === undefined ? [] : memberships.value.filter(m => m.unit_code !== savedUnit && changed(m)).map(m => ({ ...m, actions: [...m.actions] }));
  const current = ++detailRequest;
  const target = username.value;
  const refreshing = savedUnit !== undefined && detail.value?.user.username === target;
  if (!refreshing) { detail.value = null; memberships.value = []; }
  if (!refreshing || assignment.unit_code === savedUnit) { assignment.unit_code = ''; assignment.role = 'user'; assignment.actions = []; }
  if (!target) { detailLoading.value = false; return; }
  detailLoading.value = true;
  try {
    const response = await jsonRequest<UserDetail>(`/api/users/${encodeURIComponent(target)}`);
    if (current !== detailRequest || disposed) return;
    detail.value = response;
    memberships.value = response.user.memberships.map(m => pendingDrafts.find(draft => draft.unit_code === m.unit_code) ?? ({ ...m, actions: [...m.actions] }));
  } catch (error) {
    if (current !== detailRequest || disposed) return;
    await errorDialog(error);
    if (!refreshing && current === detailRequest && !disposed) await router.replace({ path: '/admin/users', query: route.query });
  } finally { if (current === detailRequest) detailLoading.value = false; }
}
async function search(): Promise<void> { await router.replace({ query: { q: filters.q || undefined, unit: filters.unit || undefined, role: filters.role || undefined, status: filters.status || undefined, per_page: perPage.value === 25 ? undefined : String(perPage.value) } }); }
async function changePage(value: number): Promise<void> { await router.replace({ query: { ...route.query, page: value > 1 ? String(value) : undefined } }); }
async function changePerPage(value: number): Promise<void> { await router.replace({ query: { ...route.query, page: undefined, per_page: String(value) } }); }
async function closeDetail(): Promise<void> { await router.push({ path: '/admin/users', query: route.query }); }
async function writeMembership(code: string, role: UnitRole, grants: UnitAction[]): Promise<void> {
  if (!username.value || detail.value?.user.username !== username.value || !code || !assignableRoles.value.some(r => r.value === role) || grants.some(a => !actions.some(row => row.value === a)) || new Set(grants).size !== grants.length) { await alertError('กรุณาเลือกหน่วยงาน บทบาท และสิทธิ์ให้ถูกต้อง'); return; }
  const effectiveGrants = role === 'user' ? [] : role === 'editor' ? [...grants] : actions.map(a => a.value);
  saving.value = true;
  try { await jsonRequest(`/api/units/${encodeURIComponent(code)}/members/${encodeURIComponent(username.value)}`, { method: 'PUT', body: JSON.stringify({ role, actions: effectiveGrants }) }); await loadDetail(code); await load(); await auth.fetchMe(true); }
  catch (error) { await errorDialog(error); } finally { saving.value = false; }
}
async function saveMembership(m: Membership): Promise<void> { if (saving.value || detailLoading.value || !canEdit(m) || !changed(m)) return; await writeMembership(m.unit_code, m.role, m.actions); }
async function addMembership(): Promise<void> { if (saving.value || detailLoading.value || !auth.is_super_admin || !availableUnits.value.some(u => u.code === assignment.unit_code)) return; await writeMembership(assignment.unit_code, assignment.role, assignment.actions); }
async function removeMembership(m: Membership): Promise<void> {
  if (saving.value || detailLoading.value || !auth.is_super_admin) return;
  const target = username.value;
  if (!await confirm(`ถอดผู้ใช้ออกจาก ${m.unit_name || m.unit_code} หรือไม่`) || disposed || target !== username.value || saving.value || !auth.is_super_admin) return;
  saving.value = true; try { await jsonRequest(`/api/units/${encodeURIComponent(m.unit_code)}/members/${encodeURIComponent(username.value)}`, { method: 'DELETE' }); await loadDetail(m.unit_code); await load(); await auth.fetchMe(true); } catch (error) { await errorDialog(error); } finally { saving.value = false; }
}
async function toggleDisabled(): Promise<void> {
  if (saving.value || detailLoading.value || !canDisable.value || !detail.value) return;
  const target = username.value;
  if (!await confirm(`${detail.value.user.is_disabled ? 'เปิด' : 'ปิด'}การใช้งาน ${detail.value.user.name || target} หรือไม่`) || disposed || target !== username.value || saving.value || !canDisable.value || !detail.value) return;
  saving.value = true; try { await jsonRequest(`/api/users/${encodeURIComponent(username.value)}/disabled`, { method: 'PATCH', body: JSON.stringify({ is_disabled: !detail.value.user.is_disabled }) }); await loadDetail(''); await load(); } catch (error) { await errorDialog(error); } finally { saving.value = false; }
}
watch(() => route.query, () => { void load(); }, { immediate: true });
watch(username, () => { void loadDetail(); }, { immediate: true });
onBeforeRouteUpdate(async (to, from) => { if (to.params.username === from.params.username) return true; if (saving.value) return false; return !dirty.value || await confirm('มีสิทธิ์ที่ยังไม่ได้บันทึก ต้องการเปลี่ยนผู้ใช้หรือไม่'); });
onBeforeRouteLeave(async () => { if (saving.value) return false; return !dirty.value || await confirm('มีสิทธิ์ที่ยังไม่ได้บันทึก ต้องการออกจากหน้านี้หรือไม่'); });
function beforeUnload(event: BeforeUnloadEvent): void { if (dirty.value || saving.value) { event.preventDefault(); event.returnValue = ''; } }
onMounted(() => window.addEventListener('beforeunload', beforeUnload));
onBeforeUnmount(() => { disposed = true; controller?.abort(); detailRequest++; window.removeEventListener('beforeunload', beforeUnload); });
</script>

<style scoped>
.user-name-cell { max-width: 80ch; min-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-name-cell a { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; vertical-align: bottom; }
.users-filters { display: grid; grid-template-columns: minmax(180px, 2fr) minmax(140px, 1.5fr) minmax(120px, 1fr) minmax(120px, 1fr) auto; gap: 12px; align-items: center; }
.user-row { cursor: pointer; }
.user-row:hover { background: #f8fafc; }
.user-row:focus-visible { outline: 2px solid #1e3a8a; outline-offset: -2px; }
.users-table a { color: #1e3a8a; font-weight: 600; }
.user-profile { display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 8px 16px; }
.user-profile dt { color: #64748b; }.user-profile dd { margin: 0; overflow-wrap: anywhere; }
.user-detail__progress { height: 3px; }
.user-detail .members-table { overflow-x: auto; }.user-detail .v-select { min-width: 130px; }
@media (max-width: 900px) { .users-filters { grid-template-columns: 1fr 1fr; }.user-profile { grid-template-columns: 110px minmax(0, 1fr); } }
@media (max-width: 500px) { .users-filters { grid-template-columns: 1fr; } }
</style>
