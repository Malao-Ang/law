<template>
  <div class="upload-form">
    <input
      ref="fileInput"
      type="file"
      accept=".doc,.docx,.pdf"
      class="upload-form__hidden-input"
      @change="onFileSelected"
    />
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
import { useUploadStore } from '../../stores/uploadStore';


const emit = defineEmits<{
  uploaded: [documentId: string];
}>();

const uploadStore = useUploadStore();

// Hidden defaults — sent to API, not shown in UI; fast = PHP-side extraction, falls back to Python for scans
const extractionEngine: 'standard' | 'fast' = 'fast';
const scanExtractionMode: 'auto' | 'local' | 'landingai' = 'auto';

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
      scanExtractionMode,
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
  selectedFileName.value = null;
  error.value = null;
  if (fileInput.value) fileInput.value.value = '';
}
</script>

<style scoped>
.upload-form__hidden-input {
  display: none;
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
