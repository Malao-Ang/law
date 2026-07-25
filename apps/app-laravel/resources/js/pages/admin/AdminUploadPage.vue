<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล']"
    :title="pendingFile ? 'นำเข้าเอกสารกฎหมาย' : 'การนำเข้าเอกสารกฎหมาย'"
    :subtitle="pendingFile
      ? 'ขั้นตอนที่ 1 จาก 5: ตรวจสอบและจัดรูปแบบเอกสาร'
      : 'อัปโหลดไฟล์เพื่อเตรียมสกัดเนื้อหาเข้าสู่ระบบฐานข้อมูล'"
    show-bell
  >
    <div class="adm-up">

      <!-- ── Step 0: Drop zone ──────────────────────────── -->
      <template v-if="!pendingFile">
        <div
          class="adm-drop"
          :class="{ 'adm-drop--over': dragOver }"
          @dragover.prevent="dragOver = true"
          @dragleave.prevent="dragOver = false"
          @drop.prevent="onDrop"
        >
          <input
            ref="fileInputEl"
            type="file"
            accept=".pdf,.doc,.docx"
            class="adm-drop__input"
            @change="onInputChange"
          />
          <div class="adm-drop__icon">
            <v-icon icon="mdi-cloud-upload-outline" size="30" color="white" />
          </div>
          <p class="adm-drop__title">ลากและวางไฟล์ข้อมูลกฎหมาย</p>
          <p class="adm-drop__sub">
            รองรับไฟล์ .PDF หรือ .DOCX เพื่อให้ระบบทำการแกะโครงสร้าง<br>มาตราโดยอัตโนมัติ
          </p>
          <div class="adm-drop__btns">
            <button type="button" class="adm-btn adm-btn--primary" @click="fileInputEl?.click()">
              เลือกไฟล์จากเครื่อง
            </button>
            <button type="button" class="adm-btn adm-btn--ghost">ยกเลิก</button>
          </div>
        </div>
      </template>

      <!-- ── Step 1: File selected ──────────────────────── -->
      <template v-else>
        <!-- Card 1: File info -->
        <div class="adm-card mb-4">
          <h3 class="adm-card__head">ไฟล์เอกสาร</h3>
          <div class="adm-file-row">
            <div class="adm-file-row__icon">
              <v-icon :icon="fileIcon" size="22" color="white" />
            </div>
            <div class="adm-file-row__body">
              <span class="adm-file-row__name">{{ pendingFile.name }}</span>
              <span class="adm-file-row__meta">{{ fileSize }} • อัปโหลดเมื่อ {{ uploadedAt }}</span>
            </div>
            <button type="button" class="adm-file-row__change" @click="cancelPending">
              <v-icon icon="mdi-file-replace-outline" size="15" />
              เปลี่ยนไฟล์
            </button>
          </div>
        </div>

        <!-- Card 2: Status + OCR selector + CTA -->
        <div class="adm-card mb-4">
          <h3 class="adm-card__head">สถานะการจัดรูปแบบเอกสาร</h3>

          <div class="adm-warn mb-4">
            <v-icon icon="mdi-alert" size="18" color="#d97706" class="flex-shrink-0" style="margin-top:2px" />
            <div>
              <p class="adm-warn__title mb-1">ยังไม่ได้ตรวจสอบรูปแบบเนื้อหา</p>
              <p class="adm-warn__sub mb-0">
                กรุณาดเปิดตัวแก้ไขเพื่อตรวจสอบข้อความ หัวข้อ ลำดับข้อ และรูปแบบเอกสารก่อนดำเนินการต่อ
              </p>
            </div>
          </div>

          <!-- OCR method selector -->
          <p class="adm-label mb-1">{{ isPdf ? 'โหมด OCR สำหรับ PDF' : 'โหมดการประมวลผล' }}</p>
          <v-select
            v-model="scanMode"
            :items="modeOptions"
            item-title="title"
            item-value="value"
            density="compact"
            variant="outlined"
            hide-details
            rounded="lg"
            class="mb-1"
          />
          <p v-if="modeHint" class="adm-hint mb-4">{{ modeHint }}</p>
          <div v-else class="mb-4" />

          <v-alert v-if="uploadError" type="error" density="compact" rounded="lg" class="mb-3">
            {{ uploadError }}
          </v-alert>

          <button
            type="button"
            class="adm-btn adm-btn--primary"
            :disabled="uploading"
            @click="doUpload"
          >
            <v-progress-circular
              v-if="uploading"
              indeterminate
              size="16"
              width="2"
              color="white"
              class="mr-2"
            />
            <v-icon v-else icon="mdi-pencil-outline" size="16" class="mr-1" />
            {{ uploading ? 'กำลังอัปโหลด...' : 'เปิดตัวแก้ไขและจัดรูปแบบเนื้อหา' }}
          </button>
        </div>
      </template>

      <!-- ── Queue table (always visible) ──────────────── -->
      <DocumentPipelineTable ref="pipelineTable" class="mt-2" />

      <!-- ── Step nav bar ───────────────────────────────── -->
      <div v-if="pendingFile" class="adm-stepbar">
        <button type="button" class="adm-btn adm-btn--step-nav" @click="cancelPending">
          ← ย้อนกลับ
        </button>
        <button type="button" class="adm-btn adm-btn--step-nav" disabled>
          ถัดไป →
        </button>
      </div>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import AppShell from '../../components/shared/AppShell.vue';
