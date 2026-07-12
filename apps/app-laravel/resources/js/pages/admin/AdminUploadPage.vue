<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล']"
    title="การนำเข้าเอกสารกฎหมาย"
    subtitle="อัปโหลดไฟล์หลายรายการ แล้วติดตามสถานะการประมวลผลจากตารางด้านล่าง"
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
              รองรับไฟล์ PDF, DOC และ DOCX ระบบจะอัปโหลดทีละไฟล์และอัปเดตสถานะในตารางอัตโนมัติ
            </p>
          </div>
        </div>

        <v-row align="end" class="ga-2">
          <v-col cols="12" md="8">
            <v-file-input
              v-model="selectedFiles"
              :disabled="uploading"
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
              :disabled="uploading"
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
          <v-progress-circular
            v-if="uploading"
            indeterminate
            size="16"
            width="2"
            color="admin-primary"
          />
          <v-icon v-else icon="mdi-information-outline" size="16" />
          <span>
            {{ uploading ? 'กำลังอัปโหลดไฟล์ โปรดรอสักครู่' : 'Gemini เหมาะกับเอกสารสแกน ส่วน OCR Library ใช้ประมวลผลในเครื่อง' }}
          </span>
        </div>
      </v-card>

      <DocumentPipelineTable ref="pipelineTable" class="mt-5" />

      <v-snackbar
        :model-value="Boolean(errorMsg)"
        color="error"
        timeout="5000"
        location="bottom right"
        @update:model-value="dismissError"
      >
        {{ errorMsg }}
        <template #actions>
          <v-btn variant="text" color="white" @click="dismissError">ปิด</v-btn>
        </template>
      </v-snackbar>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import AppShell from '../../components/shared/AppShell.vue';
import DocumentPipelineTable from '../../components/admin/DocumentPipelineTable.vue';
import { uploadDocument } from '../../api/client';
import type { ScanExtractionMode } from '../../types/document';

const selectedFiles = ref<File[]>([]);
const scanMode = ref<ScanExtractionMode>('gemini');
const uploading = ref(false);
const errorMsg = ref('');
const pipelineTable = ref<InstanceType<typeof DocumentPipelineTable> | null>(null);

const scanModeOptions: Array<{ title: string; value: ScanExtractionMode }> = [
  { title: 'Gemini Vision', value: 'gemini' },
  { title: 'OCR Library (Local)', value: 'local' },
];

async function addFiles(value: File | File[] | null): Promise<void> {
  const files = normalizeFiles(value);
  if (files.length === 0 || uploading.value) {
    selectedFiles.value = [];
    return;
  }

  uploading.value = true;

  try {
    for (const file of files) {
      try {
        await uploadDocument(file, scanMode.value, extractionEngine(scanMode.value));
        await pipelineTable.value?.load();
      } catch (error) {
        const message = error instanceof Error ? error.message : 'อัปโหลดไม่สำเร็จ';
        errorMsg.value = `${file.name}: ${message}`;
      }
    }
  } finally {
    uploading.value = false;
    selectedFiles.value = [];
  }
}

function normalizeFiles(value: File | File[] | null): File[] {
  if (!value) return [];
  return Array.isArray(value) ? value : [value];
}

function extractionEngine(mode: ScanExtractionMode): 'standard' | 'fast' {
  return mode === 'local' ? 'fast' : 'standard';
}

function dismissError(): void {
  errorMsg.value = '';
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
</style>
