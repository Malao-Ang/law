<template>
  <LawspaceShell
    :breadcrumbs="['LAWSPACE', 'การนำเข้าข้อมูล']"
    title="นำเข้าเอกสาร"
    subtitle="อัปโหลด DOC, DOCX หรือ PDF เพื่อประมวลผลด้วยระบบ OCR"
  >
    <div class="admin-upload__grid">
      <div class="admin-upload__main">
        <div class="admin-upload__drop-card">
          <div class="admin-upload__drop-icon">
            <span class="mdi mdi-cloud-upload-outline"></span>
          </div>
          <h3 class="admin-upload__drop-title">ลากไฟล์มาวางที่นี่</h3>
          <p class="admin-upload__drop-sub">หรือ</p>
          <UploadForm @uploaded="onUploaded" />
        </div>

        <transition name="fade">
          <div v-if="uploadStore.status" class="admin-upload__status-card">
            <div class="admin-upload__status-header">
              <span class="mdi mdi-file-document-outline"></span>
              <span class="admin-upload__status-filename">{{ uploadStore.status.source_file ?? 'เอกสาร' }}</span>
              <span class="admin-upload__status-chip" :class="`admin-upload__status-chip--${uploadStore.status.status}`">
                {{ statusLabel(uploadStore.status.status) }}
              </span>
            </div>

            <v-progress-linear
              v-if="isProcessing"
              indeterminate
              color="primary"
              class="admin-upload__progress"
            />

            <p v-if="uploadStore.status.current_step" class="admin-upload__step-label">{{ uploadStore.status.current_step }}</p>
            <p v-if="uploadStore.status.conversion" class="admin-upload__step-label">
              Converted from .doc via {{ uploadStore.status.conversion.tool }} ({{ formatDuration(uploadStore.status.conversion.duration_ms) }})
            </p>

            <div v-if="isDone" class="admin-upload__actions">
              <v-btn color="primary" prepend-icon="mdi-eye-outline" @click="goToView">
                ดูเอกสาร (แบบอ่าน)
              </v-btn>
              <v-btn color="secondary" variant="tonal" prepend-icon="mdi-pencil-outline" @click="goToEdit">
                แก้ไขเอกสาร
              </v-btn>
            </div>
          </div>
        </transition>
      </div>

      <aside class="admin-upload__info">
        <h4 class="admin-upload__info-title">รูปแบบไฟล์ที่รองรับ</h4>
        <ul class="admin-upload__info-list">
          <li>
            <span class="mdi mdi-file-word-outline"></span>
            <span>.doc — Word 97-2003 Document</span>
          </li>
          <li>
            <span class="mdi mdi-file-word-outline"></span>
            <span>.docx — Word Document</span>
          </li>
          <li>
            <span class="mdi mdi-file-pdf-box"></span>
            <span>.pdf — PDF (ข้อความหรือสแกน)</span>
          </li>
        </ul>

        <h4 class="admin-upload__info-title">เครื่องมือ OCR</h4>
        <ul class="admin-upload__info-list">
          <li><span class="mdi mdi-robot-outline"></span><span>Auto — Docling + EasyOCR</span></li>
          <li><span class="mdi mdi-cloud-outline"></span><span>LandingAI — AI Parse (สำหรับเอกสารซับซ้อน)</span></li>
        </ul>

        <div class="admin-upload__info-note">
          <span class="mdi mdi-information-outline"></span>
          <span>การประมวลผลครั้งแรกอาจใช้เวลา 1–3 นาที เนื่องจากระบบโหลดโมเดล OCR</span>
        </div>
      </aside>
    </div>
  </LawspaceShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useUploadStore } from '../../stores/upload';
import LawspaceShell from '../../components/shared/LawspaceShell.vue';
import UploadForm from '../../components/shared/UploadForm.vue';

const router = useRouter();
const uploadStore = useUploadStore();
const documentId = ref<string | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const isProcessing = computed(() =>
  ['queued', 'processing', 'ingesting'].includes(uploadStore.status?.status ?? ''),
);

const isDone = computed(() =>
  ['done', 'exported', 'ingesting', 'ingested'].includes(uploadStore.status?.status ?? ''),
);

