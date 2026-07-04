<template>
  <div class="d-flex flex-column" style="min-height:100vh; background:#f0f0f0">
    <v-toolbar flat border="b" density="comfortable" class="preview-toolbar px-2">
      <v-btn
        variant="text"
        prepend-icon="mdi-arrow-left"
        :to="`/documents/${documentId}/compose`"
      >กลับแก้ไข</v-btn>
      <span class="text-subtitle-1 font-weight-semibold ml-2">{{ sourceFile ? `ตัวอย่าง — ${sourceFile}` : 'ตัวอย่างเอกสาร' }}</span>
      <v-spacer />
      <v-btn
        variant="tonal"
        prepend-icon="mdi-printer-outline"
        @click="printPage()"
      >พิมพ์</v-btn>
    </v-toolbar>

    <div class="flex-grow-1 pa-8 d-flex justify-center">
      <div v-if="previewStore.loading" class="d-flex flex-column align-center ga-3 pa-12">
        <v-progress-circular indeterminate color="primary" />
        <p>กำลังโหลดตัวอย่าง...</p>
      </div>
      <div v-else-if="previewStore.error" class="d-flex flex-column align-center ga-3 pa-12">
        <v-icon icon="mdi-alert-circle-outline" size="32" />
        <v-alert type="error" variant="tonal">{{ previewStore.error }}</v-alert>
        <v-btn variant="outlined" @click="previewStore.fetch(documentId)">โหลดใหม่</v-btn>
      </div>
      <article
        v-else
        class="preview-paper"
        v-html="safeHtml"
      />
    </div>
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
/* document-render container — typography CSS, do not modify */
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
  .preview-toolbar { display: none; }
  .preview-paper { box-shadow: none; width: 100%; padding: 0; }
}
</style>
