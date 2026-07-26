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
          <div class="d-flex align-center ga-1">
            <v-btn icon="mdi-minus" size="x-small" variant="text" @click="zoomOut" />
            <span class="text-caption font-weight-medium" style="min-width:48px;text-align:center">{{ zoom }}%</span>
            <v-btn icon="mdi-plus" size="x-small" variant="text" @click="zoomIn" />
          </div>
          <div class="d-flex align-center ga-2">
            <v-btn icon="mdi-chevron-left" size="x-small" variant="text" :disabled="page <= 1" @click="page--" />
            <span class="text-caption">หน้า {{ page }} / {{ pageCount }}</span>
            <v-btn icon="mdi-chevron-right" size="x-small" variant="text" :disabled="page >= pageCount" @click="page++" />
          </div>
          <div class="d-flex align-center ga-1">
            <v-btn icon="mdi-arrow-expand-horizontal" size="x-small" variant="text" title="พอดีความกว้าง" @click="fitWidth" />
            <v-btn icon="mdi-fullscreen" size="x-small" variant="text" title="เต็มจอ" @click="toggleFullscreen" />
          </div>
        </div>

        <div ref="viewportEl" class="preview-viewer__viewport" @scroll="onScroll">
          <div class="preview-viewer__stage" :style="{ transform: `scale(${zoom / 100})` }">
            <article ref="paperEl" class="preview-paper" v-html="safeHtml" />
          </div>
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
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import DOMPurify from 'dompurify';
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

const A4_HEIGHT_PX = 1123; // ~297mm at 96dpi

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();
const previewStore = usePreviewStore();

const zoom = ref(100);
const page = ref(1);
const pageCount = ref(1);
const viewportEl = ref<HTMLElement | null>(null);
const paperEl = ref<HTMLElement | null>(null);

const signers = ref<ESignSigner[]>([]);
const addDialog = ref(false);
const confirmSendOpen = ref(false);
const savingDraft = ref(false);
const sending = ref(false);
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

const safeHtml = computed(() => {
  const raw = previewStore.data?.draft_html ?? previewStore.data?.html ?? '';
  return DOMPurify.sanitize(raw, {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 's', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
      'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
      'span', 'div', 'section', 'header', 'article', 'sub', 'sup',
      'img', 'figure', 'figcaption'],
    ALLOWED_ATTR: ['class', 'style', 'colspan', 'rowspan', 'src', 'alt', 'width', 'height',
      'data-block-id', 'data-block-type', 'data-page-no', 'data-reading-order'],
  });
});

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

function hydrateSigners(): void {
  signers.value = readStoredSigners(props.documentId);

  // Seed from compose metadata if empty
  if (signers.value.length === 0) {
    const md = documentStore.review?.compose_state?.metadata;
    if (md?.signatory_name) {
      signers.value = [{
        id: crypto.randomUUID(),
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

function zoomIn(): void {
  zoom.value = Math.min(150, zoom.value + 10);
}

function zoomOut(): void {
  zoom.value = Math.max(50, zoom.value - 10);
}

function fitWidth(): void {
  const viewport = viewportEl.value;
  if (!viewport) return;
  const target = Math.floor(((viewport.clientWidth - 48) / 794) * 100);
  zoom.value = Math.min(150, Math.max(50, target));
}

function toggleFullscreen(): void {
  const el = viewportEl.value;
  if (!el) return;
  if (document.fullscreenElement) {
    void document.exitFullscreen();
  } else {
    void el.requestFullscreen();
  }
}

function recomputePages(): void {
  const paper = paperEl.value;
  if (!paper) {
    pageCount.value = 1;
    return;
  }
  const height = paper.scrollHeight;
  pageCount.value = Math.max(1, Math.ceil(height / A4_HEIGHT_PX));
  page.value = Math.min(page.value, pageCount.value);
}

function onScroll(): void {
  const viewport = viewportEl.value;
  if (!viewport) return;
  const scaledPage = A4_HEIGHT_PX * (zoom.value / 100);
  const next = Math.floor(viewport.scrollTop / scaledPage) + 1;
  page.value = Math.min(pageCount.value, Math.max(1, next));
}

watch(page, (next) => {
  const viewport = viewportEl.value;
  if (!viewport) return;
  const scaledPage = A4_HEIGHT_PX * (zoom.value / 100);
  const target = (next - 1) * scaledPage;
  if (Math.abs(viewport.scrollTop - target) > scaledPage * 0.4) {
    viewport.scrollTo({ top: target, behavior: 'smooth' });
  }
});

watch([safeHtml, zoom], async () => {
  await nextTick();
  recomputePages();
});

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
  await nextTick();
  recomputePages();
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
  overflow: auto;
  padding: 24px;
  display: flex;
  justify-content: center;
  align-items: flex-start;
}

.preview-viewer__stage {
  transform-origin: top center;
  transition: transform 0.15s ease;
}

.preview-paper {
  background: #fff;
  width: 210mm;
  min-height: 297mm;
  padding: 25mm 30mm;
  box-shadow: 0 8px 28px rgba(15, 23, 42, 0.12);
  font-size: 16pt;
  line-height: 1.8;
  color: #1e293b;
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
