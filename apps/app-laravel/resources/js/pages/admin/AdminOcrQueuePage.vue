<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'คิวตรวจสอบ OCR']"
    title="คิวตรวจสอบ OCR"
    subtitle="ประวัติการนำเข้าและสถานะการประมวลผลเอกสารทั้งหมด"
    show-bell
  >
    <template #title-actions>
      <v-btn
        prepend-icon="mdi-refresh"
        variant="tonal"
        color="admin-primary"
        size="small"
        class="text-none"
        :loading="loading"
        @click="load"
      >
        รีเฟรช
      </v-btn>
    </template>

    <div class="d-flex flex-wrap ga-3 mb-4 align-center">
      <v-text-field
        v-model="search"
        placeholder="ค้นหาชื่อเอกสาร / document ID"
        prepend-inner-icon="mdi-magnify"
        variant="outlined"
        density="compact"
        hide-details
        style="max-width:420px; flex:1 1 260px"
      />
      <v-select
        v-model="filterStatus"
        :items="statusOptions"
        item-title="label"
        item-value="value"
        label="สถานะ"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        style="max-width:180px"
      />
      <v-select
        v-model="filterEngine"
        :items="engineOptions"
        item-title="label"
        item-value="value"
        label="Engine"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        style="max-width:160px"
      />
    </div>

    <v-card flat border rounded="lg">
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" />

      <v-data-table
        :headers="headers"
        :items="filtered"
        :items-per-page="25"
        item-value="id"
        density="comfortable"
        class="ocr-queue-table"
      >
        <template #item.title="{ item }">
          <div>
            <div class="text-body-2 font-weight-medium text-truncate" style="max-width:260px">
              {{ item.title }}
            </div>
            <div class="text-caption text-medium-emphasis">{{ item.id }}</div>
          </div>
        </template>

        <template #item.status="{ item }">
          <v-chip
            size="x-small"
            :color="statusColor(item.status)"
            rounded="pill"
          >
            <v-icon
              v-if="item.status === 'processing' || item.status === 'ingesting'"
              start
              icon="mdi-progress-clock"
              size="10"
            />
            <v-icon v-else-if="item.status === 'failed'" start icon="mdi-alert-circle-outline" size="10" />
            <v-icon
              v-else-if="item.status === 'done' || item.status === 'exported' || item.status === 'ingested'"
              start
              icon="mdi-check"
              size="10"
            />
            {{ statusLabel(item.status) }}
          </v-chip>
        </template>

        <template #item.engine="{ item }">
          <v-chip size="x-small" variant="tonal" :color="item.engine === 'fast' ? 'teal' : 'orange'" rounded="pill">
            {{ item.engine ?? '-' }}
          </v-chip>
        </template>

        <template #item.scanMode="{ item }">
          <span class="text-caption">{{ item.scanMode ?? '-' }}</span>
        </template>

        <template #item.duration="{ item }">
          <span class="text-caption">{{ item.duration }}</span>
        </template>

        <template #item.error="{ item }">
          <v-tooltip v-if="item.error" :text="item.error" location="top" max-width="320">
            <template #activator="{ props: tooltipProps }">
              <v-icon v-bind="tooltipProps" icon="mdi-alert-circle-outline" color="error" size="18" />
            </template>
          </v-tooltip>
          <span v-else class="text-caption text-medium-emphasis">-</span>
        </template>

        <template #item.updatedAt="{ item }">
          <span class="text-caption">{{ item.updatedAt }}</span>
        </template>

        <template #item.actions="{ item }">
          <v-btn
            v-if="item.status === 'done' || item.status === 'exported' || item.status === 'ingested'"
            icon="mdi-pencil-outline"
            size="x-small"
            variant="text"
            color="admin-primary"
            :to="`/documents/${item.id}/review`"
          />
          <v-btn
            v-if="item.status === 'failed'"
            icon="mdi-refresh"
            size="x-small"
            variant="text"
            color="warning"
            title="ประมวลผลใหม่"
            disabled
          />
        </template>

        <template #no-data>
          <div class="pa-6 text-center text-medium-emphasis">ยังไม่มีเอกสารในระบบ</div>
        </template>
      </v-data-table>
    </v-card>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { listDocuments } from '../../api/client';
import type { DocumentListItem } from '../../types/document';
import { formatThaiDateTime } from '../../utils/thaiDate';
import AppShell from '../../components/shared/AppShell.vue';

