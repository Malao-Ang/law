<template>
  <AppShell
    :breadcrumbs="['เมนูหลัก', 'จัดการตัวบทกฎหมาย', docTitle]"
    title=""
    full-height
    show-bell
  >
    <template #actions>
      <template v-if="session.status === 'draft'">
        <v-btn variant="outlined" size="small" class="text-none" @click="saveDraft">บันทึกฉบับร่าง</v-btn>
        <v-btn
          color="warning"
          size="small"
          prepend-icon="mdi-send-outline"
          class="text-none"
          :disabled="signers.length === 0"
          @click="confirmSendOpen = true"
        >ส่งไปยังระบบ E-Sign</v-btn>
      </template>
      <template v-else-if="session.status === 'waiting'">
        <v-btn color="error" size="small" variant="flat" class="text-none" @click="cancelSubmit">
          ยกเลิกการส่งลงนาม
        </v-btn>
      </template>
      <template v-else>
        <v-btn
          variant="outlined"
          size="small"
          class="text-none"
          @click="openDocPreview"
        >ดูตัวอย่าง</v-btn>
        <v-btn
          color="success"
          size="small"
          prepend-icon="mdi-earth"
          class="text-none"
          @click="publishOpen = true"
        >เผยแพร่</v-btn>
      </template>
    </template>

    <div class="status-page">
    <div class="status-toolbar mb-3">
      <v-btn
        variant="text"
        size="small"
        prepend-icon="mdi-arrow-left"
        class="text-none px-1"
        @click="router.push(session.status === 'draft' ? `/documents/${documentId}/esign/preview` : `/documents/${documentId}/esign`)"
      >ย้อนกลับ</v-btn>
    </div>

    <div v-if="documentStore.loading" class="d-flex align-center justify-center ga-3 pa-16 text-medium-emphasis flex-grow-1">
      <v-progress-circular indeterminate color="admin-primary" />
      <span>กำลังโหลด...</span>
    </div>
    <v-alert v-else-if="documentStore.error" type="error" variant="tonal">{{ documentStore.error }}</v-alert>

    <div v-else class="status-layout">
      <div class="status-main">
        <section class="status-card status-hero">
          <div class="d-flex flex-wrap align-center ga-2 mb-2">
            <v-chip
              size="small"
              :color="statusChip.color"
              variant="flat"
              class="font-weight-bold"
            >{{ statusChip.label }}</v-chip>
            <span class="text-caption text-medium-emphasis">{{ session.trackingId }}</span>
            <span v-if="updatedAtLabel" class="text-caption text-medium-emphasis">• แก้ไขล่าสุด {{ updatedAtLabel }}</span>
          </div>
          <h1 class="status-hero__title">{{ docTitle }}</h1>
        </section>

        <section class="status-card">
          <div class="text-subtitle-2 font-weight-bold mb-3">ความคืบหน้าการดำเนินงาน</div>
          <div class="status-summary-grid mb-4">
            <div class="status-summary">
              <div class="status-summary__label">ขั้นตอนปัจจุบัน</div>
              <div class="status-summary__value">{{ currentStepLabel }}</div>
            </div>
            <div class="status-summary">
              <div class="status-summary__label">ผู้รับผิดชอบ</div>
              <div class="status-summary__value">{{ meta.imported_by || '—' }}</div>
            </div>
            <div class="status-summary">
              <div class="status-summary__label">เวลาโดยประมาณ</div>
              <div class="status-summary__value">{{ etaLabel }}</div>
            </div>
            <div class="status-summary">
              <div class="status-summary__label">ความสมบูรณ์เอกสาร</div>
              <div class="status-summary__value">{{ completenessPct }}%</div>
            </div>
          </div>

          <div class="status-stepper">
            <div
              v-for="(step, index) in flowSteps"
              :key="step.key"
              class="status-step"
              :class="{
                'is-done': index < activeStepIndex,
                'is-current': index === activeStepIndex,
              }"
            >
              <div class="status-step__dot">
                <v-icon v-if="index < activeStepIndex" icon="mdi-check" size="14" />
                <span v-else>{{ index + 1 }}</span>
              </div>
              <div class="status-step__label">{{ step.label }}</div>
              <div v-if="index < flowSteps.length - 1" class="status-step__line" />
            </div>
          </div>
        </section>

        <section class="status-card">
          <div class="d-flex align-center justify-space-between ga-3 mb-3">
            <div>
              <div class="text-subtitle-2 font-weight-bold">
                {{ session.status === 'signed' ? 'เอกสาร PDF ที่ลงนามแล้ว' : 'ตัวอย่าง PDF จากเอกสารที่ตรวจทานแล้ว' }}
              </div>
              <div class="text-caption text-medium-emphasis">
                Generate จากข้อมูล review ล่าสุด ไม่ใช้ไฟล์ต้นฉบับ
              </div>
            </div>
            <div class="d-flex ga-1">
              <v-btn icon="mdi-refresh" size="small" variant="text" title="สร้าง PDF ใหม่" @click="refreshPdfPreview" />
              <v-btn
                icon="mdi-download"
                size="small"
                variant="text"
                title="ดาวน์โหลด PDF"
                :loading="downloadingPdf"
                @click="downloadPdf"
              />
            </div>
          </div>
          <div class="status-pdf">
            <object :key="pdfPreviewKey" class="status-pdf__frame" :data="pdfPreviewUrl" type="application/pdf">
              <div class="status-pdf__fallback">
                <v-icon icon="mdi-file-pdf-box" size="40" color="error" />
                <div class="text-body-2 font-weight-bold mt-2">{{ packageName }}</div>
                <div class="text-caption text-medium-emphasis mt-1">
                  เบราว์เซอร์ไม่สามารถแสดง PDF ในหน้านี้ได้
                </div>
                <v-btn class="mt-3" color="admin-primary" :href="pdfPreviewUrl" target="_blank" rel="noopener">
                  เปิด PDF
                </v-btn>
              </div>
            </object>
            <div v-if="session.status === 'signed'" class="status-pdf__signed">
              <v-icon icon="mdi-shield-check" size="14" />
              Digital Signature Verified
            </div>
          </div>
        </section>

        <section class="status-card">
          <div class="text-subtitle-2 font-weight-bold mb-3">ประวัติและกิจกรรม</div>
          <v-timeline v-if="session.activities.length" density="compact" side="end" truncate-line="both">
            <v-timeline-item
              v-for="item in session.activities"
              :key="item.id"
              size="x-small"
              :dot-color="activityColor(item.title)"
            >
              <div class="text-body-2 font-weight-medium">{{ item.title }}</div>
              <div v-if="item.detail" class="text-caption text-medium-emphasis">{{ item.detail }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ formatWhen(item.at) }}
                <span v-if="item.actor"> • {{ item.actor }}</span>
              </div>
            </v-timeline-item>
          </v-timeline>
          <div v-else class="text-caption text-medium-emphasis text-center py-6">
            ยังไม่มีกิจกรรม — ส่งเอกสารเข้าสู่ระบบ e-Sign เพื่อเริ่มติดตามสถานะ
          </div>

          <div v-if="session.status === 'waiting'" class="mt-4 d-flex justify-end">
            <v-btn
              size="small"
              variant="tonal"
              color="success"
              class="text-none"
              @click="markSigned"
            >จำลองลงนามเสร็จ</v-btn>
          </div>
        </section>
      </div>

      <aside class="status-side">
        <v-alert
          :type="sideAlert.type"
          variant="tonal"
          density="comfortable"
          class="mb-3"
        >
          <div class="font-weight-bold mb-1">{{ sideAlert.title }}</div>
          <div class="text-caption" style="white-space: pre-line">{{ sideAlert.body }}</div>
          <div v-if="session.status === 'signed'" class="d-flex flex-column ga-2 mt-3">
            <v-btn color="success" class="text-none" prepend-icon="mdi-earth" @click="publishOpen = true">เผยแพร่กฎหมาย</v-btn>
            <v-btn
              variant="outlined"
              class="text-none"
              @click="openDocPreview"
            >ดูตัวอย่าง</v-btn>
          </div>
        </v-alert>

        <v-card flat border rounded="lg" class="pa-4 mb-3">
          <div class="d-flex align-center justify-space-between mb-3">
            <div class="text-subtitle-2 font-weight-bold">ข้อมูลผู้ลงนาม</div>
            <div v-if="session.status === 'draft'" class="d-flex ga-1">
              <v-btn size="x-small" variant="text" class="text-none" @click="openSignerDialog">เปลี่ยนแปลง</v-btn>
              <v-btn
                size="x-small"
                variant="text"
                color="error"
                class="text-none"
                :disabled="signers.length === 0"
                @click="clearSigners"
              >ลบออก</v-btn>
            </div>
          </div>

          <div v-if="primarySigner" class="d-flex align-center ga-3">
            <v-avatar color="admin-primary" size="44">
              <span class="text-caption font-weight-bold">{{ initials(primarySigner.name) }}</span>
            </v-avatar>
            <div class="min-width-0">
              <div class="text-body-2 font-weight-bold">{{ primarySigner.name }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ ROLE_LABELS[primarySigner.roleType] || primarySigner.position }}
              </div>
              <div v-if="primarySigner.employeeId" class="text-caption text-medium-emphasis">
                {{ primarySigner.employeeId }}
              </div>
            </div>
          </div>
          <div v-else class="text-caption text-medium-emphasis text-center py-4">
            ยังไม่มีผู้ลงนาม
            <div class="mt-2">
              <v-btn size="small" color="admin-primary" class="text-none" @click="openSignerDialog">เลือกผู้ลงนาม</v-btn>
            </div>
          </div>

          <v-alert
            v-if="primarySigner?.roleType === 'delegate' && primarySigner.note"
            type="warning"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            หมายเหตุ: {{ primarySigner.note }}
            <div class="text-caption mt-1">การลงนามแทน ต้องแนบไฟล์คำสั่งมอบอำนาจ</div>
          </v-alert>
        </v-card>

        <v-card flat border rounded="lg" class="pa-4 mb-3">
          <div class="d-flex align-center justify-space-between mb-2">
            <div class="text-subtitle-2 font-weight-bold">สถานะความสมบูรณ์</div>
            <v-chip
              size="x-small"
              :color="completenessPct >= 100 ? 'success' : 'warning'"
              variant="flat"
              class="font-weight-bold"
            >{{ completenessPct >= 100 ? 'COMPLETE' : 'WARNING' }} {{ completenessPct }}%</v-chip>
          </div>
          <v-progress-linear
            :model-value="completenessPct"
            :color="completenessPct >= 100 ? 'success' : 'warning'"
            height="8"
            rounded
            class="mb-3"
          />
          <div v-for="item in checklist" :key="item.key" class="status-check-row">
            <v-icon
              :icon="item.ok ? 'mdi-check-circle' : 'mdi-alert-circle'"
              :color="item.ok ? 'success' : 'warning'"
              size="18"
            />
            <span class="flex-grow-1">{{ item.label }}</span>
            <span class="text-caption" :class="item.ok ? 'text-success' : 'text-warning'">{{ item.status }}</span>
          </div>
        </v-card>

        <v-card flat border rounded="lg" class="pa-4">
          <div class="text-subtitle-2 font-weight-bold mb-3">ความสัมพันธ์กฎหมาย</div>
          <div v-if="docRelations.length === 0" class="text-caption text-medium-emphasis text-center py-3">
            ยังไม่มีความสัมพันธ์
          </div>
          <div v-else class="d-flex flex-column ga-2">
            <div
              v-for="rel in docRelations"
              :key="rel.id"
              class="status-rel"
              :class="`status-rel--${rel.type}`"
            >
              <v-chip size="x-small" :color="RELATION_TYPE_COLORS[rel.type]" variant="flat" class="font-weight-bold">
                {{ relationTypeLabel(rel.type) }}
              </v-chip>
              <div class="text-body-2 font-weight-medium mt-1">{{ rel.target_title }}</div>
            </div>
          </div>
        </v-card>
      </aside>
    </div>
    </div>

    <SignerRightsDialog v-model="signerDialog" @confirm="onSignerConfirmed" />

    <ConfirmSendESignDialog
      v-model="confirmSendOpen"
      :document-title="docTitle"
      :file-name="packageName"
      :signer="primarySigner"
      :loading="sending"
      @confirm="submitToESign"
    />

    <DocumentScrollPreviewDialog
      v-model="docPreviewOpen"
      :document-id="documentId"
      :signed="session.status === 'signed'"
    />

    <PublishLawDialog
      v-model="publishOpen"
      :document-title="docTitle"
      :tracking-id="session.trackingId"
      :signed-at="session.signedAt"
      :loading="publishing"
      @confirm="publish"
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
import DocumentScrollPreviewDialog from './DocumentScrollPreviewDialog.vue';
import PublishLawDialog from './PublishLawDialog.vue';
import { useDocumentStore } from '../../stores/documentStore';
import { documentRelations } from '../../composables/useLawSections';
import { writeStage } from '../../data/documentPipeline';
import {
  ROLE_LABELS,
  loadSession,
  loadSigners,
  pushActivity,
  saveSession,
  saveSigners,
} from '../../data/esignSession';
import type { ESignSession, ESignSigner } from '../../types/esign';
import type { LawMeta } from '../../types/document';
import { formatThaiDate, formatThaiDateTime } from '../../utils/thaiDate';
import {
  RELATION_TYPE_COLORS,
  relationTypeLabel,
} from '../../types/lawRelation';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();

