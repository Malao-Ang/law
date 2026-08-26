<!-- apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue -->
<template>
  <v-card border rounded="lg">
    <div class="d-flex align-center pa-5 pb-3">
      <v-icon icon="mdi-file-document-multiple-outline" class="me-2" />
      <span class="text-subtitle-1 font-weight-bold flex-grow-1">รายการเอกสารที่นำเข้า</span>
      <v-chip color="admin-primary" variant="tonal" rounded="pill" size="small">{{ rows.length }} รายการ</v-chip>
    </div>
    <v-divider />

    <div class="pipeline-filter-bar d-flex flex-wrap pa-4 pb-0">
      <v-text-field
        v-model="filterText"
        class="pipeline-filter-search"
        prepend-inner-icon="mdi-magnify"
        placeholder="ค้นหาเอกสาร..."
        clearable
        density="compact"
        variant="outlined"
        hide-details
      />
      <div class="pipeline-filter-selects">
        <v-select
          v-model="filterSource"
          class="pipeline-filter-select"
          :items="[{ title: 'ภายใน', value: 'internal' }, { title: 'ภายนอก', value: 'external' }]"
          clearable
          density="compact"
          variant="outlined"
          hide-details
          label="แหล่งที่มา: ทั้งหมด"
        />
        <v-select
          v-model="filterType"
          class="pipeline-filter-select"
          :items="lawTypeOptions"
          clearable
          density="compact"
          variant="outlined"
          hide-details
          label="ประเภท: ทั้งหมด"
        />
        <v-select
          v-model="filterStatus"
          class="pipeline-filter-select"
          :items="statusOptions"
          item-title="title"
          item-value="value"
          clearable
          density="compact"
          variant="outlined"
          hide-details
          label="สถานะ: ทั้งหมด"
        />
      </div>
    </div>

    <v-data-table
      :headers="headers"
      :items="rows"
      :items-per-page="10"
      :loading="loading"
      item-value="documentId"
      density="comfortable"
      class="pipeline-table"
    >
      <template #item.no="{ item }">
        <span class="text-caption text-medium-emphasis">{{ item.no }}</span>
      </template>

      <template #item.title="{ item }">
        <span class="text-body-2 font-weight-medium">{{ item.title }}</span>
      </template>

      <template #item.source="{ item }">
        <v-chip
          v-if="item.source || item.documentType === 'old'"
          size="small"
          :color="item.source === 'internal' ? 'blue' : item.source === 'external' ? 'purple' : 'warning'"
          variant="tonal"
        >
          {{ item.source === 'internal' ? 'ภายใน' : item.source === 'external' ? 'ภายนอก' : 'รอกรอกข้อมูล' }}
        </v-chip>
        <span v-else>—</span>
      </template>

      <template #item.lawType="{ item }">
        <span v-if="item.lawType">{{ item.lawType }}</span>
        <v-chip v-else-if="item.documentType === 'old'" size="small" color="warning" variant="tonal">รอกรอกข้อมูล</v-chip>
        <span v-else>—</span>
      </template>

      <template #item.stage="{ item }">
        <PipelineStageChip :stage="item.stage" />
      </template>

      <template #item.updatedAt="{ item }">
        <span class="text-caption">{{ item.updatedAt }}</span>
      </template>

      <template #item.actions="{ item }">
        <div class="d-flex align-center ga-1 justify-end">
          <v-progress-circular
            v-if="stageDef(item.stage).action.type === 'none' && item.stage !== 'failed' && item.stage !== 'public'"
            indeterminate
            size="18"
            width="2"
            color="admin-primary"
          />
          <v-btn
            v-else-if="stageDef(item.stage).action.type !== 'none'"
            icon="mdi-eye-outline"
            size="small"
            variant="text"
            color="admin-primary"
            :title="rowActionLabel(item) || 'ดูเอกสาร'"
            aria-label="ดูเอกสาร"
            @click="runAction(item)"
          />

          <v-btn
            icon="mdi-delete-outline"
            size="small"
            variant="text"
            color="error"
            title="ลบประวัติอัปโหลด"
            :loading="deletingId === item.documentId"
            :disabled="deletingId !== null && deletingId !== item.documentId"
            @click="confirmDelete(item)"
          />
        </div>
      </template>

      <template #no-data>
        <div class="pa-6 text-center text-medium-emphasis">ยังไม่มีเอกสารในระบบ</div>
      </template>
    </v-data-table>
  </v-card>
