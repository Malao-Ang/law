<template>
  <section class="panel">
    <h2>Upload Document</h2>
    <p class="hint">Supported: .docx, .pdf</p>

    <input type="file" accept=".docx,.pdf" @change="onFileChange" />

    <button class="btn" :disabled="!selectedFile || loading" @click="submitUpload">
      {{ loading ? 'Uploading...' : 'Upload' }}
    </button>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="selectedFile" class="hint">Selected: {{ selectedFile.name }}</p>
  </section>
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
