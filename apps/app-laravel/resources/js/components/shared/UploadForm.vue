<template>
  <v-card class="mx-auto" max-width="600">
    <v-card-title class="text-h5">Upload Document</v-card-title>
    <v-card-subtitle>Supported: .doc, .docx, .pdf</v-card-subtitle>

    <v-card-text>
      <v-file-input
        v-model="selectedFile"
        label="Select file"
        accept=".doc,.docx,.pdf"
        prepend-icon="mdi-file-upload"
        show-size
        :error-messages="error"
        @change="onFileChange"
      ></v-file-input>

      <v-select
        v-model="extractionEngine"
        label="Extraction engine"
        :items="engineOptions"
        item-title="title"
        item-value="value"
        density="comfortable"
        hint="Fast = PHP-only for DOCX, .doc, and text-PDF. Standard = full Python pipeline."
        persistent-hint
      ></v-select>

      <v-select
        v-model="scanExtractionMode"
        label="PDF parser engine (scanned pages)"
        :items="scanModeOptions"
        item-title="title"
        item-value="value"
        density="comfortable"
        hint="Used by the Python pipeline, and when Fast mode falls back for scanned PDFs."
        persistent-hint
      ></v-select>

      <v-btn
        color="primary"
        size="large"
        block
        :disabled="!selectedFile || loading"
        :loading="loading"
        @click="submitUpload"
      >
        {{ loading ? 'Uploading...' : 'Upload' }}
      </v-btn>

      <v-chip
        v-if="selectedFile"
        color="info"
        class="mt-4"
        prepend-icon="mdi-file-check"
      >
        Selected: {{ selectedFile.name }}
      </v-chip>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useUploadStore } from '../../stores/upload';

const emit = defineEmits<{
  uploaded: [documentId: string];
}>();

const uploadStore = useUploadStore();

const selectedFile = ref<File | null>(null);
const extractionEngine = ref<'standard' | 'fast'>('standard');
const scanExtractionMode = ref<'auto' | 'local' | 'landingai'>('auto');
const loading = ref(false);
const error = ref<string | null>(null);
const engineOptions = [
  { title: 'Standard (Python) — recommended', value: 'standard' },
  { title: 'Fast (PHP) — immediate review page', value: 'fast' },
];
const scanModeOptions = [
  { title: 'Auto (local first, fallback)', value: 'auto' },
  { title: 'Local OCR only', value: 'local' },
  { title: 'LandingAI Parse', value: 'landingai' },
];

function onFileChange(event: Event): void {
  const target = event.target as HTMLInputElement;
  selectedFile.value = target.files?.[0] ?? null;
  error.value = null;
}

async function submitUpload(): Promise<void> {
  if (!selectedFile.value || loading.value) {
    return;
  }

  loading.value = true;
  error.value = null;

  try {
    const documentId = await uploadStore.upload(
      selectedFile.value,
      scanExtractionMode.value,
      extractionEngine.value,
    );
    emit('uploaded', documentId);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Upload failed';
  } finally {
    loading.value = false;
  }
}
</script>
