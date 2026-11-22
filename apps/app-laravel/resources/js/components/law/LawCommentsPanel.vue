<template>
  <v-card class="law-comments mt-4" flat border rounded="lg">
    <v-card-text class="pa-5">
      <div class="law-comments__head mb-1">
        <v-icon icon="mdi-comment-text-outline" size="18" color="#64748b" />
        <h3 class="law-comments__title">ความคิดเห็นและข้อซักถาม</h3>
        <v-chip v-if="publishedCount > 0" size="small" color="success" variant="tonal" class="law-comments__badge">
          {{ publishedCount }} เผยแพร่
        </v-chip>
      </div>
      <p class="law-comments__subtitle mb-4">สอบถามหรือเสนอข้อคิดเห็นเกี่ยวกับกฎหมายฉบับนี้</p>

      <v-btn
        block
        color="#1a3d7c"
        variant="flat"
        prepend-icon="mdi-plus"
        class="mb-4 text-none law-comments__cta"
        size="large"
        @click="composer?.open()"
      >
        แสดงความคิดเห็น
      </v-btn>

      <div v-if="loading" class="d-flex justify-center py-4">
        <v-progress-circular indeterminate size="22" width="2" />
      </div>
      <p v-else-if="error" class="text-caption text-error">{{ error }}</p>
      <p v-else-if="!items.length" class="text-caption text-medium-emphasis text-center py-3">
        ยังไม่มีความคิดเห็นที่เผยแพร่ เป็นคนแรกที่แสดงความเห็นต่อกฎหมายฉบับนี้
      </p>
      <div v-else class="law-comments__list">
        <article v-for="comment in items" :key="comment.id" class="law-comments__item">
          <div class="law-comments__item-meta">
            <span class="law-comments__type">{{ comment.type_label }}</span>
            <v-chip
              size="x-small"
              :color="comment.status === 'answered' ? 'success' : 'warning'"
              variant="tonal"
            >
              {{ comment.status_label }}
            </v-chip>
          </div>
          <p class="law-comments__body">{{ comment.body }}</p>
          <div v-if="comment.reply" class="law-comments__reply">
            <span class="law-comments__reply-arrow">↳</span>
            <p>{{ comment.reply.body }}</p>
          </div>
        </article>
      </div>

      <div class="text-center mt-3">
        <RouterLink class="law-comments__all" :to="`/law/${encodeURIComponent(documentId)}/comments`">
          ดูความคิดเห็นทั้งหมด
          <v-icon icon="mdi-arrow-right" size="16" />
        </RouterLink>
      </div>
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
import { computed, onMounted, ref, watch } from 'vue';
import { fetchLawComments } from '../../api/lawComments';
import type { LawComment, LawCommentCounts } from '../../types/lawComment';
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

const publishedCount = computed(() => counts.value?.visibility.public ?? items.value.length);

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const result = await fetchLawComments(props.documentId, {
      visibility: 'public',
      limit: 4,
      sort: 'newest',
    });
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

<style scoped>
.law-comments__head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.law-comments__title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 700;
  color: #1a2e52;
}

.law-comments__badge {
  font-weight: 600;
}

.law-comments__subtitle {
  margin: 0;
  color: #64748b;
  font-size: 0.8125rem;
  line-height: 1.5;
}

.law-comments__cta {
  font-weight: 600;
  letter-spacing: 0;
}

.law-comments__list {
  display: flex;
  flex-direction: column;
}

.law-comments__item {
  padding: 14px 0;
  border-top: 1px solid #e5e7eb;
}

.law-comments__item:last-child {
  padding-bottom: 4px;
}

.law-comments__item-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}

.law-comments__type {
  font-size: 0.8125rem;
  font-weight: 600;
  color: #334155;
}

.law-comments__body {
  margin: 0;
  color: #1e293b;
  font-size: 0.875rem;
  line-height: 1.65;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.law-comments__reply {
  margin-top: 10px;
  display: flex;
  gap: 8px;
  align-items: flex-start;
  background: #f1f5f9;
  border-radius: 10px;
  padding: 10px 12px;
  color: #334155;
}

.law-comments__reply-arrow {
  color: #64748b;
  line-height: 1.5;
  flex-shrink: 0;
}

.law-comments__reply p {
  margin: 0;
  font-size: 0.8125rem;
  line-height: 1.6;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.law-comments__all {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #1d4ed8;
  font-size: 0.875rem;
  font-weight: 600;
  text-decoration: none;
}

.law-comments__all:hover {
  text-decoration: underline;
}
</style>
