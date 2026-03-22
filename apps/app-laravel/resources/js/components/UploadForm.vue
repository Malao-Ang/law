<template>
  <v-card class="mx-auto" max-width="600">
    <v-card-title class="text-h5">Upload Document</v-card-title>
    <v-card-subtitle>Supported: .docx, .pdf</v-card-subtitle>

    <v-card-text>
      <v-file-input
        v-model="selectedFile"
        label="Select file"
        accept=".docx,.pdf"
        prepend-icon="mdi-file-upload"
        show-size
        :error-messages="error"
        @change="onFileChange"
      ></v-file-input>

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
import { uploadDocument } from '../api/client';

const emit = defineEmits<{
  uploaded: [documentId: string];
}>();

const selectedFile = ref<File | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

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
    const response = await uploadDocument(selectedFile.value);
    emit('uploaded', response.document_id);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Upload failed';
  } finally {
    loading.value = false;
  }
}
</script>
