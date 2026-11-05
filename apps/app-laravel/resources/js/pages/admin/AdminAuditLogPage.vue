<template>
  <AppShell :breadcrumbs="['การจัดการข้อมูล', 'ประวัติการใช้งาน']" title="ประวัติการใช้งาน" subtitle="ค้นหาและตรวจสอบประวัติการเปลี่ยนแปลง">
    <template #title-actions>
      <v-btn prepend-icon="mdi-file-excel-outline" color="admin-primary" @click="openExport">ส่งออก Excel</v-btn>
      <v-btn prepend-icon="mdi-refresh" variant="tonal" :disabled="loading" @click="load">รีเฟรช</v-btn>
    </template>
    <v-form class="audit-filters mb-4" @submit.prevent="search">
      <v-select v-model="filters.resource" :items="resources" item-title="label" item-value="value" label="เรื่อง" multiple chips clearable hide-details density="compact" variant="outlined" />
      <v-select v-model="filters.action" :items="actions" item-title="label" item-value="value" label="การกระทำ" clearable hide-details density="compact" variant="outlined" />
      <v-select v-model="filters.unit" :items="units" item-title="name" item-value="code" label="หน่วยงาน" clearable hide-details density="compact" variant="outlined" />
      <v-text-field v-model="filters.actor" label="ผู้ทำ (ชื่อผู้ใช้)" hide-details density="compact" variant="outlined" />
      <v-text-field v-model="filters.from" label="ตั้งแต่วันที่" type="date" hide-details density="compact" variant="outlined" />
      <v-text-field v-model="filters.to" label="ถึงวันที่" type="date" hide-details density="compact" variant="outlined" />
      <v-text-field v-model="filters.q" label="ค้นหา" prepend-inner-icon="mdi-magnify" hide-details density="compact" variant="outlined" />
      <v-btn type="submit" color="admin-primary">ค้นหา</v-btn>
    </v-form>
    <v-alert v-if="error" type="error" class="mb-3">{{ error }}</v-alert>
    <div style="height:4px"><v-progress-linear v-if="loading" indeterminate color="admin-primary" height="3" /></div>
    <v-card flat border rounded="lg">
      <v-table density="comfortable">
        <thead><tr><th>เวลา</th><th>ผู้ทำ</th><th>การกระทำ</th><th>เรื่อง</th><th>รายการ</th><th>สรุป</th><th>ผล</th><th>รายละเอียด</th></tr></thead>
        <tbody>
          <tr v-if="!loading && !items.length"><td colspan="8" class="text-center pa-8">ไม่มีบันทึกที่ตรงกับเงื่อนไข</td></tr>
          <template v-for="item in items" :key="item.id">
            <tr>
              <td class="text-caption text-no-wrap">{{ formatThaiDateTime(item.at) }}</td>
              <td>{{ item.actor_name }}<div class="text-caption">{{ item.actor_username }}</div><div class="text-caption">{{ roleLabel(item.actor_role) }} · {{ unitName(item.unit_code) }}</div></td>
              <td>{{ item.action_label }}</td><td>{{ item.resource_label }}</td>
              <td><RouterLink v-if="item.document_id" :to="`/documents/${encodeURIComponent(item.document_id)}/law-info`">{{ item.label || item.document_id }}</RouterLink><RouterLink v-else-if="item.resource === 'master_data'" :to="masterLink(item)">{{ item.label || item.resource_id }}</RouterLink><span v-else>{{ item.label || item.resource_id }}</span></td>
              <td>{{ item.summary }}</td><td>{{ resultLabel(item.result) }}</td>
              <td><v-btn variant="text" size="small" :aria-expanded="expanded === item.id" @click="expanded = expanded === item.id ? '' : item.id">{{ expanded === item.id ? 'ย่อ' : 'ดู' }}</v-btn></td>
            </tr>
            <tr v-if="expanded === item.id"><td colspan="8" class="pa-4">
              <div>IP: {{ item.ip || '—' }} <span v-if="item.on_behalf_of">· ทำแทน {{ item.on_behalf_of }}</span></div>
              <div v-for="change in item.changes" :key="change">{{ change }}</div>
              <v-table density="compact"><thead><tr><th>ข้อมูล</th><th>ก่อนเปลี่ยน</th><th>หลังเปลี่ยน</th></tr></thead><tbody><tr v-for="field in changeFields(item)" :key="field"><td>{{ field }}</td><td class="audit-value">{{ item.before?.[field] ?? '—' }}</td><td class="audit-value">{{ item.after?.[field] ?? '—' }}</td></tr></tbody></v-table>
            </td></tr>
          </template>
        </tbody>
      </v-table>
    </v-card>
    <div class="d-flex align-center justify-space-between mt-3"><span>ทั้งหมด {{ total }} รายการ</span><v-pagination v-model="page" :length="lastPage" :total-visible="7" @update:model-value="load" /></div>
    <v-dialog v-model="exportDialog" max-width="620">
      <v-card title="ส่งออก Excel">
        <v-card-text>
          <div class="mb-3">เลือกเรื่องที่จะส่งออก (ไม่เลือก = ทุกเรื่อง)</div>
          <v-checkbox v-for="option in resources" :key="option.value" v-model="exportResources" :value="option.value" :label="option.label" hide-details density="compact" />
          <div class="d-flex ga-3 mt-4"><v-text-field v-model="exportFrom" label="ตั้งแต่วันที่" type="date" /><v-text-field v-model="exportTo" label="ถึงวันที่" type="date" /></div>
          <div class="text-caption">ใช้หน่วยงาน ผู้ทำ และคำค้นตามตัวกรองปัจจุบัน</div>
          <v-alert v-if="exportError" type="error">{{ exportError }}</v-alert>
        </v-card-text>
        <v-card-actions><v-spacer /><v-btn @click="exportDialog = false">ยกเลิก</v-btn><v-btn color="admin-primary" :loading="exporting" @click="download">ส่งออก Excel</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </AppShell>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { auditLogQuery, fetchAuditLogs, type AuditLogFilters } from '../../api/client';
