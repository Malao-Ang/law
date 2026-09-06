<template>
  <div class="upload-form">
    <div v-if="!pendingFile" class="mb-3">
      <div class="text-caption text-medium-emphasis mb-1">ประเภทเอกสาร</div>
      <v-btn-toggle v-model="documentType" mandatory divided density="comfortable" color="admin-primary">
        <v-btn value="new">เอกสารใหม่ (.doc, .docx)</v-btn>
        <v-btn value="old">เอกสารเก่า (.pdf)</v-btn>
      </v-btn-toggle>
    </div>

    <!-- Step 1: pick file -->
    <v-file-input
      v-if="!pendingFile"
      v-model="fileModel"
      :accept="acceptedExtensions"
      :label="fileInputLabel"
      variant="outlined"
      density="comfortable"
      hide-details
      @update:model-value="onFileSelected"
    />

    <!-- Step 2: confirm scan mode then upload -->
    <template v-else>
      <div class="file-preview">
        <v-icon :icon="fileIcon" size="20" color="admin-primary" class="mr-1" />
        <span class="text-body-2 font-weight-medium">{{ pendingFile.name }}</span>
        <v-chip size="x-small" variant="tonal" color="admin-primary" class="ml-2">{{ fileTypeLabel }}</v-chip>
      </div>

      <!-- scan mode selector — shown for all types; label changes per type -->
      <div v-if="documentType !== 'old'" class="mt-3">
        <div class="text-caption text-medium-emphasis mb-1">{{ modeLabel }}</div>
        <v-select
          v-model="scanMode"
          :items="modeOptions"
          item-title="title"
          item-value="value"
          density="compact"
          variant="outlined"
          hide-details
        />
        <div v-if="modeHint" class="text-caption text-medium-emphasis mt-1">{{ modeHint }}</div>
      </div>

      <div class="upload-form__actions mt-4">
        <v-btn variant="outlined" color="grey" :disabled="loading" @click="cancelPending">ยกเลิก</v-btn>
        <v-btn color="admin-primary" :loading="loading" prepend-icon="mdi-cloud-upload-outline" @click="doUpload">
          อัปโหลด
        </v-btn>
      </div>
    </template>

    <v-alert v-if="error" type="error" density="compact" class="mt-2">{{ error }}</v-alert>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import type { ScanExtractionMode } from '../../types/document';
import { useUploadStore } from '../../stores/uploadStore';

const emit = defineEmits<{ uploaded: [documentId: string] }>();
const uploadStore = useUploadStore();

const fileModel = ref<File | File[] | null>(null);
const pendingFile = ref<File | null>(null);
const documentType = ref<'new' | 'old'>('new');
const scanMode = ref<ScanExtractionMode>('local');
const loading = ref(false);
const error = ref<string | null>(null);

const fileExt = computed(() => pendingFile.value?.name.split('.').pop()?.toLowerCase() ?? '');

const isPdf = computed(() => fileExt.value === 'pdf');

const acceptedFileExtensions = computed(() => (documentType.value === 'old' ? ['pdf'] : ['doc', 'docx']));

const acceptedExtensions = computed(() => acceptedFileExtensions.value.map(ext => `.${ext}`).join(','));

const fileInputLabel = computed(() =>
  documentType.value === 'old'
    ? 'เลือกไฟล์เอกสารเก่า (.pdf)'
    : 'เลือกไฟล์เอกสารใหม่ (.doc, .docx)',
);

const extractionEngine = computed((): 'fast' | 'standard' => {
  return documentType.value === 'old' ? 'standard' : 'fast';
});

const fileTypeLabel = computed(() => ({ pdf: 'PDF', docx: 'DOCX', doc: 'DOC' }[fileExt.value] ?? fileExt.value.toUpperCase()));

const fileIcon = computed(() =>
  isPdf.value ? 'mdi-file-pdf-box' : 'mdi-file-word-box',
);

const modeLabel = computed(() =>
  isPdf.value ? 'โหมด OCR สำหรับ PDF' : 'โหมดการประมวลผล',
);

const modeOptions = computed(() =>
  isPdf.value
    ? [
        { title: 'Gemini Vision — Google AI (แนะนำสำหรับ PDF scan)', value: 'gemini' },
        { title: 'LandingAI ADE', value: 'landingai' },
      ]
    : [
        { title: 'Fast PHP extraction (แนะนำ)', value: 'local' },
      ],
);

const modeHint = computed(() => {
  if (isPdf.value && (scanMode.value === 'gemini' || scanMode.value === 'landingai')) {
    return 'ใช้ cloud OCR ตามที่เลือก — ถ้าอ่านเอกสารไม่สำเร็จจะแจ้งทันที';
  }
  return '';
});

function onFileSelected(file: File | File[] | null): void {
  const f = Array.isArray(file) ? (file[0] ?? null) : file;
  if (!f) return;
  const ext = f.name.split('.').pop()?.toLowerCase() ?? '';
  if (!acceptedFileExtensions.value.includes(ext)) {
    fileModel.value = null;
    error.value = documentType.value === 'old'
      ? 'เอกสารเก่ารับเฉพาะไฟล์ PDF'
      : 'เอกสารใหม่รับเฉพาะไฟล์ DOC หรือ DOCX';
    return;
  }

  pendingFile.value = f;
  error.value = null;
  scanMode.value = documentType.value === 'old' ? 'gemini' : 'local';
}

async function doUpload(): Promise<void> {
  if (!pendingFile.value) return;
  loading.value = true;
  error.value = null;
  try {
    const documentId = await uploadStore.upload(
      pendingFile.value,
      scanMode.value,
      extractionEngine.value,
      { documentType: documentType.value },
    );
    emit('uploaded', documentId);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'อัปโหลดไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

function cancelPending(): void {
  pendingFile.value = null;
  fileModel.value = null;
  error.value = null;
  scanMode.value = 'local';
}
</script>

<style scoped>
.file-preview {
  display: flex;
  align-items: center;
  padding: 10px 14px;
  background: #f1f5f9;
  border-radius: 8px;
  margin-bottom: 4px;
}

.upload-form__actions {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
}
</style>
