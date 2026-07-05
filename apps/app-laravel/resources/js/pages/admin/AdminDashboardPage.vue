<template>
  <AppShell :breadcrumbs="['LAWSPACE', 'หน้าแรก']" title="ภาพรวมระบบ">
    <template #actions>
      <v-btn color="primary" prepend-icon="mdi-cloud-upload-outline" @click="router.push('/admin/upload')">
        นำเข้าเอกสาร
      </v-btn>
    </template>

    <v-row class="mb-6">
      <v-col
        v-for="stat in statCards"
        :key="stat.label"
        cols="12"
        sm="6"
        md="2"
      >
        <AdminStatCard
          :icon="stat.icon"
          :icon-color="stat.iconColor"
          :icon-bg="stat.iconBg"
          :number="stat.number"
          :label="stat.label"
        />
      </v-col>
    </v-row>

    <v-row class="mb-6">
      <v-col cols="12" md="6">
        <v-card flat border rounded="lg">
          <v-card-title class="text-subtitle-1 font-weight-bold">ความครบถ้วนของข้อมูล</v-card-title>
          <v-card-text>
            <div
              v-for="item in completeness"
              :key="item.label"
              class="d-flex align-center ga-3 mb-2"
            >
              <span class="text-body-2" style="width:80px;flex-shrink:0">{{ item.label }}</span>
              <v-progress-linear
                :model-value="item.pct"
                :color="item.color"
                height="8"
                rounded
                class="flex-grow-1"
              />
              <span class="text-caption">{{ item.pct }}%</span>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card flat border rounded="lg">
          <v-card-title class="text-subtitle-1 font-weight-bold">รายการเร่งด่วน</v-card-title>
          <v-card-text>
            <v-alert
              v-for="alert in urgentAlerts"
              :key="alert.id"
              :type="alert.level === 'error' ? 'error' : alert.level === 'warning' ? 'warning' : 'info'"
              variant="tonal"
              density="compact"
              :title="alert.title"
              :text="alert.sub"
              class="mb-2"
            />
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Document log table -->
    <v-card flat border rounded="lg">
      <div class="d-flex align-center pa-4 pb-2">
        <span class="text-subtitle-1 font-weight-bold flex-grow-1">บันทึกการนำเข้าเอกสาร</span>
        <v-btn
          size="small"
          color="admin-primary"
          prepend-icon="mdi-cloud-upload-outline"
          @click="router.push('/admin/upload')"
        >นำเข้าเอกสาร</v-btn>
      </div>

      <!-- Filters -->
      <div class="d-flex flex-wrap gap-2 px-4 pb-3 align-center">
        <v-text-field
          v-model="filterName"
          placeholder="ค้นหาชื่อเอกสาร"
          prepend-inner-icon="mdi-magnify"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:260px;flex:1 1 200px"
        />
        <v-select
          v-model="filterStatus"
          :items="STATUS_OPTIONS"
          item-title="title"
          item-value="value"
          label="สถานะ"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:160px"
        />
        <v-select
          v-model="filterType"
          :items="DOC_TYPES"
          item-title="title"
          item-value="value"
          label="ประเภท"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:160px"
        />
        <v-select
          v-model="filterGroup"
          :items="DOC_GROUPS"
          item-title="title"
          item-value="value"
          label="หมวดหมู่"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:160px"
        />
        <v-text-field
          v-model="filterDateFrom"
          label="จากวันที่"
          type="date"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:160px"
        />
        <v-text-field
          v-model="filterDateTo"
          label="ถึงวันที่"
          type="date"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:160px"
        />
        <v-btn
          v-if="filterName || filterStatus || filterType || filterGroup || filterDateFrom || filterDateTo"
          size="small"
          variant="text"
          color="grey"
          prepend-icon="mdi-close"
          @click="filterName = ''; filterStatus = null; filterType = null; filterGroup = null; filterDateFrom = ''; filterDateTo = ''"
        >ล้างตัวกรอง</v-btn>
      </div>

      <v-divider />

      <v-table density="comfortable">
        <thead>
          <tr>
            <th>ชื่อเอกสาร</th>
            <th>ประเภท</th>
            <th>หมวดหมู่</th>
            <th>วันที่นำเข้า</th>
            <th>สถานะ</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="6" class="text-center pa-6">
              <v-progress-circular indeterminate size="24" color="admin-primary" />
            </td>
          </tr>
          <tr v-else-if="logRows.length === 0">
            <td colspan="6" class="text-center pa-6 text-medium-emphasis">ไม่พบเอกสารที่ตรงกับเงื่อนไข</td>
          </tr>
          <tr v-for="row in logRows" :key="row.id">
            <td class="py-2" style="max-width:320px">
              <span class="text-body-2 font-weight-medium d-block" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                {{ row.name }}
              </span>
            </td>
            <td>
              <v-chip size="x-small" :color="badgeColor(row.type)" rounded="pill">{{ typeLabel(row.type) }}</v-chip>
            </td>
            <td class="text-caption">{{ row.group }}</td>
            <td class="text-caption">{{ formatDate(row.importDate) }}</td>
            <td>
              <v-chip size="x-small" :color="statusChipColor(row.status)" rounded="pill">
                <v-icon start icon="mdi-circle" size="8" />
                {{ statusLabel(row.status) }}
              </v-chip>
            </td>
            <td>
              <v-btn
                size="x-small"
                color="admin-primary"
                variant="tonal"
                prepend-icon="mdi-pencil-outline"
                @click="router.push(`/documents/${row.id}/review`)"
              >แก้ไข</v-btn>
            </td>
          </tr>
        </tbody>
      </v-table>

      <v-divider />
      <div class="pa-3 d-flex justify-space-between align-center">
        <span class="text-caption text-medium-emphasis">
          แสดง {{ logRows.length }} จาก {{ docs.length }} รายการ
        </span>
      </div>
    </v-card>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { listDocuments } from '../../api/client';