const session = ref<ESignSession>(loadSession(props.documentId));
const signers = ref<ESignSigner[]>(loadSigners(props.documentId));
const signerDialog = ref(false);
const confirmSendOpen = ref(false);
const docPreviewOpen = ref(false);
const publishOpen = ref(false);
const sending = ref(false);
const publishing = ref(false);
const downloadingPdf = ref(false);
const pdfPreviewKey = ref(0);

const EMPTY_META: LawMeta = {
  status: '',
  law_type: '',
  law_group: '',
  change_status: null,
  law_groups: [],
  agency: '',
  signer_group: null,
  agencies: [],
  keywords: [],
  promulgation_date: '',
  effective_date: '',
  published_date: '',
  expiry_date: null,
  section_count: null,
  title: '',
  gazette_reference: '',
  royal_command: '',
  repealed_laws: [],
  imported_by: '',
  parent_document_id: null,
  access_scope: 'public',
  permission_group_ids: [],
};

const meta = computed(() => documentStore.review?.law_meta ?? EMPTY_META);
const docTitle = computed(() => meta.value.title || documentStore.review?.source_file || props.documentId);
const docRelations = computed(() => documentRelations(documentStore.review?.relations));
const primarySigner = computed(() => signers.value[0] ?? null);

const agencyLabel = computed(() => {
  if (meta.value.agencies?.length) return meta.value.agencies.join(', ');
  return meta.value.agency || 'มหาวิทยาลัยบูรพา';
});

