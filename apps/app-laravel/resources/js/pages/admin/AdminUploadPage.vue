<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล']"
    title="การนำเข้าเอกสารกฎหมาย"
    subtitle="อัปโหลดไฟล์เพื่อเตรียมสกัดเนื้อหาเข้าสู่ระบบฐานข้อมูล"
  >
    <div class="d-flex flex-column ga-5 mx-auto" style="max-width:600px">
      <v-card class="pa-8 text-center" flat border rounded="lg" style="border-style:dashed">
        <v-icon icon="mdi-cloud-upload-outline" size="56" color="grey" />
        <div class="text-h6 font-weight-bold mt-2">ลากและวางไฟล์ข้อมูลกฎหมาย</div>
        <div class="text-body-2 text-medium-emphasis mb-2">รองรับไฟล์ .PDF หรือ .DOCX</div>
        <UploadForm @uploaded="onUploaded" />
      </v-card>

      <transition name="fade">
        <v-card v-if="uploadStore.status" class="pa-5" flat border rounded="lg">
          <div class="d-flex align-center ga-3 font-weight-medium">
            <v-icon icon="mdi-file-document-outline" color="primary" />
            <span class="flex-grow-1">{{ uploadStore.status.source_file ?? 'เอกสาร' }}</span>
            <v-chip size="small" :color="statusChipColor(uploadStore.status.status)">
              {{ statusLabel(uploadStore.status.status) }}
            </v-chip>
          </div>
          <v-progress-linear
            v-if="isProcessing"
            indeterminate
            color="primary"
            class="mt-3"
          />
          <div v-if="uploadStore.status.current_step" class="text-caption text-medium-emphasis mt-2">
            {{ uploadStore.status.current_step }}
          </div>
        </v-card>
      </transition>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useUploadStore } from '../../stores/uploadStore';
import AppShell from '../../components/shared/AppShell.vue';
import UploadForm from '../../components/shared/UploadForm.vue';

const router = useRouter();
const uploadStore = useUploadStore();

let documentId: string | null = null;
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const isProcessing = computed(() =>
  ['queued', 'processing', 'ingesting'].includes(uploadStore.status?.status ?? ''),
);

onBeforeUnmount(() => {
  if (pollTimer) clearTimeout(pollTimer);
  uploadStore.reset();
});

function statusLabel(s: string): string {
  const map: Record<string, string> = {
    queued: 'รอดำเนินการ',
    processing: 'กำลังประมวลผล',
    ingesting: 'กำลังนำเข้าระบบ',
    done: 'เสร็จสิ้น',
    exported: 'ส่งออกแล้ว',
    ingested: 'นำเข้าแล้ว',
    failed: 'ล้มเหลว',
  };
  return map[s] ?? s;
}

function statusChipColor(s: string): string {
  return ({ done: 'success', exported: 'success', ingested: 'success', processing: 'warning', ingesting: 'warning', queued: 'info', failed: 'error' } as Record<string, string>)[s] ?? 'grey';
}

function onUploaded(id: string): void {
  documentId = id;
  uploadStore.reset();
  pollStatus();
}

async function pollStatus(): Promise<void> {
  if (!documentId) return;
  const result = await uploadStore.pollOnce(documentId);
  if (result && ['queued', 'processing', 'ingesting'].includes(result.status)) {
    pollTimer = setTimeout(pollStatus, 1500);
  } else if (!result) {
    pollTimer = setTimeout(pollStatus, 2000);
  } else if (result.status === 'done' || result.status === 'exported') {
    pollTimer = setTimeout(() => {
      router.push(`/documents/${documentId!}/review`);
    }, 1000);
  }
}
</script>

<style scoped>
/* ponytail: fade transition, keep */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.25s;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
