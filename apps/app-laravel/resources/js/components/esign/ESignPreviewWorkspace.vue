<template>
  <AppShell
    :breadcrumbs="['เมนูหลัก', 'จัดการตัวบทกฎหมาย', docTitle]"
    title=""
    full-height
    show-bell
  >
    <template #actions>
      <v-btn
        variant="outlined"
        size="small"
        class="text-none"
        :loading="savingDraft"
        @click="saveDraft"
      >บันทึกฉบับร่าง</v-btn>
      <v-btn
        color="admin-primary"
        size="small"
        prepend-icon="mdi-send-outline"
        class="text-none"
        :disabled="!canSend"
        :loading="sending"
        @click="confirmSendOpen = true"
      >ส่งไปยังระบบ E-Sign</v-btn>
    </template>

    <div class="preview-page">
    <div class="preview-toolbar mb-3">
      <v-btn
        variant="text"
        size="small"
        prepend-icon="mdi-arrow-left"
        class="text-none px-1"
        @click="router.push(`/documents/${documentId}/esign`)"
      >ย้อนกลับ</v-btn>
    </div>

    <v-alert v-if="flash" type="success" variant="tonal" density="compact" class="mb-3" closable @click:close="flash = ''">
      {{ flash }}
    </v-alert>
    <v-alert v-if="errorFlash" type="error" variant="tonal" density="compact" class="mb-3" closable @click:close="errorFlash = ''">
      {{ errorFlash }}
    </v-alert>

    <div v-if="loading" class="d-flex align-center justify-center ga-3 pa-16 text-medium-emphasis flex-grow-1">
      <v-progress-circular indeterminate color="admin-primary" />
      <span>กำลังโหลดตัวอย่าง...</span>
    </div>
    <v-alert v-else-if="loadError" type="error" variant="tonal" class="ma-2">{{ loadError }}</v-alert>

    <div v-else class="preview-layout">
      <!-- Document viewer -->
      <section class="preview-viewer">
        <div class="preview-viewer__toolbar">
          <div>
            <div class="text-body-2 font-weight-bold">ตัวอย่าง PDF จากเอกสารที่ตรวจทานแล้ว</div>
            <div class="text-caption text-medium-emphasis">Generate จาก review ล่าสุดก่อนส่งเข้า E-Sign</div>
          </div>
          <div class="d-flex align-center ga-1">
            <v-btn icon="mdi-refresh" size="x-small" variant="text" title="สร้าง PDF ใหม่" @click="refreshPdfPreview" />
            <v-btn icon="mdi-open-in-new" size="x-small" variant="text" title="เปิด PDF" :href="pdfPreviewUrl" target="_blank" rel="noopener" />
            <v-btn icon="mdi-download" size="x-small" variant="text" title="ดาวน์โหลด PDF" :loading="downloadingPdf" @click="downloadPdf" />
          </div>
        </div>

        <div class="preview-viewer__viewport">
          <object :key="pdfPreviewKey" class="preview-pdf" :data="pdfPreviewUrl" type="application/pdf">
            <div class="preview-pdf__fallback">
              <v-icon icon="mdi-file-pdf-box" size="44" color="error" />
              <div class="text-body-2 font-weight-bold mt-2">{{ packageName }}</div>
              <div class="text-caption text-medium-emphasis mt-1">
                เบราว์เซอร์ไม่สามารถแสดง PDF ในหน้านี้ได้
              </div>
              <v-btn class="mt-3" color="admin-primary" :href="pdfPreviewUrl" target="_blank" rel="noopener">
                เปิด PDF
              </v-btn>
            </div>
          </object>
        </div>
      </section>

      <!-- Right sidebar -->
      <aside class="preview-side">
        <v-alert
          v-if="signers.length === 0"
          type="warning"
          variant="tonal"
          density="comfortable"
          class="mb-3"
        >
          <div class="font-weight-bold mb-1">กรุณาเลือกผู้ลงนาม</div>
          <div class="text-caption">ต้องระบุผู้ลงนามก่อนส่งเอกสารเข้าสู่ระบบลงนามอิเล็กทรอนิกส์</div>
        </v-alert>

        <v-card flat border rounded="lg" class="pa-4 mb-3">
          <div class="d-flex align-center justify-space-between mb-3">
            <div class="text-subtitle-2 font-weight-bold">จัดการผู้ลงนาม</div>
            <v-btn
              color="admin-primary"
              size="small"
              prepend-icon="mdi-plus"
              class="text-none"
              @click="addDialog = true"
            >เพิ่มผู้ลงนาม</v-btn>
          </div>

          <div v-if="signers.length === 0" class="preview-empty-signers">
            <v-icon icon="mdi-account-outline" size="36" color="grey" class="mb-2" />
            <div class="text-body-2 text-medium-emphasis">ยังไม่มีผู้ลงนาม</div>
          </div>

          <div v-else class="d-flex flex-column ga-2">
            <div
              v-for="(signer, index) in signers"
              :key="signer.id"
              class="preview-signer"
            >
              <v-avatar color="admin-primary" size="36" class="flex-shrink-0">
                <span class="text-caption font-weight-bold">{{ initials(signer.name) }}</span>
              </v-avatar>
              <div class="min-width-0 flex-grow-1">
                <div class="text-body-2 font-weight-bold text-truncate">{{ signer.name }}</div>
                <div class="text-caption text-medium-emphasis text-truncate">
                  {{ roleLabel(signer.roleType) }}
                  <span v-if="signer.position"> • {{ signer.position }}</span>
                </div>
                <div v-if="signer.note" class="text-caption text-warning text-truncate">{{ signer.note }}</div>
              </div>
              <v-btn
                icon="mdi-close"
                size="x-small"
                variant="text"
                color="grey"
                @click="removeSigner(index)"
              />
            </div>
          </div>
        </v-card>

        <v-card flat border rounded="lg" class="pa-4 mb-3">
          <div class="d-flex align-center justify-space-between mb-2">
            <div class="text-subtitle-2 font-weight-bold">สถานะความสมบูรณ์</div>
            <v-chip
              size="x-small"
              :color="completenessPct >= 100 ? 'success' : 'warning'"
              variant="flat"
              class="font-weight-bold"
            >{{ completenessPct >= 100 ? 'READY' : 'WARNING' }}</v-chip>
          </div>

          <div class="d-flex align-center ga-3 mb-3">
            <v-progress-circular
              :model-value="completenessPct"
              :color="completenessPct >= 100 ? 'success' : 'warning'"
              size="56"
              width="5"
            >
              <span class="text-caption font-weight-bold">{{ completenessPct }}%</span>
            </v-progress-circular>
            <div class="text-caption text-medium-emphasis">
              {{ completenessPct >= 100 ? 'พร้อมส่งลงนาม' : 'ยังไม่ครบเงื่อนไขการส่ง' }}
            </div>
          </div>

          <div
            v-for="item in checklist"
            :key="item.key"
            class="preview-check-row"
          >
            <v-icon
              :icon="item.ok ? 'mdi-check-circle' : 'mdi-alert-circle'"
              :color="item.ok ? 'success' : 'warning'"
              size="18"
            />
            <span class="flex-grow-1">{{ item.label }}</span>
            <span class="text-caption" :class="item.ok ? 'text-success' : 'text-warning'">
              {{ item.status }}
            </span>
          </div>
        </v-card>

        <v-card flat border rounded="lg" class="pa-4">
          <div class="d-flex align-center justify-space-between mb-3">
            <div class="text-subtitle-2 font-weight-bold">ความสัมพันธ์กฎหมาย</div>
            <v-btn
              size="x-small"
              variant="text"
              class="text-none"
              @click="router.push(`/documents/${documentId}/relations`)"
            >จัดการ</v-btn>
          </div>

          <div v-if="docRelations.length === 0" class="text-caption text-medium-emphasis text-center py-4">
            ยังไม่มีความสัมพันธ์
          </div>

          <div v-else class="d-flex flex-column ga-2">
            <div
              v-for="rel in docRelations"
              :key="rel.id"
              class="preview-rel"
              :class="`preview-rel--${rel.type}`"
            >
              <v-chip
                size="x-small"
                :color="RELATION_TYPE_COLORS[rel.type]"
                variant="flat"
                class="font-weight-bold"
              >{{ relationTypeLabel(rel.type) }}</v-chip>
              <div class="text-body-2 font-weight-medium mt-1">{{ rel.target_title }}</div>
              <div v-if="rel.target_section" class="text-caption text-medium-emphasis">{{ rel.target_section }}</div>
            </div>
          </div>
        </v-card>
      </aside>
    </div>
    </div>

    <SignerRightsDialog v-model="addDialog" @confirm="onSignerConfirmed" />

    <ConfirmSendESignDialog
      v-model="confirmSendOpen"
      :document-title="docTitle"
      :file-name="packageName"
      :signer="signers[0] ?? null"
      :loading="sending"
      @confirm="sendToESign"
    />
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { downloadPdfExport, reviewPdfPreviewUrl } from '../../api/client';
import AppShell from '../shared/AppShell.vue';
import SignerRightsDialog from './SignerRightsDialog.vue';
import ConfirmSendESignDialog from './ConfirmSendESignDialog.vue';
import { useDocumentStore } from '../../stores/documentStore';
import { usePreviewStore } from '../../stores/previewStore';
import { documentRelations } from '../../composables/useLawSections';
import { writeStage } from '../../data/documentPipeline';
import type { ESignSigner, ESignSignerRole } from '../../types/esign';
import {
  ROLE_LABELS,
  loadSession,
  loadSigners as readStoredSigners,
  pushActivity,
  saveSession,
  saveSigners,
} from '../../data/esignSession';
import {
  RELATION_TYPE_COLORS,
  relationTypeLabel,
} from '../../types/lawRelation';
import { createClientId } from '../../utils/createClientId';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();
const previewStore = usePreviewStore();