import type { DocumentListItem } from '../../types/document';
import AdminStatCard from '../../components/admin/AdminStatCard.vue';
import AppShell from '../../components/shared/AppShell.vue';

const router = useRouter();

// ── API state ──────────────────────────────────────────────
const docs = ref<DocumentListItem[]>([]);
const loading = ref(false);

onMounted(async () => {
  loading.value = true;
  try {
    const res = await listDocuments();
    docs.value = res.documents ?? [];
  } finally {
    loading.value = false;
  }
});

// ── Status card counts ─────────────────────────────────────
const PUBLISHED = ['done', 'exported', 'ingested'];
const PROCESSING = ['queued', 'processing', 'ingesting'];

const statCards = computed(() => [
  {
    icon: 'mdi-file-document-multiple-outline',
    iconColor: '#2563eb',
    iconBg: '#dbeafe',
    number: docs.value.length,
    label: 'เอกสารทั้งหมด',
  },
  {
    icon: 'mdi-check-circle-outline',
    iconColor: '#16a34a',
    iconBg: '#dcfce7',
    number: docs.value.filter(d => PUBLISHED.includes(d.status)).length,
    label: 'เผยแพร่แล้ว',
  },
  {
    icon: 'mdi-file-edit-outline',
    iconColor: '#64748b',
    iconBg: '#f1f5f9',
    number: docs.value.filter(d => d.status === 'failed').length,
    label: 'ล้มเหลว',
  },
  {
    icon: 'mdi-clock-outline',
    iconColor: '#d97706',
    iconBg: '#fef3c7',
    number: docs.value.filter(d => PROCESSING.includes(d.status)).length,
    label: 'รอตรวจสอบ',
  },
  {
    icon: 'mdi-draw-pen',
    iconColor: '#7c3aed',
    iconBg: '#ede9fe',
    number: 0,  // ponytail: eSign not in current API, show 0 until workflow added
    label: 'รอลงนาม (eSign)',
  },
]);

// ── Completeness bars (keep as-is) ─────────────────────────
const completeness = [
  { label: 'ระเบียบ', pct: 87, color: '#16a34a' },
  { label: 'ประกาศ', pct: 74, color: '#ea580c' },
  { label: 'ข้อบังคับ', pct: 61, color: '#2563eb' },
  { label: 'กฎหมายหลัก', pct: 92, color: '#7c3aed' },
];

// ── Urgent alerts (keep as-is) ──────────────────────────────
const urgentAlerts = [
  { id: 'a1', level: 'error', title: 'ระเบียบ 12 ฉบับ หมดอายุภายใน 30 วัน', sub: 'ต้องปรับปรุงเนื้อหา' },
  { id: 'a2', level: 'warning', title: 'OCR คิวคงค้าง 12 งาน', sub: 'เอกสาร scan กำลังรอประมวลผล' },
  { id: 'a3', level: 'info', title: '5 เอกสารรอตรวจสอบ', sub: 'โดยเจ้าหน้าที่ภายใน 3 วัน' },
];