const updatedAtLabel = computed(() => {
  const iso = documentStore.review?.document_review?.updated_at;
  if (!iso) return '';
  return formatThaiDate(iso);
});

const packageName = computed(() => {
  const base = (documentStore.review?.source_file || 'Draft_Regulation').replace(/\.[^.]+$/, '');
  return `${base}_v1.0.pdf`;
});

const pdfPreviewUrl = computed(() => `${reviewPdfPreviewUrl(props.documentId)}?v=${pdfPreviewKey.value}`);

const metaOk = computed(() => Boolean(meta.value.title && meta.value.law_type && (meta.value.promulgation_date || meta.value.effective_date)));
const structureOk = computed(() => (documentStore.review?.summary.block_count ?? 0) > 0);
const relationsOk = computed(() => (documentStore.review?.relations?.length ?? 0) > 0);
const previewOk = computed(() => true);

const checklist = computed(() => [
  { key: 'meta', label: 'ข้อมูล METADATA', ok: metaOk.value, status: metaOk.value ? 'ผ่าน' : 'ไม่ครบ' },
  { key: 'structure', label: 'โครงสร้างหมวด/ข้อ', ok: structureOk.value, status: structureOk.value ? 'ผ่าน' : 'ไม่ครบ' },
  { key: 'relations', label: 'ความสัมพันธ์กฎหมาย (ไม่บังคับก่อนลงนาม)', ok: relationsOk.value, status: relationsOk.value ? 'ผ่าน' : 'ทำภายหลังได้' },
  {
    key: 'esign',
    label: 'ระบบ E-SIGN',
    ok: session.value.status === 'signed',
    status: session.value.status === 'signed'
      ? 'เสร็จสิ้น'
      : session.value.status === 'waiting'
        ? 'รอลงนาม'
        : signers.value.length ? 'พร้อมส่ง' : 'รอดำเนินการ',
  },
]);

