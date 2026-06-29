<template>
  <div v-if="loading" class="review-page-loading">
    <v-progress-circular indeterminate color="primary" />
    <p>กำลังโหลดเอกสาร...</p>
  </div>

  <div v-else-if="error" class="review-page-error">
    <v-icon icon="mdi-alert-circle-outline" size="48" />
    <p>{{ error }}</p>
    <v-btn variant="outlined" @click="reload">ลองใหม่</v-btn>
  </div>

  <DocumentEditorShell
    v-else-if="review"
    :review="review"
    :document-id="documentId"
    @reload="reload"
  />
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import DocumentEditorShell from '../../components/review/DocumentEditorShell.vue';
import { fetchReview } from '../../api/client';
import type { ReviewDocument } from '../../types/document';

const props = defineProps<{
  documentId: string;
}>();

const loading = ref(true);
const error = ref('');
const review = ref<ReviewDocument | null>(null);

onMounted(async () => {
  await reload();
});

async function reload(): Promise<void> {
  loading.value = true;
  error.value = '';

  try {
    review.value = await fetchReview(props.documentId);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'ไม่สามารถโหลดเอกสาร';
  } finally {
    loading.value = false;
  }
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