// ── Helpers ────────────────────────────────────────────────
function badgeColor(t: string): string {
  return ({ rabiap: 'success', prakat: 'warning', 'kho-bangkhab': 'info', 'kotmai-krung': 'deep-purple' } as Record<string, string>)[t] ?? 'grey';
}
function statusChipColor(s: string): string {
  return ({ done: 'success', exported: 'success', ingested: 'success', processing: 'warning', ingesting: 'warning', queued: 'info', failed: 'error' } as Record<string, string>)[s] ?? 'grey';
}
function statusLabel(s: string): string {
  const m: Record<string, string> = { done: 'เสร็จสิ้น', exported: 'ส่งออกแล้ว', ingested: 'นำเข้าแล้ว', processing: 'กำลังประมวลผล', ingesting: 'กำลังนำเข้า', queued: 'รอดำเนินการ', failed: 'ล้มเหลว' };
  return m[s] ?? s;
}

// ── Document log filters ────────────────────────────────────
const filterName = ref('');
const filterStatus = ref<string | null>(null);
const filterType = ref<string | null>(null);
const filterGroup = ref<string | null>(null);
const filterDateFrom = ref('');
const filterDateTo = ref('');

const DOC_TYPES = [
  { title: 'ทุกประเภท', value: null },
  { title: 'ระเบียบ', value: 'rabiap' },
  { title: 'ประกาศ', value: 'prakat' },
  { title: 'ข้อบังคับ', value: 'kho-bangkhab' },
  { title: 'กฎหมายหลัก', value: 'kotmai-krung' },
];

const DOC_GROUPS = [
  { title: 'ทุกหมวด', value: null },
  { title: 'ด้านวิชาการ', value: 'วิชาการ' },
  { title: 'ด้านการเงิน', value: 'การเงิน' },
  { title: 'ด้านบุคคล', value: 'บุคคล' },
  { title: 'ด้านดิจิทัล', value: 'ดิจิทัล' },
];

const STATUS_OPTIONS = [
  { title: 'ทุกสถานะ', value: null },
  { title: 'เสร็จสิ้น', value: 'done' },
  { title: 'กำลังประมวลผล', value: 'processing' },
  { title: 'รอดำเนินการ', value: 'queued' },
  { title: 'ล้มเหลว', value: 'failed' },
];

// ponytail: type/group inferred from title keywords until API adds metadata fields
function inferType(title: string): string {
  if (title.includes('ระเบียบ')) return 'rabiap';
  if (title.includes('ประกาศ')) return 'prakat';
  if (title.includes('ข้อบังคับ')) return 'kho-bangkhab';
  if (title.includes('พระราช') || title.includes('กฎหมาย')) return 'kotmai-krung';
  return 'other';
}

function inferGroup(title: string): string {
  if (title.includes('วิชาการ') || title.includes('การสอน')) return 'วิชาการ';
  if (title.includes('การเงิน') || title.includes('งบประมาณ')) return 'การเงิน';
  if (title.includes('บุคลากร') || title.includes('บุคคล') || title.includes('พนักงาน')) return 'บุคคล';
  if (title.includes('ดิจิทัล') || title.includes('สารสนเทศ') || title.includes('ข้อมูล')) return 'ดิจิทัล';
  return 'ทั่วไป';
}

const typeLabel = (key: string): string =>
  ({ rabiap: 'ระเบียบ', prakat: 'ประกาศ', 'kho-bangkhab': 'ข้อบังคับ', 'kotmai-krung': 'กฎหมายหลัก', other: 'อื่น ๆ' } as Record<string, string>)[key] ?? key;

const logRows = computed(() => {
  return docs.value
    .map(d => ({
      id: d.document_id,
      name: d.title ?? d.document_id,
      type: inferType(d.title ?? ''),
      group: inferGroup(d.title ?? ''),
      importDate: d.updated_at ?? '',
      status: d.status,
    }))
    .filter(row => {
      if (filterName.value && !row.name.toLowerCase().includes(filterName.value.toLowerCase())) return false;
      if (filterStatus.value && row.status !== filterStatus.value) return false;
      if (filterType.value && row.type !== filterType.value) return false;
      if (filterGroup.value && row.group !== filterGroup.value) return false;
      if (filterDateFrom.value && row.importDate < filterDateFrom.value) return false;
      if (filterDateTo.value && row.importDate > filterDateTo.value + 'T23:59:59') return false;
      return true;
    });
});

function formatDate(iso: string): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
}
</script>