onBeforeUnmount(() => {
  if (pollTimer) {
    clearTimeout(pollTimer);
  }
  uploadStore.reset();
});

function statusLabel(nextStatus: string): string {
  const map: Record<string, string> = {
    queued: 'รอดำเนินการ',
    processing: 'กำลังประมวลผล',
    ingesting: 'กำลังนำเข้าระบบ',
    done: 'เสร็จสิ้น',
    exported: 'ส่งออกแล้ว',
    ingested: 'นำเข้าแล้ว',
    failed: 'ล้มเหลว',
  };
  return map[nextStatus] ?? nextStatus;
}

function formatDuration(durationMs?: number | null): string {
  if (!durationMs || durationMs <= 0) {
    return '-';
  }

  return `${(durationMs / 1000).toFixed(1)}s`;
}

function onUploaded(id: string): void {
  documentId.value = id;
  uploadStore.reset();
  pollStatus();
}

async function pollStatus(): Promise<void> {
  if (!documentId.value) return;
  const result = await uploadStore.pollOnce(documentId.value);
  if (result && ['queued', 'processing', 'ingesting'].includes(result.status)) {
    pollTimer = setTimeout(pollStatus, 1500);
  } else if (!result) {
    pollTimer = setTimeout(pollStatus, 2000);
  }
}

function goToView(): void {
  if (documentId.value) {
    router.push(`/documents/${documentId.value}/review`);
  }
}

function goToEdit(): void {
  if (documentId.value) {
    router.push(`/documents/${documentId.value}/compose`);
  }
}
</script>

<style scoped>
.admin-upload__grid {
  display: grid;
  grid-template-columns: 1fr 300px;
  gap: 24px;
  align-items: start;
}

.admin-upload__drop-card {
  border: 2px dashed var(--law-border);
  border-radius: 12px;
  padding: 40px 32px 32px;
  text-align: center;
  background: var(--law-surface);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.admin-upload__drop-icon .mdi {
  font-size: 56px;
  color: var(--law-border);
}

.admin-upload__drop-title {
  font-size: 18px;
  font-weight: 700;
  color: var(--elaw-navy);
  margin: 8px 0 0;
}

.admin-upload__drop-sub {
  font-size: 13px;
  color: var(--elaw-muted);
  margin: 0 0 8px;
}

.admin-upload__status-card {
  margin-top: 20px;
  border: 1px solid var(--law-border);
  border-radius: 10px;
  padding: 20px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.admin-upload__status-header {
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 600;
}

.admin-upload__status-header .mdi {
  font-size: 20px;
  color: var(--law-primary);
}

.admin-upload__status-filename {
  flex: 1;
  font-size: 14px;
}

.admin-upload__status-chip {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 10px;
}

.admin-upload__status-chip--done {
  color: #15803d;
  background: #dcfce7;
}

.admin-upload__status-chip--processing,
.admin-upload__status-chip--ingesting {
  color: #92400e;
  background: #fffbeb;
}

.admin-upload__status-chip--queued {
  color: #1d4ed8;
  background: #dbeafe;
}

.admin-upload__status-chip--failed {
  color: #b91c1c;
  background: #fee2e2;
}

.admin-upload__step-label {
  font-size: 12px;
  color: var(--elaw-muted);
  margin: 0;
}

.admin-upload__actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.admin-upload__info {
  background: #fff;
  border: 1px solid var(--law-border);
  border-radius: 10px;
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.admin-upload__info-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--elaw-navy);
  margin: 0 0 6px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.admin-upload__info-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.admin-upload__info-list li {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--elaw-text);
}

.admin-upload__info-list .mdi {
  color: var(--law-primary);
  font-size: 16px;
}

.admin-upload__info-note {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 10px 12px;
  background: var(--law-primary-soft);
  border-radius: 8px;
  font-size: 12px;
  color: var(--law-primary);
}

.admin-upload__info-note .mdi {
  font-size: 16px;
  flex-shrink: 0;
  margin-top: 1px;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.25s;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

@media (max-width: 960px) {
  .admin-upload__grid {
    grid-template-columns: 1fr;
  }
}
</style>
