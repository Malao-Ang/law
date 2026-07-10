<!-- apps/app-laravel/resources/js/components/admin/DocumentPipelineTable.vue -->
<template>
  <v-card border rounded="lg">
    <div class="d-flex align-center pa-5 pb-3">
      <v-icon icon="mdi-file-document-multiple-outline" class="me-2" />
      <span class="text-subtitle-1 font-weight-bold flex-grow-1">รายการเอกสารที่นำเข้า</span>
      <v-chip color="admin-primary" variant="tonal" rounded="pill" size="small">{{ rows.length }} รายการ</v-chip>
    </div>
    <v-divider />

    <v-data-table
      :headers="headers"
      :items="rows"
      :items-per-page="10"
      :loading="loading"
      item-value="documentId"
      density="comfortable"
      class="pipeline-table"
    >
      <template #item.title="{ item }">
        <span class="text-body-2 font-weight-medium">{{ item.title }}</span>
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
            size="small"
            variant="tonal"
            color="admin-primary"
            class="text-none"
            @click="runAction(item)"
          >
            {{ actionLabel(item.stage) }}
          </v-btn>

          <v-menu>
            <template #activator="{ props: menuProps }">
              <v-btn icon="mdi-dots-vertical" size="small" variant="text" color="grey" v-bind="menuProps" />
            </template>
            <v-list density="compact">
              <v-list-item :disabled="item.stage === 'public' || item.stage === 'failed'" @click="advance(item)">
                <template #prepend><v-icon icon="mdi-arrow-right" size="18" /></template>
                <v-list-item-title>ขั้นถัดไป</v-list-item-title>
              </v-list-item>
              <v-list-item :disabled="!canRollback(item.stage)" @click="rollback(item)">
                <template #prepend><v-icon icon="mdi-arrow-left" size="18" /></template>
                <v-list-item-title>ย้อนกลับ</v-list-item-title>
              </v-list-item>
            </v-list>
          </v-menu>
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
import { listDocuments } from '../../api/client';
import type { DocumentListItem } from '../../types/document';
import PipelineStageChip from './PipelineStageChip.vue';
import {
  deriveStage, laterStage, nextStage, prevStage, readStages, writeStage,
  STAGE_MAP, STAGES, type StageKey,
} from '../../data/documentPipeline';

interface Row {
  documentId: string;
  title: string;
  status: string;
  updatedAt: string;
  stage: StageKey;
}

const router = useRouter();
const docs = ref<DocumentListItem[]>([]);
const localStages = ref<Record<string, StageKey>>(readStages());
const loading = ref(false);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const headers = [
  { title: 'เอกสาร', key: 'title', sortable: false },
  { title: 'สถานะ', key: 'stage', sortable: false },
  { title: 'อัปเดตล่าสุด', key: 'updatedAt', sortable: false },
  { title: 'การดำเนินการ', key: 'actions', sortable: false, align: 'end' as const },
];

const PROCESSED_FLOOR = STAGES.findIndex((s) => s.key === 'processed');

function effectiveStage(doc: DocumentListItem): StageKey {
  const derived = deriveStage(doc.status);
  if (derived === 'failed') return 'failed';
  const stored = localStages.value[doc.document_id];
  return stored ? laterStage(derived, stored) : derived;
}

const rows = computed<Row[]>(() =>
  docs.value.map((doc) => ({
    documentId: doc.document_id,
    title: doc.title || doc.document_id,
    status: doc.status,
    updatedAt: formatDate(doc.updated_at),
    stage: effectiveStage(doc),
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
  const i = STAGES.findIndex((s) => s.key === stage);
  return i > PROCESSED_FLOOR;
}

function setStage(documentId: string, stage: StageKey): void {
  localStages.value = { ...localStages.value, [documentId]: stage };
  writeStage(documentId, stage);
}

function runAction(row: Row): void {
  const action = STAGE_MAP[row.stage].action;
  if (action.type === 'route') {
    router.push(action.to(row.documentId));
  } else if (action.type === 'advance') {
    setStage(row.documentId, nextStage(row.stage));
  }
}

function advance(row: Row): void {
  setStage(row.documentId, nextStage(row.stage));
}

function rollback(row: Row): void {
  setStage(row.documentId, prevStage(row.stage));
}

function formatDate(iso?: string | null): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
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
</style>