const completenessPct = computed(() => {
  const base = [metaOk.value, structureOk.value, relationsOk.value, previewOk.value];
  const passed = base.filter(Boolean).length + (session.value.status === 'signed' ? 1 : 0);
  const total = base.length + 1;
  return Math.round((passed / total) * 100);
});

const flowSteps = [
  { key: 'create', label: 'สร้างเอกสาร' },
  { key: 'review', label: 'ตรวจสอบ' },
  { key: 'preview', label: 'Preview' },
  { key: 'send', label: 'ส่งลงนาม' },
  { key: 'wait', label: 'รอลงนาม' },
  { key: 'signed', label: 'ลงนามเสร็จ' },
  { key: 'publish', label: 'เผยแพร่' },
];

const activeStepIndex = computed(() => {
  if (session.value.status === 'signed') return 5;
  if (session.value.status === 'waiting') return 4;
  return 3;
});

const currentStepLabel = computed(() => flowSteps[activeStepIndex.value]?.label ?? '—');

const etaLabel = computed(() => {
  if (session.value.status === 'signed') return 'ดำเนินการเสร็จสิ้น';
  if (session.value.status === 'waiting') return 'ประมาณ 1-2 วันทำการ';
  return 'รอส่งเข้าระบบ';
});

const statusChip = computed(() => {
  if (session.value.status === 'signed') return { label: 'มีผลบังคับใช้', color: 'success' };
  if (session.value.status === 'waiting') return { label: 'รอการลงนาม', color: 'warning' };
  return { label: 'เตรียมส่งลงนาม', color: 'admin-primary' };
});

