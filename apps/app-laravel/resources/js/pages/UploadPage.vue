<template>
  <section>
    <UploadForm @uploaded="onUploaded" />

    <section v-if="documentId" class="panel status-panel">
      <h3>Processing Status</h3>
      <p><strong>Document:</strong> {{ documentId }}</p>
      <p><strong>Status:</strong> {{ status?.status ?? 'loading' }}</p>
      <p><strong>Step:</strong> {{ status?.current_step ?? '-' }}</p>
      <p><strong>Progress:</strong> {{ status?.progress ?? 0 }}%</p>

      <button class="btn btn-primary" :disabled="status?.status !== 'done'" @click="goToReview">
        Open Review
      </button>
    </section>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import UploadForm from '../components/UploadForm.vue';
import { fetchStatus } from '../api/client';
import type { DocumentStatus } from '../types/document';

const router = useRouter();
const documentId = ref<string | null>(null);
const status = ref<DocumentStatus | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

function onUploaded(id: string): void {
  documentId.value = id;
  status.value = null;
  pollStatus();
}

async function pollStatus(): Promise<void> {
  if (!documentId.value) return;

  try {
    status.value = await fetchStatus(documentId.value);

    if (status.value.status === 'queued' || status.value.status === 'processing') {
      pollTimer = setTimeout(pollStatus, 1500);
    }
  } catch {
    pollTimer = setTimeout(pollStatus, 2000);
  }
}

function goToReview(): void {
  if (!documentId.value) return;
  router.push(`/documents/${documentId.value}/review`);
}
</script>