const signers = ref<ESignSigner[]>([]);
const addDialog = ref(false);
const confirmSendOpen = ref(false);
const savingDraft = ref(false);
const sending = ref(false);
const downloadingPdf = ref(false);
const pdfPreviewKey = ref(0);
const flash = ref('');
const errorFlash = ref('');

const loading = computed(() => previewStore.loading || documentStore.loading);
const loadError = computed(() => previewStore.error || documentStore.error);

const docTitle = computed(() =>
  documentStore.review?.law_meta?.title
  || previewStore.data?.source_file
  || documentStore.review?.source_file
  || props.documentId,
);

const meta = computed(() => documentStore.review?.law_meta);
const docRelations = computed(() => documentRelations(documentStore.review?.relations));

const metaOk = computed(() => {
  const m = meta.value;
  return Boolean(m?.title && m?.law_type && (m.promulgation_date || m.effective_date));
});

const structureOk = computed(() => (documentStore.review?.summary.block_count ?? 0) > 0);

const relationsOk = computed(() => (documentStore.review?.relations?.length ?? 0) > 0
  || (documentStore.review?.workflow_completed_step ?? 0) >= 5);

const esignOk = computed(() => signers.value.length > 0);

const checklist = computed(() => [
  { key: 'meta', label: 'ข้อมูล METADATA', ok: metaOk.value, status: metaOk.value ? 'ผ่าน' : 'ไม่ครบ' },
  { key: 'structure', label: 'โครงสร้างหมวด/ข้อ', ok: structureOk.value, status: structureOk.value ? 'ผ่าน' : 'ไม่ครบ' },
  { key: 'relations', label: 'ระบุความสัมพันธ์กฎหมายครบ', ok: relationsOk.value, status: relationsOk.value ? 'ผ่าน' : 'รอดำเนินการ' },
  { key: 'esign', label: 'ระบบ E-SIGN', ok: esignOk.value, status: esignOk.value ? 'พร้อมส่ง' : 'รอลงนาม' },
]);

