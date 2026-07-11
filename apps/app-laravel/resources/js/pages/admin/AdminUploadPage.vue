<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล']"
    title="การนำเข้าเอกสารกฎหมาย"
    subtitle="อัปโหลดไฟล์หลายรายการ แล้วติดตามสถานะการประมวลผลในคิว"
  >
    <div class="admin-upload-page mx-auto">
      <v-card class="admin-upload-panel pa-6" flat border rounded="lg">
        <div class="d-flex flex-column flex-md-row align-md-center ga-4 mb-5">
          <div class="admin-upload-panel__icon">
            <v-icon icon="mdi-cloud-upload-outline" size="34" />
          </div>
          <div class="flex-grow-1">
            <h2 class="text-h6 font-weight-bold mb-1">เลือกไฟล์เพื่อนำเข้าพร้อมกัน</h2>
            <p class="text-body-2 text-medium-emphasis mb-0">
              รองรับไฟล์ PDF, DOC และ DOCX ระบบจะเพิ่มเข้า queue และประมวลผลแยกแต่ละไฟล์
            </p>
          </div>
        </div>

        <v-row align="end" class="ga-2">
          <v-col cols="12" md="8">
            <v-file-input
              v-model="selectedFiles"
              accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
              chips
              clearable
              counter
              hide-details
              label="เลือกไฟล์เอกสาร"
              multiple
              prepend-icon=""
              prepend-inner-icon="mdi-file-document-plus-outline"
              rounded="lg"
              show-size
              variant="outlined"
              @update:model-value="addFiles"
            />
          </v-col>
          <v-col cols="12" md="4">
            <v-select
              v-model="scanMode"
              :items="scanModeOptions"
              hide-details
              item-title="title"
              item-value="value"
              label="วิธีอ่านข้อความสแกน"
              rounded="lg"
              variant="outlined"
            />
          </v-col>
        </v-row>

        <div class="d-flex flex-wrap align-center ga-2 mt-4 text-caption text-medium-emphasis">
          <v-icon icon="mdi-information-outline" size="16" />
          <span>Gemini เหมาะกับเอกสารสแกน ส่วน OCR Library ใช้ประมวลผลในเครื่อง</span>
        </div>
      </v-card>

      <v-card class="mt-5" flat border rounded="lg">
        <v-card-title class="d-flex flex-wrap align-center ga-3 pa-5">
          <div class="flex-grow-1">
            <div class="text-subtitle-1 font-weight-bold">คิวการนำเข้าเอกสาร</div>
            <div class="text-caption text-medium-emphasis">ติดตามสถานะไฟล์ที่กำลังอัปโหลดและประมวลผล</div>
          </div>
          <v-chip color="admin-primary" variant="tonal" rounded="pill">
            {{ queue.length }} รายการ
          </v-chip>
        </v-card-title>
        <v-divider />

        <div v-if="queue.length === 0" class="admin-upload-empty pa-10 text-center">
          <v-icon icon="mdi-tray-arrow-up" size="54" color="grey" />
          <div class="text-body-1 font-weight-bold mt-3">ยังไม่มีไฟล์ในคิว</div>
          <div class="text-body-2 text-medium-emphasis">เลือกไฟล์หลายรายการจากช่องด้านบนเพื่อเริ่มอัปโหลด</div>
        </div>

        <div v-else class="admin-upload-queue pa-4">
          <div
            v-for="item in queue"
            :key="item.id"
            class="admin-upload-queue__item"
            :class="`admin-upload-queue__item--${item.status}`"
          >
            <div class="admin-upload-queue__content">
              <div class="admin-upload-queue__file-icon">
                <v-icon :icon="fileIcon(item.fileName)" size="24" />
              </div>

              <div class="admin-upload-queue__main">
                <div class="d-flex flex-wrap align-center ga-2 mb-1">
                  <p class="admin-upload-queue__filename mb-0">{{ item.fileName }}</p>
                  <v-chip size="x-small" :color="statusColor(item.status)" variant="tonal" rounded="pill">
                    {{ statusLabel(item.status) }}
                  </v-chip>
                  <v-chip size="x-small" color="grey" variant="tonal" rounded="pill">
                    {{ scanModeLabel(item.scanMode) }}
                  </v-chip>
                </div>

                <p class="text-caption text-medium-emphasis mb-2">
                  {{ item.error || item.currentStep || 'กำลังเตรียมไฟล์สำหรับอัปโหลด' }}
                </p>

                <v-progress-linear
                  v-if="isActive(item)"
                  :indeterminate="item.progress <= 0"
                  :model-value="item.progress"
                  color="admin-primary"
                  height="5"
                  rounded
                />
              </div>
            </div>

            <div class="admin-upload-queue__actions">
              <v-btn
                v-if="canEdit(item)"
                color="admin-primary"
                prepend-icon="mdi-pencil-outline"
                rounded="lg"
                size="small"
                variant="flat"
                @click="goToReview(item)"
              >
                แก้ไขเอกสาร
              </v-btn>
              <v-btn
                v-else-if="item.status === 'failed'"
                color="admin-primary"
                prepend-icon="mdi-refresh"
                rounded="lg"
                size="small"
                variant="tonal"
                @click="retryUpload(item)"
              >
                ลองใหม่
              </v-btn>
              <v-btn
                v-if="!isActive(item)"
                icon="mdi-close"
                size="small"
                variant="text"
                color="grey"
                @click="removeItem(item)"
              />
            </div>
          </div>
        </div>
      </v-card>

      <DocumentPipelineTable class="mt-5" />
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchStatus, uploadDocument } from '../../api/client';
import AppShell from '../../components/shared/AppShell.vue';
import DocumentPipelineTable from '../../components/admin/DocumentPipelineTable.vue';
import type { DocumentStatus, ScanExtractionMode } from '../../types/document';

