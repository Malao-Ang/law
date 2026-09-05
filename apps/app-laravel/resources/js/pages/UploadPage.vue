<template>
  <HeaderComponent
    title="อัปโหลดเอกสาร"
    :breadcrumbs="[
      { text: 'หน้าแรก', to: '/' },
      { text: 'อัปโหลดเอกสาร' }
    ]"
  />

  <v-main>
    <v-container class="py-8">
      <v-row>
        <v-col cols="12">
          <UploadForm @uploaded="onUploaded" />
        </v-col>
      </v-row>

      <v-row v-if="uploadStore.status">
        <v-col cols="12">
          <v-card>
            <v-card-title>สถานะการประมวลผลเอกสาร</v-card-title>
            <v-card-text>
              <v-chip :color="getStatusColor(uploadStore.status.status)" class="mb-4">
                {{ getStatusText(uploadStore.status.status) }}
              </v-chip>

              <v-progress-linear
                v-if="['queued', 'processing', 'ingesting'].includes(uploadStore.status.status)"
                indeterminate
                color="primary"
                class="mb-4"
              ></v-progress-linear>

              <p v-if="uploadStore.status.scan_extraction_mode_requested" class="text-body-2 mb-1">
                Scan mode requested: {{ uploadStore.status.scan_extraction_mode_requested }}
              </p>
              <p v-if="uploadStore.status.scan_extraction_mode_effective" class="text-body-2 mb-4">
                Scan mode effective: {{ uploadStore.status.scan_extraction_mode_effective }}
              </p>
              <p v-if="uploadStore.status.extraction_path?.length" class="text-body-2 mb-1">
                Extraction path: {{ uploadStore.status.extraction_path.join(' -> ') }}
              </p>
              <p v-if="uploadStore.status.conversion" class="text-body-2 mb-1">
                Converted from .doc via {{ uploadStore.status.conversion.tool }} ({{ formatDuration(uploadStore.status.conversion.duration_ms) }})
              </p>
              <p v-if="uploadStore.status.timings" class="text-body-2 mb-4">
                Timings: {{ formatTimings(uploadStore.status.timings) }}
              </p>
              <v-alert v-if="uploadStore.pollError" type="error" class="mt-4">{{ uploadStore.pollError }}</v-alert>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>
    </v-container>
  </v-main>
</template>

<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import Swal from 'sweetalert2';
import UploadForm from '../components/shared/UploadForm.vue';
import HeaderComponent from '../components/shared/HeaderComponent.vue';
import { formatGeminiUploadError, useUploadStore } from '../stores/uploadStore';
import type { DocumentStatus } from '../types/document';

const router = useRouter();
const uploadStore = useUploadStore();
const documentId = ref<string | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let errorRetryCount = 0;

onUnmounted(() => {
  if (pollTimer !== null) {
    clearTimeout(pollTimer);
    pollTimer = null;
  }
  uploadStore.reset();
});

function getStatusColor(s: DocumentStatus['status']): string {
  switch (s) {
    case 'done': case 'exported': case 'ingested': return 'success';
    case 'processing': case 'ingesting': return 'warning';
    case 'queued': return 'info';
    default: return 'error';
  }
}

function getStatusText(s: DocumentStatus['status']): string {
  switch (s) {
    case 'queued': return 'รอดำเนินการ';
    case 'processing': return 'กำลังประมวลผล';
    case 'ingesting': return 'กำลังนำเข้าระบบ';
    case 'done': return 'เสร็จสิ้น';
    case 'exported': return 'ส่งออกแล้ว';
    case 'ingested': return 'นำเข้าระบบแล้ว';
    case 'failed': return 'ล้มเหลว';
    default: return s;
  }
}

function formatTimings(timings: Record<string, number>): string {
  return Object.entries(timings).map(([k, v]) => `${k}=${v}ms`).join(', ');
}

function formatDuration(ms?: number | null): string {
  if (!ms || ms <= 0) return '-';
  return `${(ms / 1000).toFixed(1)}s`;
}

function onUploaded(id: string): void {
  if (pollTimer !== null) {
    clearTimeout(pollTimer);
    pollTimer = null;
  }
  errorRetryCount = 0;
  uploadStore.reset();
  documentId.value = id;
  pollStatus();
}

async function pollStatus(): Promise<void> {
  if (!documentId.value) return;
  const result = await uploadStore.pollOnce(documentId.value);
  if (result) {
    errorRetryCount = 0;
    if (['queued', 'processing', 'ingesting'].includes(result.status)) {
      pollTimer = setTimeout(pollStatus, 1500);
    } else if (['done', 'exported', 'ingested'].includes(result.status)) {
      router.push(`/documents/${documentId.value}/review`);
    } else if (result.status === 'failed') {
      await Swal.fire({
        icon: 'error',
        title: 'นำเข้า PDF ไม่สำเร็จ',
        text: formatGeminiUploadError(result.error || 'Gemini OCR ไม่สำเร็จ'),
        confirmButtonText: 'ตกลง',
        confirmButtonColor: '#b42318',
        allowOutsideClick: true,
      });
    }
  } else {
    errorRetryCount++;
    if (errorRetryCount >= 10) {
      return;
    }
    pollTimer = setTimeout(pollStatus, 2000);
  }
}
</script>
