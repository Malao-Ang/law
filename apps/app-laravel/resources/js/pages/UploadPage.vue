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

      <v-row v-if="status">
        <v-col cols="12">
          <v-card>
            <v-card-title>สถานะการประมวลผลเอกสาร</v-card-title>
            <v-card-text>
              <v-chip :color="getStatusColor(status.status)" class="mb-4">
                {{ getStatusText(status.status) }}
              </v-chip>

              <v-progress-linear
                v-if="['queued', 'processing', 'ingesting'].includes(status.status)"
                indeterminate
                color="primary"
                class="mb-4"
              ></v-progress-linear>

              <p v-if="status.scan_extraction_mode_requested" class="text-body-2 mb-1">
                Scan mode requested: {{ status.scan_extraction_mode_requested }}
              </p>
              <p v-if="status.scan_extraction_mode_effective" class="text-body-2 mb-4">
                Scan mode effective: {{ status.scan_extraction_mode_effective }}
              </p>
              <p v-if="status.extraction_path?.length" class="text-body-2 mb-1">
                Extraction path: {{ status.extraction_path.join(' -> ') }}
              </p>
              <p v-if="status.conversion" class="text-body-2 mb-1">
                Converted from .doc via {{ status.conversion.tool }} ({{ formatDuration(status.conversion.duration_ms) }})
              </p>
              <p v-if="status.timings" class="text-body-2 mb-4">
                Timings: {{ formatTimings(status.timings) }}
              </p>
              <v-alert v-if="pollError" type="error" class="mt-4">{{ pollError }}</v-alert>
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
import UploadForm from '../components/UploadForm.vue';
import HeaderComponent from '../components/HeaderComponent.vue';
import { fetchStatus } from '../api/client';
import type { DocumentStatus } from '../types/document';

const router = useRouter();
const documentId = ref<string | null>(null);
const status = ref<DocumentStatus | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let errorRetryCount = 0;
const pollError = ref<string | null>(null);

onUnmounted(() => {
  if (pollTimer !== null) {
    clearTimeout(pollTimer);
    pollTimer = null;
  }
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
  pollError.value = null;
  documentId.value = id;
  status.value = null;
  pollStatus();
}

async function pollStatus(): Promise<void> {
  if (!documentId.value) return;
  try {
    status.value = await fetchStatus(documentId.value);
    if (['queued', 'processing', 'ingesting'].includes(status.value.status)) {
      pollTimer = setTimeout(pollStatus, 1500);
    } else if (['done', 'exported', 'ingested'].includes(status.value.status)) {
      router.push(`/documents/${documentId.value}/review`);
    }
  } catch {
    errorRetryCount++;
    if (errorRetryCount >= 10) {
      pollError.value = 'ไม่สามารถตรวจสอบสถานะได้ กรุณารีเฟรชหน้า';
      return;
    }
    pollTimer = setTimeout(pollStatus, 2000);
  }
}
</script>
