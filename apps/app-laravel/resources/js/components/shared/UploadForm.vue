<template>
  <div class="upload-form">
    <input
      ref="fileInput"
      type="file"
      accept=".doc,.docx,.pdf"
      class="upload-form__hidden-input"
      @change="onFileSelected"
    />
    <div class="upload-form__scan-mode">
      <label class="upload-form__label" for="scan-mode">โหมด OCR สำหรับ PDF สแกน</label>
      <select id="scan-mode" v-model="scanExtractionMode" class="upload-form__select" :disabled="loading">
        <option value="auto">auto — EasyOCR ก่อน แล้ว fallback cloud</option>
        <option value="gemini">gemini — Google Gemini Vision</option>
        <option value="landingai">landingai — LandingAI ADE Parse</option>
        <option value="local">local — EasyOCR ในเครื่อง</option>
      </select>
    </div>
    <div class="upload-form__actions">
      <v-btn
        color="#1a3673"
        size="large"
        :loading="loading"
        @click="fileInput?.click()"
      >
        เลือกไฟล์จากเครื่อง
      </v-btn>
      <v-btn
        variant="outlined"
        size="large"
        :disabled="loading"
        @click="reset"
      >
        ยกเลิก
      </v-btn>
    </div>
    <p v-if="selectedFileName" class="upload-form__filename">
      ไฟล์ที่เลือก: {{ selectedFileName }}
    </p>
    <p v-if="error" class="upload-form__error">{{ error }}</p>
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

const scanExtractionMode = ref<ScanExtractionMode>('gemini');

function extractionEngineFor(file: File, scanMode: ScanExtractionMode): 'standard' | 'fast' {
  const isPdf = file.name.toLowerCase().endsWith('.pdf');
  // PDF + explicit OCR mode must use the Python pipeline (Gemini / LandingAI / EasyOCR).
  if (isPdf && scanMode !== 'auto') {
    return 'standard';
  }

  return 'fast';
}

const fileInput = ref<HTMLInputElement | null>(null);
const selectedFileName = ref<string | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

async function onFileSelected(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0] ?? null;
  if (!file) return;
  selectedFileName.value = file.name;
  error.value = null;
  loading.value = true;
  try {
    const documentId = await uploadStore.upload(
      file,
      scanExtractionMode.value,
      extractionEngineFor(file, scanExtractionMode.value),
    );
    emit('uploaded', documentId);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'อัปโหลดไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

function reset(): void {
  selectedFileName.value = null;
  error.value = null;
  if (fileInput.value) fileInput.value.value = '';
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
}

.upload-form__filename {
  font-size: 13px;
  color: #64748b;
  margin-top: 8px;
  text-align: center;
}

.upload-form__error {
  font-size: 13px;
  color: #ef4444;
  margin-top: 8px;
  text-align: center;
}
</style>
