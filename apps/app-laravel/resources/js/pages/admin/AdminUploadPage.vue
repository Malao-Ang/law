<template>
  <LawspaceShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล']"
    title="การนำเข้าเอกสารกฎหมาย"
    subtitle="อัปโหลดไฟล์เพื่อเตรียมสกัดเนื้อหาเข้าสู่ระบบฐานข้อมูล"
  >
    <div class="admin-upload">
      <div class="admin-upload__drop-card">
        <span class="mdi mdi-cloud-upload-outline admin-upload__drop-icon"></span>
        <h3 class="admin-upload__drop-title">ลากและวางไฟล์ข้อมูลกฎหมาย</h3>
        <p class="admin-upload__drop-sub">รองรับไฟล์ .PDF หรือ .DOCX</p>
        <UploadForm @uploaded="onUploaded" />
      </div>

      <transition name="fade">
        <div v-if="uploadStore.status" class="admin-upload__status-card">
          <div class="admin-upload__status-header">
            <span class="mdi mdi-file-document-outline"></span>
            <span class="admin-upload__status-filename">{{ uploadStore.status.source_file ?? 'เอกสาร' }}</span>
            <span
              class="admin-upload__status-chip"
              :class="`admin-upload__status-chip--${uploadStore.status.status}`"
            >
              {{ statusLabel(uploadStore.status.status) }}
            </span>
          </div>
          <v-progress-linear
            v-if="isProcessing"
            indeterminate
            color="primary"
            class="admin-upload__progress"
          />
          <p v-if="uploadStore.status.current_step" class="admin-upload__step-label">
            {{ uploadStore.status.current_step }}
          </p>
          <p v-if="uploadStore.status.scan_extraction_mode_requested" class="admin-upload__step-label">
            OCR mode: {{ uploadStore.status.scan_extraction_mode_requested }}
            <template v-if="uploadStore.status.scan_extraction_mode_effective">
              → {{ uploadStore.status.scan_extraction_mode_effective }}
            </template>
          </p>
        </div>
      </transition>
    </div>
  </LawspaceShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useUploadStore } from '../../stores/uploadStore';
import LawspaceShell from '../../components/shared/LawspaceShell.vue';
import UploadForm from '../../components/shared/UploadForm.vue';

const router = useRouter();
const uploadStore = useUploadStore();

let documentId: string | null = null;
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const isProcessing = computed(() =>
  ['queued', 'processing', 'ingesting'].includes(uploadStore.status?.status ?? ''),
);

onBeforeUnmount(() => {
  if (pollTimer) clearTimeout(pollTimer);
  uploadStore.reset();
});

function statusLabel(s: string): string {
  const map: Record<string, string> = {
    queued: 'รอดำเนินการ',
    processing: 'กำลังประมวลผล',
    ingesting: 'กำลังนำเข้าระบบ',
    done: 'เสร็จสิ้น',
    exported: 'ส่งออกแล้ว',
    ingested: 'นำเข้าแล้ว',
    failed: 'ล้มเหลว',
  };
  return map[s] ?? s;
}

function onUploaded(id: string): void {
  documentId = id;
  uploadStore.reset();
  pollStatus();
}

async function pollStatus(): Promise<void> {
  if (!documentId) return;
  const result = await uploadStore.pollOnce(documentId);
  if (result && ['queued', 'processing', 'ingesting'].includes(result.status)) {
    pollTimer = setTimeout(pollStatus, 1500);
  } else if (!result) {
    pollTimer = setTimeout(pollStatus, 2000);
  } else if (result.status === 'done' || result.status === 'exported') {
    pollTimer = setTimeout(() => {
      router.push(`/documents/${documentId!}/review`);
    }, 1000);
  }
}
</script>

<style scoped>
.admin-upload {
  max-width: 600px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.admin-upload__drop-card {
  border: 2px dashed var(--law-border);
  border-radius: 12px;
  padding: 40px 32px;
  text-align: center;
  background: var(--law-surface);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.admin-upload__drop-icon {
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

.admin-upload__status-chip--done,
.admin-upload__status-chip--exported,
.admin-upload__status-chip--ingested {
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

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.25s;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