const completenessPct = computed(() => {
  const items = checklist.value;
  const passed = items.filter((i) => i.ok).length;
  return Math.round((passed / items.length) * 100);
});

const canSend = computed(() => signers.value.length > 0 && completenessPct.value >= 75);

const packageName = computed(() => {
  const base = (documentStore.review?.source_file || previewStore.data?.source_file || 'Draft_Regulation')
    .replace(/\.[^.]+$/, '');
  return `${base}_v1.0.pdf`;
});
const pdfPreviewUrl = computed(() => `${reviewPdfPreviewUrl(props.documentId)}?v=${pdfPreviewKey.value}`);

function hydrateSigners(): void {
  signers.value = readStoredSigners(props.documentId);

  // Seed from compose metadata if empty
  if (signers.value.length === 0) {
    const md = documentStore.review?.compose_state?.metadata;
    if (md?.signatory_name) {
      signers.value = [{
        id: createClientId('signer'),
        roleType: 'delegate',
        name: md.signatory_name,
        position: md.signatory_position || '',
      }];
    }
  }
}

function persistSigners(): void {
  saveSigners(props.documentId, signers.value);
}

function roleLabel(role: ESignSignerRole): string {
  return ROLE_LABELS[role] ?? role;
}

function initials(name: string): string {
  const cleaned = name.replace(/^(ศ\.ดร\.|รศ\.ดร\.|ผศ\.ดร\.|ดร\.|นาย|นาง|นางสาว)\s*/u, '').trim();
  const parts = cleaned.split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2);
  return `${parts[0][0] ?? ''}${parts[1][0] ?? ''}`;
}