</template>

<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { useRouter } from 'vue-router';
import Swal from 'sweetalert2';
import { deleteDocument, listDocuments } from '../../api/client';
import type { DocumentListItem } from '../../types/document';
import { useSnackbarStore } from '../../stores/snackbarStore';
import { formatThaiDate } from '../../utils/thaiDate';
import PipelineStageChip from './PipelineStageChip.vue';
import {
  deleteStage, deriveStage, deriveStageForDocument, deriveStageFromWorkflow, laterStage, nextStage, prevStage, readStages, writeStage,
  STAGE_MAP, type StageKey,
} from '../../data/documentPipeline';

interface Row {
  no: number;
  documentId: string;
  title: string;
  updatedAt: string;
  stage: StageKey;
  source: string;
  lawType: string;
  documentType: 'new' | 'old';
}

const router = useRouter();
const snackbar = useSnackbarStore();
const docs = ref<DocumentListItem[]>([]);
const localStages = ref<Record<string, StageKey>>(readStages());
const loading = ref(false);
const deletingId = ref<string | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const filterText = ref<string | null>('');
const filterSource = ref<string | null>(null);
const filterType = ref<string | null>(null);
const filterStatus = ref<string | null>(null);

const statusOptions = [
  { title: 'รออัปโหลด/รอประมวลผล', value: 'queued' },
  { title: 'กำลังประมวลผล', value: 'processing' },
  { title: 'ประมวลผลแล้ว', value: 'done' },
  { title: 'กำลังนำเข้าระบบ', value: 'ingesting' },
  { title: 'เผยแพร่แล้ว', value: 'exported' },
  { title: 'นำเข้าระบบแล้ว', value: 'ingested' },
  { title: 'ล้มเหลว', value: 'failed' },
  { title: 'ยกเลิก', value: 'cancelled' },
];

const filteredDocs = computed(() => {
  const needle = (filterText.value ?? '').trim().toLowerCase();

  return docs.value.filter((d) => {
    const searchableTitle = (d.title || d.document_id || d.source_file || '').toLowerCase();
    return (!needle || searchableTitle.includes(needle)) &&
    (!filterSource.value || d.source === filterSource.value) &&
    (!filterType.value || d.law_type === filterType.value) &&
    (!filterStatus.value || d.status === filterStatus.value);
  });
});

// Unique law_type values from loaded docs for the type filter
const lawTypeOptions = computed(() =>
  [...new Set(docs.value.map((d) => d.law_type).filter(Boolean))] as string[]
);

const headers = [
  { title: 'ลำดับ', key: 'no', sortable: false, align: 'center' as const, width: 72 },
  { title: 'เอกสาร', key: 'title', sortable: false },
  { title: 'แหล่งที่มา', key: 'source', sortable: false },
  { title: 'ประเภท', key: 'lawType', sortable: false },
  { title: 'สถานะ', key: 'stage', sortable: false },
  { title: 'อัปเดตล่าสุด', key: 'updatedAt', sortable: false },
  { title: 'การดำเนินการ', key: 'actions', sortable: false, align: 'end' as const },
];

function effectiveStage(doc: DocumentListItem): StageKey {
  // Old docs follow a fixed path — no localStorage overrides needed
  if (doc.document_type === 'old') {
    return deriveStageForDocument({
      status: doc.status,
      document_type: doc.document_type,
      workflow_completed_step: doc.workflow_completed_step,
    });
  }
  // New docs: derive backend+workflow stage, then apply localStorage admin override on top
  const base = deriveStageForDocument({
    status: doc.status,
    document_type: doc.document_type,
    workflow_completed_step: doc.workflow_completed_step,
  });
  if (base === 'failed') return 'failed';
  const stored = localStages.value[doc.document_id];
  return stored ? laterStage(base, stored) : base;
}

const rows = computed<Row[]>(() =>
  filteredDocs.value.map((doc, index) => ({
    no: index + 1,
    documentId: doc.document_id,
    title: doc.title || doc.document_id,
    updatedAt: formatDate(doc.updated_at),
    stage: effectiveStage(doc),
    source: doc.source ?? '',
    lawType: doc.law_type ?? '',
    documentType: doc.document_type ?? 'new',
  })),
);

function stageDef(stage: StageKey) {
  return STAGE_MAP[stage];
}