const sideAlert = computed(() => {
  if (session.value.status === 'signed') {
    return {
      type: 'success' as const,
      title: 'ลงนามเสร็จสิ้น',
      body: 'เอกสารผ่านการลงนามอิเล็กทรอนิกส์แล้ว พร้อมเผยแพร่สู่ระบบฐานข้อมูลกฎหมาย',
    };
  }
  if (session.value.status === 'waiting') {
    return {
      type: 'error' as const,
      title: 'เอกสารอยู่ระหว่างรอลงนาม',
      body: 'ไม่สามารถแก้ไขเนื้อหาได้ในระหว่างรอผู้ลงนามตรวจสอบและลงนาม',
    };
  }
  return {
    type: 'warning' as const,
    title: 'โปรดตรวจสอบก่อนส่ง',
    body: '• ตรวจสอบผู้ลงนามก่อนส่ง\n• ความสัมพันธ์กฎหมายเพิ่มได้ภายหลัง\n• หลังส่งแล้วจะแก้ไขเอกสารไม่ได้\n• สถานะจะเปลี่ยนเป็นรอลงนาม',
  };
});

function persist(): void {
  saveSession(props.documentId, session.value);
  saveSigners(props.documentId, signers.value);
}

function initials(name: string): string {
  const cleaned = name.replace(/^(ศ\.ดร\.|รศ\.ดร\.|ผศ\.ดร\.|ดร\.|นาย|นาง|นางสาว)\s*/u, '').trim();
  const parts = cleaned.split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2);
  return `${parts[0][0] ?? ''}${parts[1][0] ?? ''}`;
}

function formatWhen(iso: string): string {
  return formatThaiDateTime(iso);
}

function activityColor(title: string): string {
  if (title.includes('ลงนามเสร็จ') || title.includes('พร้อมเผยแพร่')) return 'success';
  if (title.includes('ยกเลิก')) return 'error';
  if (title.includes('e-Sign') || title.includes('ส่ง')) return 'warning';
  return 'admin-primary';
}

function openSignerDialog(): void {
  signerDialog.value = true;
}

function onSignerConfirmed(signer: ESignSigner): void {
  signers.value = [
    ...signers.value.filter((entry) => entry.roleType !== signer.roleType),
    signer,
  ];
  persist();
}

function clearSigners(): void {
  signers.value = [];
  persist();
}

function openDocPreview(): void {
  docPreviewOpen.value = true;
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

function saveDraft(): void {
  writeStage(props.documentId, 'wait_esign');
  persist();
}

function submitToESign(): void {
  if (signers.value.length === 0) return;
  sending.value = true;
  try {
    const now = new Date().toISOString();
    session.value = pushActivity({
      ...session.value,
      status: 'waiting',
      submittedAt: now,
    }, {
      title: 'ส่งเอกสารเข้าสู่ระบบ e-Sign',
      detail: `Tracking ${session.value.trackingId}`,
      actor: meta.value.imported_by || undefined,
      at: now,
    });
    writeStage(props.documentId, 'wait_esign');
    persist();
    confirmSendOpen.value = false;
  } finally {
    sending.value = false;
  }
}

function cancelSubmit(): void {
  session.value = pushActivity({
    ...session.value,
    status: 'draft',
    submittedAt: null,
  }, {
    title: 'ยกเลิกการส่งลงนาม',
    actor: meta.value.imported_by || undefined,
  });
  persist();
}

function markSigned(): void {
  const now = new Date().toISOString();
  session.value = pushActivity({
    ...session.value,
    status: 'signed',
    signedAt: now,
  }, {
    title: 'ลงนามเสร็จสิ้น — พร้อมเผยแพร่',
    detail: primarySigner.value ? `ผู้ลงนาม: ${primarySigner.value.name}` : undefined,
    at: now,
  });
  persist();
}

function publish(): void {
  publishing.value = true;
  try {
    writeStage(props.documentId, 'public');
    publishOpen.value = false;
    void router.push(`/law/${props.documentId}`);
  } finally {
    publishing.value = false;
  }
}

onMounted(() => {
  if (documentStore.documentId !== props.documentId || !documentStore.review) {
    void documentStore.fetch(props.documentId);
  }
  session.value = loadSession(props.documentId);
  signers.value = loadSigners(props.documentId);
  refreshPdfPreview();
  writeStage(props.documentId, 'wait_esign');
});

onBeforeUnmount(() => documentStore.reset());
</script>

<style scoped>
.status-page {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
  height: 100%;
  overflow: hidden;
}

.status-toolbar {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}

.status-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 16px;
  align-items: stretch;
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
}