type UploadQueueStatus = DocumentStatus['status'] | 'pending' | 'uploading';

interface UploadQueueItem {
  id: string;
  file: File;
  fileName: string;
  documentId: string | null;
  status: UploadQueueStatus;
  currentStep: string;
  progress: number;
  error: string;
  scanMode: ScanExtractionMode;
}

const router = useRouter();
const selectedFiles = ref<File[]>([]);
const scanMode = ref<ScanExtractionMode>('gemini');
const queue = ref<UploadQueueItem[]>([]);
const pollTimers = new Map<string, ReturnType<typeof setTimeout>>();

const scanModeOptions: Array<{ title: string; value: ScanExtractionMode }> = [
  { title: 'Gemini Vision', value: 'gemini' },
  { title: 'OCR Library (Local)', value: 'local' },
];

onBeforeUnmount(() => {
  pollTimers.forEach((timer) => clearTimeout(timer));
  pollTimers.clear();
});

function addFiles(value: File | File[] | null): void {
  const files = normalizeFiles(value);
  if (files.length === 0) return;

  files.forEach((file) => {
    const item: UploadQueueItem = {
      id: `${Date.now()}-${crypto.randomUUID()}`,
      file,
      fileName: file.name,
      documentId: null,
      status: 'pending',
      currentStep: 'รออัปโหลด',
      progress: 0,
      error: '',
      scanMode: scanMode.value,
    };
    queue.value.unshift(item);
    void startUpload(item);
  });

  selectedFiles.value = [];
}

async function startUpload(item: UploadQueueItem): Promise<void> {
  clearItemTimer(item);
  item.status = 'uploading';
  item.currentStep = 'กำลังอัปโหลดไฟล์';
  item.progress = 0;
  item.error = '';

  try {
    const response = await uploadDocument(item.file, item.scanMode, extractionEngine(item.scanMode));
    item.documentId = response.document_id;
    item.status = response.status;
    item.currentStep = 'อยู่ในคิวประมวลผล';
    item.progress = 5;
    void pollItem(item);
  } catch (error) {
    item.status = 'failed';
    item.currentStep = 'อัปโหลดไม่สำเร็จ';
    item.error = error instanceof Error ? error.message : 'อัปโหลดไม่สำเร็จ';
  }
}

async function pollItem(item: UploadQueueItem): Promise<void> {
  if (!item.documentId) return;
  pollTimers.delete(item.id);

  try {
    const status = await fetchStatus(item.documentId);
    applyStatus(item, status);

    if (isProcessingStatus(status.status)) {
      pollTimers.set(item.id, setTimeout(() => void pollItem(item), 1500));
    }
  } catch (error) {
    item.currentStep = 'เชื่อมต่อเพื่อตรวจสอบสถานะไม่สำเร็จ กำลังลองใหม่';
    item.error = error instanceof Error ? error.message : '';
    pollTimers.set(item.id, setTimeout(() => void pollItem(item), 2500));
  }
}

function applyStatus(item: UploadQueueItem, status: DocumentStatus): void {
  item.documentId = status.document_id;
  item.status = status.status;
  item.progress = status.progress ?? item.progress;
  item.currentStep = status.current_step || statusLabel(status.status);
  item.fileName = status.source_file || item.fileName;
  item.error = status.error || '';
}

function retryUpload(item: UploadQueueItem): void {
  item.documentId = null;
  void startUpload(item);
}

function removeItem(item: UploadQueueItem): void {
  clearItemTimer(item);
  queue.value = queue.value.filter((queueItem) => queueItem.id !== item.id);
}

