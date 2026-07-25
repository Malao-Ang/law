<template>
  <div v-if="documentStore.loading" class="review-page-loading">
    <v-progress-circular indeterminate color="primary" />
    <p>กำลังโหลดเอกสาร...</p>
  </div>

  <div v-else-if="documentStore.error" class="review-page-error">
    <v-icon icon="mdi-alert-circle-outline" size="48" />
    <p>{{ documentStore.error }}</p>
    <v-btn variant="outlined" @click="reload">ลองใหม่</v-btn>
  </div>

  <template v-else-if="documentStore.review">
    <div class="review-workflow-bg">
      <WorkflowStepper :step="2" description="อ่านทวน แก้ไข และจัดรูปแบบก่อนยืนยันนำเข้าระบบ" />
    </div>

    <v-dialog :model-value="true" fullscreen persistent scrim transition="dialog-bottom-transition">
      <DocumentEditorShell
        :document-id="documentId"
        :locked="locked"
        @close="goBack"
      />
    </v-dialog>
  </template>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import DocumentEditorShell from '../../components/review/DocumentEditorShell.vue';
import WorkflowStepper from '../../components/shared/WorkflowStepper.vue';
import { fetchStatus } from '../../api/client';
import { useDocumentStore } from '../../stores/documentStore';
import { useReviewUiStore } from '../../stores/reviewUiStore';
import { getPreviewCached } from '../../stores/reviewCache';
import type { DocumentStatus } from '../../types/document';

const props = defineProps<{
  documentId: string;
}>();

const documentStore = useDocumentStore();
const reviewUiStore = useReviewUiStore();
const router = useRouter();
const docStatus = ref<DocumentStatus | null>(null);

const locked = computed(() => docStatus.value?.esign_exported_at != null);

onMounted(async () => {
  const [, status] = await Promise.all([
    documentStore.fetch(props.documentId),
    fetchStatus(props.documentId).catch(() => null),
  ]);
  docStatus.value = status;
  // Warm the preview HTML in the background so the next step feels instant.
  void getPreviewCached(props.documentId).catch(() => undefined);
});

onUnmounted(() => {
  documentStore.reset();
  reviewUiStore.reset();
});

async function reload(): Promise<void> {
  const [, status] = await Promise.all([
    documentStore.fetch(props.documentId),
    fetchStatus(props.documentId).catch(() => null),
  ]);
  docStatus.value = status;
}

function goBack(): void {
  router.push('/admin/upload');
}
</script>

<style scoped>
.review-workflow-bg {
  min-height: 100vh;
  padding: 16px 24px;
  background: #f8fafc;
}

.review-page-loading,
.review-page-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  gap: 16px;
  background: #f5f5f5;
}

.review-page-error {
  color: #d97706;

  p {
    font-size: 16px;
  }
}
</style>