import AppShell from '../../components/shared/AppShell.vue';
import type { AuditLogItem, AuditLogOption } from '../../types/auditLog';
import { ROLE_LABELS, type AuthRole } from '../../stores/authStore';
import { formatThaiDateTime } from '../../utils/thaiDate';

const route = useRoute();
const router = useRouter();
const filters = reactive({ resource: [] as string[], action: '', unit: '', actor: '', document: '', q: '', from: '', to: '' });
const page = ref(1);
const items = ref<AuditLogItem[]>([]);
const actions = ref<AuditLogOption[]>([]);
const resources = ref<AuditLogOption[]>([]);
const units = ref<{code: string; name: string}[]>([]);
const total = ref(0);
const lastPage = ref(1);
const loading = ref(false);
const error = ref('');
const expanded = ref('');
const exportDialog = ref(false);
const exportResources = ref<string[]>([]);
const exportFrom = ref('');
const exportTo = ref('');
const exporting = ref(false);
const exportError = ref('');
let generation = 0;
function readQuery(): void {
  for (const key of ['action', 'unit', 'actor', 'document', 'q', 'from', 'to'] as const) filters[key] = String(route.query[key] ?? '');
  const resource = route.query.resource ?? route.query['resource[]'];
  filters.resource = (Array.isArray(resource) ? resource : resource ? [resource] : []).filter((v): v is string => typeof v === 'string');
  page.value = Math.max(1, Number(route.query.page) || 1);
}
function params(): AuditLogFilters { return { ...filters, resource: filters.resource ?? [], page: page.value, per_page: 25 }; }
async function load(): Promise<void> {
  const current = ++generation;
  loading.value = true;
  error.value = '';
  try {
    const result = await fetchAuditLogs(params());
    if (current !== generation) return;
    items.value = result.items; actions.value = result.actions; resources.value = result.resources; units.value = result.units;
    total.value = result.total; lastPage.value = result.last_page;
    const query = Object.fromEntries(Object.entries({ ...filters, page: page.value }).filter(([, v]) => Array.isArray(v) ? v.length : v !== '' && v !== null));
    await router.replace({ query });
  } catch (e) { if (current === generation) error.value = e instanceof Error ? e.message : 'โหลดประวัติไม่สำเร็จ'; }
  finally { if (current === generation) loading.value = false; }
}
function search(): void { page.value = 1; void load(); }
onMounted(() => { readQuery(); void load(); });
watch(() => route.fullPath, () => { if (route.path !== '/admin/audit-logs') return; readQuery(); if (!loading.value) void load(); });
function roleLabel(role: string): string { return ROLE_LABELS[role as AuthRole] || role; }
function unitName(code: string | null): string { return units.value.find((u) => u.code === code)?.name || code || '—'; }
function resultLabel(result: string): string { return result === 'denied' ? 'ปฏิเสธ' : result === 'failed' ? 'ไม่สำเร็จ' : 'สำเร็จ'; }
function changeFields(item: AuditLogItem): string[] { return [...new Set([...Object.keys(item.before || {}), ...Object.keys(item.after || {})])]; }
function masterLink(item: AuditLogItem): string { const kind = (item as AuditLogItem & {kind?: string}).kind || ''; const paths: Record<string, string> = { law_type: 'law-types', law_family: 'law-types', law_category: 'categories', legal_structure: 'legal-structures', change_status: 'change-status', enforcement_status: 'enforcement-status' }; return `/admin/master/${paths[kind] || 'law-types'}`; }
function openExport(): void { exportResources.value = [...(filters.resource ?? [])]; exportFrom.value = filters.from; exportTo.value = filters.to; exportError.value = ''; exportDialog.value = true; }
async function download(): Promise<void> {
  exporting.value = true; exportError.value = '';
  try {
    const response = await fetch(`/api/audit-logs/export?${auditLogQuery({ ...params(), resource: exportResources.value, from: exportFrom.value, to: exportTo.value })}`, { credentials: 'include', headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } });
    if (!response.ok) { const body = await response.json().catch(() => ({})); throw new Error(body.message || 'ส่งออกไม่สำเร็จ'); }
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a'); link.href = url; link.download = 'ประวัติการใช้งาน.xlsx'; link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000); exportDialog.value = false;
  } catch (e) { exportError.value = e instanceof Error ? e.message : 'ส่งออกไม่สำเร็จ'; }
  finally { exporting.value = false; }
}
</script>

<style scoped>
.audit-filters { display: grid; grid-template-columns: repeat(4, minmax(160px, 1fr)); gap: 12px; }
.audit-value { white-space: pre-wrap; overflow-wrap: anywhere; max-width: 420px; }
@media (max-width: 900px) { .audit-filters { grid-template-columns: repeat(2, minmax(140px, 1fr)); } }
</style>