function onSignerConfirmed(signer: ESignSigner): void {
  // One primary signer per role type — replace if same role already selected
  signers.value = [
    ...signers.value.filter((entry) => entry.roleType !== signer.roleType),
    signer,
  ];
  persistSigners();
}

function removeSigner(index: number): void {
  signers.value = signers.value.filter((_, i) => i !== index);
  persistSigners();
}

function refreshPdfPreview(): void {
  pdfPreviewKey.value += 1;
}

async function downloadPdf(): Promise<void> {
  downloadingPdf.value = true;
  try {
    await downloadPdfExport(props.documentId);
  } finally {
    downloadingPdf.value = false;
  }
}

async function saveDraft(): Promise<void> {
  savingDraft.value = true;
  flash.value = '';
  try {
    persistSigners();
    writeStage(props.documentId, 'wait_esign');
    flash.value = 'บันทึกฉบับร่างแล้ว';
  } finally {
    savingDraft.value = false;
  }
}

async function sendToESign(): Promise<void> {
  errorFlash.value = '';
  if (signers.value.length === 0) {
    errorFlash.value = 'กรุณาเพิ่มผู้ลงนามก่อนส่งเข้าระบบ E-Sign';
    return;
  }
  sending.value = true;
  try {
    persistSigners();
    const now = new Date().toISOString();
    const current = loadSession(props.documentId);
    const next = pushActivity({
      ...current,
      status: 'waiting',
      submittedAt: now,
    }, {
      title: 'ส่งเอกสารเข้าสู่ระบบ e-Sign',
      detail: `Tracking ${current.trackingId}`,
      actor: documentStore.review?.law_meta?.imported_by || undefined,
      at: now,
    });
    saveSession(props.documentId, next);
    writeStage(props.documentId, 'wait_esign');
    confirmSendOpen.value = false;
    await router.push(`/documents/${props.documentId}/esign/status`);
  } finally {
    sending.value = false;
  }
}

onMounted(async () => {
  await Promise.all([
    previewStore.fetch(props.documentId),
    documentStore.documentId === props.documentId && documentStore.review
      ? Promise.resolve()
      : documentStore.fetch(props.documentId),
  ]);
  hydrateSigners();
  refreshPdfPreview();
  writeStage(props.documentId, 'wait_esign');
});

onBeforeUnmount(() => {
  previewStore.reset();
});
</script>

<style scoped>
.preview-page {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
  height: 100%;
  overflow: hidden;
}

.preview-toolbar {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}

.preview-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 16px;
  align-items: stretch;
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
}

.preview-viewer {
  background: #e8edf3;
  border: 1px solid #d7dee7;
  border-radius: 16px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  min-height: 0;
  max-height: 100%;
}

.preview-viewer__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 12px;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
}

.preview-viewer__viewport {
  flex: 1;
  min-height: 0;
  padding: 0;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  background: #111827;
}

.preview-pdf {
  display: block;
  width: 100%;
  height: 100%;
  min-height: 620px;
  border: 0;
  background: #fff;
}

.preview-pdf__fallback {
  width: 100%;
  min-height: 620px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
  text-align: center;
  background: #fff;
}

.preview-side {
  min-height: 0;
  max-height: 100%;
  overflow: auto;
  overscroll-behavior: contain;
}

.preview-empty-signers {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 110px;
  border: 1px dashed #d7dee7;
  border-radius: 12px;
  background: #f8fafc;
}

.preview-signer {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  background: #fff;
}

.preview-check-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  padding: 6px 0;
  border-bottom: 1px solid #f1f5f9;
}

.preview-check-row:last-child {
  border-bottom: none;
}

.preview-rel {
  border-radius: 12px;
  padding: 10px 12px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
}

.preview-rel--repeals {
  background: #fef2f2;
  border-color: #fecaca;
}

.preview-rel--related,
.preview-rel--issued_under {
  background: #eff6ff;
  border-color: #bfdbfe;
}

.preview-rel--amends {
  background: #f0fdfa;
  border-color: #99f6e4;
}

.preview-rel--supersedes {
  background: #fff7ed;
  border-color: #fed7aa;
}

@media (max-width: 1100px) {
  .preview-page {
    overflow: auto;
  }

  .preview-layout {
    grid-template-columns: 1fr;
    overflow: visible;
    flex: none;
  }

  .preview-viewer,
  .preview-side {
    max-height: none;
    overflow: visible;
  }

  .preview-viewer {
    min-height: 60vh;
  }
}
</style>