.status-main {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-width: 0;
  min-height: 0;
  max-height: 100%;
  overflow: auto;
  overscroll-behavior: contain;
}

.status-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 18px;
}

.status-hero__title {
  margin: 0;
  font-size: clamp(20px, 2.2vw, 28px);
  font-weight: 800;
  color: #1e2a4a;
  line-height: 1.35;
}

.status-summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}

.status-summary {
  background: #f8fafc;
  border: 1px solid #eef2f7;
  border-radius: 12px;
  padding: 12px;
}

.status-summary__label { font-size: 11px; color: #64748b; margin-bottom: 4px; }
.status-summary__value { font-size: 14px; font-weight: 700; color: #1e293b; }

.status-stepper {
  display: flex;
  align-items: flex-start;
  gap: 0;
  overflow-x: auto;
  padding-top: 4px;
}

.status-step {
  position: relative;
  flex: 1 1 0;
  min-width: 72px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.status-step__dot {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: #e2e8f0;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 700;
  z-index: 1;
}

.status-step.is-done .status-step__dot {
  background: #16a34a;
  color: #fff;
}

.status-step.is-current .status-step__dot {
  background: #f59e0b;
  color: #fff;
  box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.2);
}

.status-step__label {
  margin-top: 8px;
  font-size: 11px;
  color: #64748b;
  font-weight: 600;
  line-height: 1.3;
}

.status-step.is-current .status-step__label { color: #b45309; }
.status-step.is-done .status-step__label { color: #15803d; }

.status-step__line {
  position: absolute;
  top: 13px;
  left: calc(50% + 16px);
  width: calc(100% - 32px);
  height: 2px;
  background: #e2e8f0;
}

.status-step.is-done .status-step__line { background: #86efac; }

.status-pdf {
  position: relative;
  overflow: hidden;
  border: 1px solid #d7dee7;
  border-radius: 14px;
  background: #eef2f7;
}

.status-pdf__frame {
  display: block;
  width: 100%;
  height: min(68vh, 720px);
  min-height: 460px;
  border: 0;
  background: #fff;
}

.status-pdf__fallback {
  width: 100%;
  min-height: 460px;
  border-radius: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
  text-align: center;
}

.status-pdf__signed {
  position: absolute;
  right: 12px;
  bottom: 12px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 9px;
  border-radius: 999px;
  background: rgba(22, 163, 74, 0.94);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
}

.status-side {
  min-height: 0;
  max-height: 100%;
  overflow: auto;
  overscroll-behavior: contain;
}

.status-check-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  padding: 6px 0;
  border-bottom: 1px solid #f1f5f9;
}

.status-check-row:last-child { border-bottom: none; }

.status-rel {
  border-radius: 12px;
  padding: 10px 12px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
}

.status-rel--repeals { background: #fef2f2; border-color: #fecaca; }
.status-rel--related,
.status-rel--issued_under { background: #eff6ff; border-color: #bfdbfe; }
.status-rel--amends { background: #f0fdfa; border-color: #99f6e4; }
.status-rel--supersedes { background: #fff7ed; border-color: #fed7aa; }

@media (max-width: 1100px) {
  .status-page {
    overflow: auto;
  }

  .status-layout {
    grid-template-columns: 1fr;
    overflow: visible;
    flex: none;
  }

  .status-main,
  .status-side {
    max-height: none;
    overflow: visible;
  }

  .status-summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
