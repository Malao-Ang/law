<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล']"
    title="การนำเข้าเอกสารกฎหมาย"
    subtitle="อัปโหลดไฟล์เพื่อเตรียมสกัดเนื้อหาเข้าสู่ระบบฐานข้อมูล"
  >
    <div class="d-flex flex-column ga-5 mx-auto" style="max-width:600px">

      <!-- Drop zone — hidden while a doc is active -->
      <v-card v-if="!documentId" class="pa-8 text-center" flat border rounded="lg" style="border-style:dashed">
        <v-icon icon="mdi-cloud-upload-outline" size="56" color="grey" />
        <div class="text-h6 font-weight-bold mt-2">ลากและวางไฟล์ข้อมูลกฎหมาย</div>
        <div class="text-body-2 text-medium-emphasis mb-2">รองรับไฟล์ .PDF หรือ .DOCX</div>
        <UploadForm @uploaded="onUploaded" />
      </v-card>

      <!-- Status card -->
      <transition name="fade">
        <v-card v-if="uploadStore.status" flat border rounded="lg" class="overflow-hidden">

          <!-- Processing -->
          <template v-if="isProcessing">
            <v-card-text class="pa-5">
              <div class="d-flex align-center ga-3 mb-3">
                <v-progress-circular indeterminate color="admin-primary" size="24" width="3" />
                <div class="flex-grow-1">
                  <div class="text-body-2 font-weight-bold">{{ uploadStore.status.source_file ?? 'เอกสาร' }}</div>
                  <div v-if="uploadStore.status.current_step" class="text-caption text-medium-emphasis">
                    {{ uploadStore.status.current_step }}
                  </div>
                </div>
                <v-chip size="small" color="warning">{{ statusLabel(uploadStore.status.status) }}</v-chip>
              </div>
              <v-progress-linear indeterminate color="admin-primary" rounded height="4" />
            </v-card-text>
            <v-divider />
            <v-card-actions class="pa-3 justify-end">
              <v-btn size="small" variant="tonal" color="error" prepend-icon="mdi-close-circle-outline"
                @click="cancelUpload">ยกเลิก</v-btn>
            </v-card-actions>
          </template>

          <!-- Success -->
          <template v-else-if="isDone">
            <div class="status-banner status-banner--success pa-4 d-flex align-center ga-3">
              <v-icon icon="mdi-check-circle-outline" size="32" color="success" />
              <div class="flex-grow-1">
                <div class="text-body-2 font-weight-bold">ประมวลผลสำเร็จ</div>
                <div class="text-caption text-medium-emphasis">{{ uploadStore.status.source_file }}</div>
              </div>
              <v-chip size="small" color="success">{{ statusLabel(uploadStore.status.status) }}</v-chip>
            </div>
            <v-divider />
            <v-card-actions class="pa-3 d-flex ga-2 justify-end">
              <v-btn size="small" variant="outlined" color="grey" prepend-icon="mdi-plus"
                @click="startNew">อัปโหลดใหม่</v-btn>
              <v-btn size="small" color="admin-primary" prepend-icon="mdi-pencil-outline"
                @click="goToReview">แก้ไขเอกสาร</v-btn>
            </v-card-actions>
          </template>

          <!-- Failed -->
          <template v-else-if="isFailed">
            <div class="status-banner status-banner--error pa-4 d-flex align-center ga-3">
              <v-icon icon="mdi-alert-circle-outline" size="32" color="error" />
              <div class="flex-grow-1">
                <div class="text-body-2 font-weight-bold">ประมวลผลล้มเหลว</div>
                <div class="text-caption text-medium-emphasis">{{ uploadStore.status.source_file }}</div>
              </div>
              <v-chip size="small" color="error">ล้มเหลว</v-chip>
            </div>
            <v-divider />
            <v-card-actions class="pa-3 justify-end">
              <v-btn size="small" color="admin-primary" prepend-icon="mdi-refresh"
                @click="startNew">ลองอีกครั้ง</v-btn>
            </v-card-actions>
          </template>

        </v-card>
      </transition>

      <!-- Upload history -->
      <template v-if="uploadStore.history.length">
        <div class="text-caption text-medium-emphasis font-weight-bold text-uppercase">ประวัติการนำเข้า</div>
        <v-card flat border rounded="lg">
          <v-list density="compact" lines="two">
            <v-list-item
              v-for="entry in uploadStore.history.slice(0, 5)"
              :key="entry.documentId + entry.at"
              :prepend-icon="historyIcon(entry.status)"
              :title="entry.filename"
              :subtitle="historyLabel(entry.status) + ' · ' + formatTime(entry.at)"
            >
              <template #append>
                <v-chip size="x-small" :color="historyColor(entry.status)" variant="tonal">
                  {{ historyLabel(entry.status) }}
                </v-chip>
              </template>
            </v-list-item>
          </v-list>
        </v-card>
      </template>

    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useUploadStore } from '../../stores/uploadStore';
import type { UploadHistoryEntry } from '../../stores/uploadStore';
import AppShell from '../../components/shared/AppShell.vue';
import UploadForm from '../../components/shared/UploadForm.vue';

const router = useRouter();
const uploadStore = useUploadStore();

const documentId = ref<string | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const isProcessing = computed(() =>
  ['queued', 'processing', 'ingesting'].includes(uploadStore.status?.status ?? ''),
);
const isDone = computed(() =>
  ['done', 'exported', 'ingested'].includes(uploadStore.status?.status ?? ''),
);
const isFailed = computed(() => uploadStore.status?.status === 'failed');

onBeforeUnmount(() => {
  if (pollTimer) clearTimeout(pollTimer);
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

function onUploaded(id: string): void {
  documentId.value = id;
  uploadStore.reset();
  poll();
}

async function poll(): Promise<void> {
  if (!documentId.value) return;
  const result = await uploadStore.pollOnce(documentId.value);
  if (result && ['queued', 'processing', 'ingesting'].includes(result.status)) {
    pollTimer = setTimeout(poll, 1500);
  } else if (!result) {
    pollTimer = setTimeout(poll, 2000);
  } else {
    uploadStore.finalize(documentId.value);
  }
}

function cancelUpload(): void {
  if (pollTimer) clearTimeout(pollTimer);
  if (documentId.value) uploadStore.cancelCurrent(documentId.value);
  documentId.value = null;
}

function startNew(): void {
  if (pollTimer) clearTimeout(pollTimer);
  uploadStore.reset();
  documentId.value = null;
}

function goToReview(): void {
  router.push(`/documents/${documentId.value!}/review`);
}

function historyIcon(status: UploadHistoryEntry['status']): string {
  return { done: 'mdi-check-circle-outline', failed: 'mdi-alert-circle-outline', cancelled: 'mdi-cancel' }[status];
}
function historyColor(status: UploadHistoryEntry['status']): string {
  return { done: 'success', failed: 'error', cancelled: 'grey' }[status];
}
function historyLabel(status: UploadHistoryEntry['status']): string {
  return { done: 'สำเร็จ', failed: 'ล้มเหลว', cancelled: 'ยกเลิกโดยผู้ใช้' }[status];
}
function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.status-banner--success { background: rgba(22, 163, 74, 0.08); }
.status-banner--error   { background: rgba(215, 71, 71, 0.08); }
</style>