interface QueueRow {
  id: string;
  title: string;
  status: string;
  engine: string | null;
  scanMode: string | null;
  duration: string;
  error: string | null;
  updatedAt: string;
}

const docs = ref<DocumentListItem[]>([]);
const loading = ref(false);
const search = ref('');
const filterStatus = ref<string | null>(null);
const filterEngine = ref<string | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const headers = [
  { title: 'เอกสาร', key: 'title', sortable: false, minWidth: '220px' },
  { title: 'สถานะ', key: 'status', sortable: false },
  { title: 'Engine', key: 'engine', sortable: false },
  { title: 'OCR Mode', key: 'scanMode', sortable: false },
  { title: 'ใช้เวลา', key: 'duration', sortable: false },
  { title: 'ข้อผิดพลาด', key: 'error', sortable: false },
  { title: 'อัปเดตล่าสุด', key: 'updatedAt', sortable: true },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
];

const statusOptions = [
  { label: 'ทุกสถานะ', value: null },
  { label: 'รอประมวลผล', value: 'queued' },
  { label: 'กำลังประมวลผล', value: 'processing' },
  { label: 'เสร็จสิ้น', value: 'done' },
  { label: 'ล้มเหลว', value: 'failed' },
  { label: 'เผยแพร่', value: 'exported' },
  { label: 'นำเข้าระบบแล้ว', value: 'ingested' },
];

const engineOptions = [
  { label: 'ทุก Engine', value: null },
  { label: 'Fast (PHP)', value: 'fast' },
  { label: 'Standard (Python)', value: 'standard' },
];

const rows = computed<QueueRow[]>(() =>
  docs.value.map((doc) => ({
    id: doc.document_id,
    title: doc.title || doc.document_id,
    status: doc.status,
    engine: doc.extraction_engine ?? null,
    scanMode: doc.scan_mode ?? null,
    duration: formatDuration(doc.timings),
    error: doc.error ?? null,
    updatedAt: formatDate(doc.updated_at),
  })),
);

const filtered = computed(() => {
  let result = rows.value;
  if (filterStatus.value) result = result.filter((row) => row.status === filterStatus.value);
  if (filterEngine.value) result = result.filter((row) => row.engine === filterEngine.value);
  if (search.value.trim()) {
    const query = search.value.trim().toLowerCase();
    result = result.filter((row) => row.title.toLowerCase().includes(query) || row.id.toLowerCase().includes(query));
  }
  return result;
});

function formatDuration(timings: Record<string, number> | null | undefined): string {
  if (!timings) return '-';
  const total = Object.values(timings).reduce((sum, value) => sum + value, 0);
  if (total < 1) return `${(total * 1000).toFixed(0)} ms`;
  return `${total.toFixed(1)} s`;
}

function formatDate(iso?: string | null): string {
  if (!iso) return '-';
  return formatThaiDateTime(iso) || '-';
}

function statusColor(status: string): string {
  if (status === 'done' || status === 'exported' || status === 'ingested') return 'success';
  if (status === 'processing' || status === 'ingesting') return 'info';
  if (status === 'failed') return 'error';
  return 'grey';
}

function statusLabel(status: string): string {
  const map: Record<string, string> = {
    queued: 'รอ',
    processing: 'ประมวลผล',
    ingesting: 'กำลัง OCR',
    done: 'เสร็จ',
    exported: 'เผยแพร่',
    ingested: 'เผยแพร่',
    failed: 'ล้มเหลว',
  };
  return map[status] ?? status;
}

function hasActive(): boolean {
  return docs.value.some((doc) => ['queued', 'processing', 'ingesting'].includes(doc.status));
}

async function load(): Promise<void> {
  if (pollTimer) {
    clearTimeout(pollTimer);
    pollTimer = null;
  }

  loading.value = true;
  try {
    const res = await listDocuments();
    docs.value = res.documents ?? [];
  } finally {
    loading.value = false;
    if (hasActive()) pollTimer = setTimeout(() => void load(), 3000);
  }
}

onMounted(() => void load());

onBeforeUnmount(() => {
  if (pollTimer) clearTimeout(pollTimer);
});
</script>

<style scoped>
.ocr-queue-table :deep(thead th) {
  color: rgba(var(--v-theme-secondary), 0.6) !important;
  font-size: 0.75rem !important;
  font-weight: 600 !important;
}
</style>
