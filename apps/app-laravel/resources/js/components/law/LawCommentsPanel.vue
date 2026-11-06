<template>
  <v-card class="law-comments mt-4" flat border rounded="lg">
    <v-card-title class="d-flex align-center ga-2 text-body-2 font-weight-bold text-elaw-navy">
      <v-icon icon="mdi-comment-text-outline" size="small" />
      ความคิดเห็นและข้อซักถาม
      <v-chip v-if="counts" size="x-small" color="primary" variant="tonal">{{ counts.all }}</v-chip>
    </v-card-title>
    <v-card-text>
      <v-btn block color="primary" variant="flat" prepend-icon="mdi-plus" class="mb-3 text-none" @click="composer?.open()">
        แสดงความคิดเห็น
      </v-btn>

      <div v-if="loading" class="d-flex justify-center py-4">
        <v-progress-circular indeterminate size="22" width="2" />
      </div>
      <p v-else-if="error" class="text-caption text-error">{{ error }}</p>
      <p v-else-if="!items.length" class="text-caption text-medium-emphasis text-center py-2">
        ยังไม่มีความคิดเห็น เป็นคนแรกที่แสดงความเห็นต่อกฎหมายฉบับนี้
      </p>
      <div v-else class="d-flex flex-column ga-2">
        <LawCommentCard v-for="comment in items" :key="comment.id" :comment="comment" compact />
      </div>

      <v-btn
        block
        variant="text"
        color="primary"
        class="mt-2 text-none"
        append-icon="mdi-arrow-right"
        :to="`/law/${encodeURIComponent(documentId)}/comments`"
      >
        ดูความคิดเห็นทั้งหมด
      </v-btn>
    </v-card-text>
    <LawCommentComposer
      ref="composer"
      :document-id="documentId"
      :document-title="documentTitle"
      @submitted="load"
    />
  </v-card>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { fetchLawComments } from '../../api/lawComments';
import type { LawComment, LawCommentCounts } from '../../types/lawComment';
import LawCommentCard from './LawCommentCard.vue';
import LawCommentComposer from './LawCommentComposer.vue';

const props = defineProps<{
  documentId: string;
  documentTitle: string;
}>();

const items = ref<LawComment[]>([]);
const counts = ref<LawCommentCounts | null>(null);
const loading = ref(false);
const error = ref('');
const composer = ref<{ open: () => void } | null>(null);

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const result = await fetchLawComments(props.documentId, { limit: 4, sort: 'newest' });
    items.value = result.items;
    counts.value = result.counts;
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'โหลดความคิดเห็นไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

onMounted(() => { void load(); });
watch(() => props.documentId, () => { void load(); });
</script>
