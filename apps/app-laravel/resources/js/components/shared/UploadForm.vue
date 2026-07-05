<template>
  <div class="upload-form">
    <v-file-input
      v-model="fileModel"
      accept=".doc,.docx,.pdf"
      label="เลือกไฟล์ (.doc, .docx, .pdf)"
      variant="outlined"
      density="comfortable"
      :loading="loading"
      :disabled="loading"
      hide-details
      @update:model-value="onFileSelected"
    />
    <div class="upload-form__scan-mode">
      <label class="upload-form__label" for="scan-mode">โหมด OCR สำหรับ PDF สแกน</label>
      <select id="scan-mode" v-model="scanExtractionMode" class="upload-form__select" :disabled="loading">
        <option value="gemini">Gemini — Google Gemini Vision</option>
        <option value="local">OCR Library — EasyOCR ในเครื่อง</option>
      </select>
    </div>
    <div class="upload-form__actions">
      <v-btn
        variant="outlined"
        size="large"
        :disabled="loading"
        @click="reset"
      >
        ยกเลิก
      </v-btn>
    </div>
    <v-alert v-if="error" type="error" density="compact" class="mt-2">{{ error }}</v-alert>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import type { ScanExtractionMode } from '../../types/document';
import { useUploadStore } from '../../stores/uploadStore';

const emit = defineEmits<{
  uploaded: [documentId: string];
}>();

const uploadStore = useUploadStore();

// Hidden defaults — sent to API, not shown in UI; fast = PHP-side extraction, falls back to Python for scans
const extractionEngine: 'standard' | 'fast' = 'fast';
const scanExtractionMode = ref<ScanExtractionMode>('gemini');

const fileModel = ref<File | File[] | null>(null);
const selectedFileName = ref<string | null>(null); // ponytail: kept for compat; v-file-input displays selection
const loading = ref(false);
const error = ref<string | null>(null);

async function onFileSelected(file: File | File[] | null): Promise<void> {
  const f = Array.isArray(file) ? (file[0] ?? null) : file;
  if (!f) return;
  selectedFileName.value = f.name;
  error.value = null;
  loading.value = true;
  try {
    const documentId = await uploadStore.upload(
      f,
      scanExtractionMode.value,
      extractionEngine,
    );
    emit('uploaded', documentId);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'อัปโหลดไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

function reset(): void {
  fileModel.value = null;
  selectedFileName.value = null;
  error.value = null;
}
</script>

<style scoped>
.upload-form__hidden-input {
  display: none;
}

.upload-form__scan-mode {
  margin-bottom: 16px;
  text-align: center;
}

.upload-form__label {
  display: block;
  font-size: 13px;
  color: #475569;
  margin-bottom: 6px;
}

.upload-form__select {
  min-width: 280px;
  max-width: 100%;
  padding: 8px 12px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-size: 14px;
  background: #fff;
}

.upload-form__actions {
  display: flex;
  gap: 12px;
  justify-content: center;
  flex-wrap: wrap;
  margin-top: 8px;
}
</style>
