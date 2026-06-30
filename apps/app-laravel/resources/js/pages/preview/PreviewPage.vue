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
      <div v-if="previewStore.loading" class="preview-state">
        <v-progress-circular indeterminate color="primary" />
        <p>กำลังโหลดตัวอย่าง...</p>
      </div>
      <div v-else-if="previewStore.error" class="preview-state preview-state--error">
        <v-icon icon="mdi-alert-circle-outline" size="32" />
        <p>{{ previewStore.error }}</p>
        <v-btn variant="outlined" @click="previewStore.fetch(documentId)">โหลดใหม่</v-btn>
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
import { computed, onMounted, onUnmounted } from 'vue';
import DOMPurify from 'dompurify';
import { usePreviewStore } from '../../stores/previewStore';

const props = defineProps<{ documentId: string }>();

const previewStore = usePreviewStore();

const sourceFile = computed(() => previewStore.data?.source_file ?? '');

const safeHtml = computed(() => {
  const raw = previewStore.data?.draft_html ?? previewStore.data?.html ?? '';
  return DOMPurify.sanitize(raw, {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 's', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                   'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
                   'span', 'div', 'section', 'header', 'article', 'sub', 'sup',
                   'img', 'figure', 'figcaption'],
    ALLOWED_ATTR: ['class', 'style', 'colspan', 'rowspan', 'src', 'alt', 'width', 'height',
                   'data-block-id', 'data-block-type', 'data-page-no', 'data-reading-order'],
  });
});

function printPage(): void {
  window.print();
}

onMounted(() => previewStore.fetch(props.documentId));
onUnmounted(() => previewStore.reset());
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