import DocumentPipelineTable from '../../components/admin/DocumentPipelineTable.vue';
import type { ScanExtractionMode } from '../../types/document';
import { useUploadStore } from '../../stores/uploadStore';
import { useSnackbarStore } from '../../stores/snackbarStore';

const router = useRouter();
const uploadStore = useUploadStore();
const snackbar = useSnackbarStore();

const fileInputEl = ref<HTMLInputElement | null>(null);
const pendingFile = ref<File | null>(null);
const scanMode = ref<ScanExtractionMode>('local');
const dragOver = ref(false);
const uploading = ref(false);
const uploadError = ref('');
const uploadedAt = ref('');
const pipelineTable = ref<InstanceType<typeof DocumentPipelineTable> | null>(null);

const isPdf = computed(() =>
  (pendingFile.value?.name.split('.').pop()?.toLowerCase() ?? '') === 'pdf',
);

const fileIcon = computed(() => (isPdf.value ? 'mdi-file-pdf-box' : 'mdi-file-word-box'));

const fileSize = computed(() => {
  const b = pendingFile.value?.size ?? 0;
  return b < 1024 * 1024 ? `${(b / 1024).toFixed(0)} KB` : `${(b / (1024 * 1024)).toFixed(1)} MB`;
});

const modeOptions = computed(() =>
  isPdf.value
    ? [
        { title: 'Gemini Vision — Google AI (แนะนำสำหรับ PDF scan)', value: 'gemini' },
        { title: 'Auto — EasyOCR → cloud fallback', value: 'auto' },
        { title: 'LandingAI — ADE Parse', value: 'landingai' },
        { title: 'Local — EasyOCR ในเครื่อง', value: 'local' },
      ]
    : [
        { title: 'Local — Fast PHP extraction (แนะนำ)', value: 'local' },
        { title: 'Standard — Python Docling', value: 'auto' },
      ],
);

const modeHint = computed(() => {
  if (isPdf.value && scanMode.value === 'gemini') return 'ต้องตั้งค่า GEMINI_API_KEY ใน .env';
  if (isPdf.value && scanMode.value === 'landingai') return 'ต้องตั้งค่า VISION_AGENT_API_KEY ใน .env';
  if (!isPdf.value && scanMode.value === 'auto') return 'ส่งไฟล์ผ่าน Python Docling pipeline';
  return '';
});

const extractionEngine = computed((): 'fast' | 'standard' => {
  if (isPdf.value && scanMode.value !== 'auto') return 'standard';
  return scanMode.value === 'local' ? 'fast' : 'standard';
});

function selectFile(file: File): void {
  pendingFile.value = file;
  uploadError.value = '';
  scanMode.value = file.name.split('.').pop()?.toLowerCase() === 'pdf' ? 'gemini' : 'local';
  uploadedAt.value = new Intl.DateTimeFormat('th-TH', {
    day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  }).format(new Date());
}

function onInputChange(event: Event): void {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (file) selectFile(file);
  input.value = '';
}

function onDrop(event: DragEvent): void {
  dragOver.value = false;
  const file = event.dataTransfer?.files[0];
  if (file) selectFile(file);
}

function cancelPending(): void {
  pendingFile.value = null;
  uploadError.value = '';
  scanMode.value = 'local';
}

