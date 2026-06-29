<template>
  <div class="preview-page">
    <header class="preview-header">
      <v-btn
        variant="text"
        prepend-icon="mdi-arrow-left"
        :to="`/documents/${documentId}/compose`"
      >กลับแก้ไข</v-btn>
      <span class="preview-header__title">{{ sourceFile ? `ตัวอย่าง — ${sourceFile}` : 'ตัวอย่างเอกสาร' }}</span>
      <v-spacer />
      <v-btn
        variant="tonal"
        prepend-icon="mdi-printer-outline"
        @click="printPage()"
      >พิมพ์</v-btn>
    </header>

    <main class="preview-main">
      <div v-if="loading" class="preview-state">
        <v-progress-circular indeterminate color="primary" />
        <p>กำลังโหลดตัวอย่าง...</p>
      </div>
      <div v-else-if="errorMsg" class="preview-state preview-state--error">
        <v-icon icon="mdi-alert-circle-outline" size="32" />
        <p>{{ errorMsg }}</p>
        <v-btn variant="outlined" @click="load">โหลดใหม่</v-btn>
      </div>
      <article
        v-else
        class="preview-paper"
        v-html="safeHtml"
      />
    </main>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import DOMPurify from 'dompurify';
import { jsonRequest } from '../../api/client';

const props = defineProps<{ documentId: string }>();

interface PreviewData {
  html: string;
  draft_html: string;
  html_mode: 'generated' | 'manual';
  source_file?: string | null;
}

const loading = ref(true);
const errorMsg = ref('');
const data = ref<PreviewData | null>(null);

const sourceFile = computed(() => data.value?.source_file ?? '');

const safeHtml = computed(() => {
  const raw = data.value?.draft_html ?? data.value?.html ?? '';
  return DOMPurify.sanitize(raw, {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 's', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                   'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
                   'span', 'div', 'sub', 'sup'],
    ALLOWED_ATTR: ['class', 'style', 'colspan', 'rowspan'],
  });
});

async function load(): Promise<void> {
  loading.value = true;
  errorMsg.value = '';
  try {
    data.value = await jsonRequest<PreviewData>(`/api/documents/${props.documentId}/preview`);
  } catch (err) {
    errorMsg.value = err instanceof Error ? err.message : 'โหลดตัวอย่างไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

function printPage(): void {
  window.print();
}

onMounted(load);
</script>

<style scoped>
.preview-page {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: #f0f0f0;
}
.preview-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 20px;
  background: white;
  border-bottom: 1px solid #ddd;
  position: sticky;
  top: 0;
  z-index: 10;
}
.preview-header__title {
  font-size: 15px;
  font-weight: 600;
}
.preview-main {
  flex: 1;
  padding: 32px 16px;
  display: flex;
  justify-content: center;
}
.preview-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 48px;
}
.preview-paper {
  background: white;
  width: 210mm;
  min-height: 297mm;
  padding: 25mm 30mm;
  box-shadow: 0 2px 16px rgba(0, 0, 0, 0.15);
  font-size: 16pt;
  line-height: 1.8;
}
@media print {
  .preview-header { display: none; }
  .preview-main { padding: 0; }
  .preview-paper { box-shadow: none; width: 100%; padding: 0; }
}
</style>