function goToReview(item: UploadQueueItem): void {
  if (!item.documentId) return;
  router.push(`/documents/${item.documentId}/review`);
}

function normalizeFiles(value: File | File[] | null): File[] {
  if (!value) return [];
  return Array.isArray(value) ? value : [value];
}

function extractionEngine(mode: ScanExtractionMode): 'standard' | 'fast' {
  return mode === 'local' ? 'fast' : 'standard';
}

function clearItemTimer(item: UploadQueueItem): void {
  const timer = pollTimers.get(item.id);
  if (!timer) return;
  clearTimeout(timer);
  pollTimers.delete(item.id);
}

function isProcessingStatus(status: UploadQueueStatus): boolean {
  return ['queued', 'processing', 'ingesting', 'uploading', 'pending'].includes(status);
}

function isActive(item: UploadQueueItem): boolean {
  return isProcessingStatus(item.status);
}

function canEdit(item: UploadQueueItem): boolean {
  return Boolean(item.documentId) && ['done', 'exported', 'ingested'].includes(item.status);
}

function statusLabel(status: UploadQueueStatus): string {
  const map: Record<UploadQueueStatus, string> = {
    pending: 'รออัปโหลด',
    uploading: 'กำลังอัปโหลด',
    queued: 'รอดำเนินการ',
    processing: 'กำลังประมวลผล',
    ingesting: 'กำลังนำเข้าระบบ',
    done: 'เสร็จสิ้น',
    exported: 'ส่งออกแล้ว',
    ingested: 'นำเข้าแล้ว',
    failed: 'ล้มเหลว',
  };
  return map[status] ?? status;
}

function statusColor(status: UploadQueueStatus): string {
  if (['done', 'exported', 'ingested'].includes(status)) return 'success';
  if (status === 'failed') return 'error';
  if (['queued', 'processing', 'ingesting', 'uploading', 'pending'].includes(status)) return 'warning';
  return 'grey';
}

function scanModeLabel(mode: ScanExtractionMode): string {
  return mode === 'gemini' ? 'Gemini' : 'OCR Library';
}

function fileIcon(fileName: string): string {
  const lowerName = fileName.toLowerCase();
  if (lowerName.endsWith('.pdf')) return 'mdi-file-pdf-box';
  if (lowerName.endsWith('.doc') || lowerName.endsWith('.docx')) return 'mdi-file-word-box';
  return 'mdi-file-document-outline';
}
</script>

<style scoped>
.admin-upload-page {
  max-width: 980px;
}

.admin-upload-panel {
  background: #ffffff;
}

.admin-upload-panel__icon {
  align-items: center;
  background: rgba(var(--v-theme-admin-primary), 0.12);
  border-radius: 18px;
  color: rgb(var(--v-theme-admin-primary));
  display: inline-flex;
  height: 58px;
  justify-content: center;
  width: 58px;
}

.admin-upload-empty {
  background: #ffffff;
}

.admin-upload-queue {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.admin-upload-queue__item {
  align-items: center;
  background: #ffffff;
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-left: 4px solid rgb(var(--v-theme-admin-primary));
  border-radius: 18px;
  display: flex;
  gap: 16px;
  justify-content: space-between;
  padding: 16px;
}

.admin-upload-queue__item--failed {
  border-left-color: rgb(var(--v-theme-error));
}

.admin-upload-queue__item--done,
.admin-upload-queue__item--exported,
.admin-upload-queue__item--ingested {
  border-left-color: rgb(var(--v-theme-success));
}

.admin-upload-queue__content {
  align-items: flex-start;
  display: flex;
  flex: 1 1 auto;
  gap: 14px;
  min-width: 0;
}

.admin-upload-queue__file-icon {
  align-items: center;
  background: rgba(var(--v-theme-admin-primary), 0.10);
  border-radius: 14px;
  color: rgb(var(--v-theme-admin-primary));
  display: inline-flex;
  flex: 0 0 auto;
  height: 44px;
  justify-content: center;
  width: 44px;
}

.admin-upload-queue__main {
  min-width: 0;
  width: 100%;
}

.admin-upload-queue__filename {
  font-weight: 700;
  max-width: min(100%, 520px);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.admin-upload-queue__actions {
  align-items: center;
  display: flex;
  flex: 0 0 auto;
  gap: 6px;
}

@media (max-width: 720px) {
  .admin-upload-queue__item {
    align-items: stretch;
    flex-direction: column;
  }

  .admin-upload-queue__actions {
    justify-content: flex-end;
  }

  .admin-upload-queue__filename {
    max-width: 100%;
  }
}
</style>
