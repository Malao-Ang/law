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

              <v-btn
                v-if="canOpenReview"
                color="success"
                @click="goToReview"
              >
                ตรวจสอบเอกสาร
              </v-btn>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>
    </v-container>
  </v-main>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import UploadForm from '../components/UploadForm.vue';
import { fetchStatus } from '../api/client';
import type { DocumentStatus } from '../types/document';

const router = useRouter();
const documentId = ref<string | null>(null);
const status = ref<DocumentStatus | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const canOpenReview = computed(() => {
  return ['done', 'exported', 'ingesting', 'ingested'].includes(status.value?.status ?? '');
});

function getStatusColor(status: string): string {
  switch (status) {
    case 'done':
    case 'exported':
    case 'ingested':
      return 'success';
    case 'processing':
    case 'ingesting':
      return 'warning';
    case 'queued':
      return 'info';
    default:
      return 'error';
  }
}

function getStatusText(status: string): string {
  switch (status) {
    case 'queued':
      return 'รอดำเนินการ';
    case 'processing':
      return 'กำลังประมวลผล';
    case 'ingesting':
      return 'กำลังนำเข้าระบบ';
    case 'done':
      return 'เสร็จสิ้น';
    case 'exported':
      return 'ส่งออกแล้ว';
    case 'ingested':
      return 'นำเข้าระบบแล้ว';
    case 'failed':
      return 'ล้มเหลว';
    default:
      return status;
  }
}

function onUploaded(id: string): void {
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