function actionLabel(stage: StageKey): string {
  const a = STAGE_MAP[stage].action;
  return a.type === 'none' ? '' : a.label;
}

function canRollback(stage: StageKey): boolean {
  return prevStage(stage) !== stage;
}

function setStage(documentId: string, stage: StageKey): void {
  localStages.value = { ...localStages.value, [documentId]: stage };
  writeStage(documentId, stage);
}

function runAction(row: Row): void {
  if (isOldPreview(row)) {
    router.push(`/documents/${row.documentId}/preview`);
    return;
  }
  const action = STAGE_MAP[row.stage].action;
  if (action.type === 'route') {
    router.push(action.to(row.documentId));
  } else if (action.type === 'advance') {
    setStage(row.documentId, nextStage(row.stage));
  }
}

function isOldPreview(row: Row): boolean {
  return row.documentType === 'old' && row.stage === 'info';
}

function rowActionLabel(row: Row): string {
  if (isOldPreview(row)) return 'ดูตัวอย่าง';
  return actionLabel(row.stage);
}

function advance(row: Row): void {
  setStage(row.documentId, nextStage(row.stage));
}

function rollback(row: Row): void {
  setStage(row.documentId, prevStage(row.stage));
}

async function confirmDelete(row: Row): Promise<void> {
  const confirmed = await Swal.fire({
    icon: 'warning',
    title: 'ลบประวัติอัปโหลด?',
    text: row.title,
    showCancelButton: true,
    confirmButtonText: 'ลบ',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#b42318',
    cancelButtonColor: '#64748b',
  });
  if (!confirmed.isConfirmed) return;

  deletingId.value = row.documentId;
  try {
    await deleteDocument(row.documentId);
    delete localStages.value[row.documentId];
    localStages.value = { ...localStages.value };
    deleteStage(row.documentId);
    await load();
    snackbar.success('ลบประวัติอัปโหลดแล้ว');
  } catch (err) {
    snackbar.error(err instanceof Error ? err.message : 'ลบประวัติอัปโหลดไม่สำเร็จ');
  } finally {
    deletingId.value = null;
  }
}

function formatDate(iso?: string | null): string {
  if (!iso) return '—';
  return formatThaiDate(iso) || '—';
}

function hasActive(): boolean {
  return docs.value.some((d) => ['queued', 'processing', 'ingesting'].includes(d.status));
}

async function load(): Promise<void> {
  try {
    const res = await listDocuments();
    docs.value = res.documents ?? [];
  } finally {
    schedulePoll();
  }
}

function schedulePoll(): void {
  if (pollTimer) clearTimeout(pollTimer);
  if (hasActive()) {
    pollTimer = setTimeout(() => void load(), 2000);
  }
}

onMounted(async () => {
  loading.value = true;
  await load();
  loading.value = false;
});

onBeforeUnmount(() => {
  if (pollTimer) clearTimeout(pollTimer);
});

defineExpose({ load });
</script>

<style scoped>
.pipeline-table :deep(thead th) {
  color: rgba(var(--v-theme-secondary), 0.6) !important;
  font-size: 0.75rem !important;
  font-weight: 600 !important;
}

.pipeline-filter-bar {
  align-items: flex-start;
  column-gap: 24px;
  justify-content: space-between;
  row-gap: 14px;
}

.pipeline-filter-search {
  flex: 1 1 360px;
  min-width: 320px;
}

.pipeline-filter-selects {
  align-items: flex-start;
  column-gap: 14px;
  display: flex;
  flex: 0 0 auto;
  flex-wrap: nowrap;
  justify-content: flex-end;
  row-gap: 12px;
}

.pipeline-filter-select {
  flex: 0 0 190px;
  width: 190px;
}

.pipeline-filter-select:last-child {
  flex-basis: 210px;
  width: 210px;
}

.pipeline-sel-bar {
  background: rgba(var(--v-theme-admin-primary), 0.05);
  border-bottom: 1px solid rgba(var(--v-theme-admin-primary), 0.12);
}

@media (max-width: 1100px) {
  .pipeline-filter-selects {
    flex-basis: 100%;
    flex-wrap: wrap;
    justify-content: flex-end;
  }
}

@media (max-width: 720px) {
  .pipeline-filter-search,
  .pipeline-filter-select {
    flex-basis: 100%;
    min-width: 0;
    width: 100%;
  }

  .pipeline-filter-selects {
    flex-basis: 100%;
    justify-content: stretch;
  }
}
</style>
