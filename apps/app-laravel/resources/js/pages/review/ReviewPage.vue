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

  <DocumentEditorShell
    v-else-if="documentStore.review"
    :document-id="documentId"
  />
</template>

<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue';
import DocumentEditorShell from '../../components/review/DocumentEditorShell.vue';
import { useDocumentStore } from '../../stores/documentStore';
import { useReviewUiStore } from '../../stores/reviewUiStore';

const props = defineProps<{
  documentId: string;
}>();

const documentStore = useDocumentStore();
const reviewUiStore = useReviewUiStore();

onMounted(async () => {
  await documentStore.fetch(props.documentId);
});

onUnmounted(() => {
  documentStore.reset();
  reviewUiStore.reset();
});

async function reload(): Promise<void> {
  await documentStore.fetch(props.documentId);
}
</script>

<style scoped>
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