async function doUpload(): Promise<void> {
  if (!pendingFile.value || uploading.value) return;
  uploading.value = true;
  uploadError.value = '';
  try {
    const documentId = await uploadStore.upload(pendingFile.value, scanMode.value, extractionEngine.value);
    await pipelineTable.value?.load();
    await router.push({ name: 'review', params: { documentId } });
  } catch (error) {
    uploadError.value = error instanceof Error ? error.message : 'อัปโหลดไม่สำเร็จ';
    snackbar.error(uploadError.value);
  } finally {
    uploading.value = false;
  }
}
</script>

<style scoped>
.adm-up {
  max-width: 860px;
  margin: 0 auto;
}

/* ── Drop zone ──────────────────────────────────────────── */
.adm-drop {
  position: relative;
  background: #ffffff;
  border: 1.5px dashed #d1d5db;
  border-radius: 16px;
  padding: 52px 40px 44px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
}

.adm-drop--over {
  border-color: rgb(var(--v-theme-admin-primary));
  background: rgba(var(--v-theme-admin-primary), 0.03);
}

.adm-drop__input {
  position: absolute;
  inset: 0;
  opacity: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
}

.adm-drop__icon {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: #dbeafe;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 20px;
}

.adm-drop__icon :deep(.v-icon) {
  color: rgb(var(--v-theme-admin-primary)) !important;
}

.adm-drop__title {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 22px;
  font-weight: 700;
  color: rgb(var(--v-theme-admin-primary));
  margin: 0 0 10px;
}

.adm-drop__sub {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px;
  color: #6b7280;
  margin: 0 0 28px;
  line-height: 1.6;
}

.adm-drop__btns {
  display: flex;
  gap: 12px;
  align-items: center;
  position: relative;
  z-index: 1;
}

/* ── Cards ──────────────────────────────────────────────── */
.adm-card {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 24px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.adm-card__head {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 18px;
  font-weight: 700;
  color: #1e293b;
  margin: 0 0 16px;
}

/* ── File row ───────────────────────────────────────────── */
.adm-file-row {
  display: flex;
  align-items: center;
  gap: 14px;
  background: rgb(var(--v-theme-admin-primary));
  border-radius: 10px;
  padding: 14px 18px;
}

.adm-file-row__icon {
  width: 40px;
  height: 40px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.adm-file-row__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.adm-file-row__name {
  font-family: 'Sarabun', sans-serif;
  font-size: 15px;
  font-weight: 700;
  color: #ffffff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.adm-file-row__meta {
  font-family: 'Sarabun', sans-serif;
  font-size: 13px;
  color: rgba(255, 255, 255, 0.7);
}

.adm-file-row__change {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.3);
  border-radius: 8px;
  padding: 6px 14px;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 14px;
  color: #ffffff;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  transition: background 0.15s;
}

.adm-file-row__change:hover {
  background: rgba(255, 255, 255, 0.22);
}

/* ── Warning box ────────────────────────────────────────── */
.adm-warn {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 10px;
  padding: 14px 16px;
}

.adm-warn__title {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px;
  font-weight: 700;
  color: #92400e;
}

.adm-warn__sub {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 14px;
  color: #78350f;
  line-height: 1.5;
}

/* ── Labels / hints ─────────────────────────────────────── */
.adm-label {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 15px;
  font-weight: 700;
  color: #374151;
  display: block;
}

.adm-hint {
  font-family: 'Sarabun', sans-serif;
  font-size: 13px;
  color: #6b7280;
  margin-top: 4px;
}

/* ── Buttons ────────────────────────────────────────────── */
.adm-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border: none;
  cursor: pointer;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 17px;
  font-weight: 700;
  border-radius: 10px;
  padding: 11px 24px;
  transition: opacity 0.15s, background 0.15s;
  white-space: nowrap;
}

.adm-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.adm-btn--primary {
  background: rgb(var(--v-theme-admin-primary));
  color: #ffffff;
}

.adm-btn--primary:hover:not(:disabled) {
  opacity: 0.9;
}

.adm-btn--ghost {
  background: transparent;
  border: 1.5px solid #d1d5db;
  color: #374151;
}

.adm-btn--ghost:hover {
  background: #f9fafb;
}

/* ── Step nav bar ───────────────────────────────────────── */
.adm-stepbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 24px;
  padding: 16px 0 4px;
  border-top: 1px solid #e5e7eb;
}

.adm-btn--step-nav {
  background: transparent;
  border: 1.5px solid #d1d5db;
  color: #6b7280;
  font-size: 16px;
  padding: 9px 22px;
  border-radius: 9px;
}

.adm-btn--step-nav:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
</style>
