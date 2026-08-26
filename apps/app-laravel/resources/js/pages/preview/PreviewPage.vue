<template>
  <div class="d-flex flex-column" style="min-height:100vh; background:#f0f0f0">
    <v-toolbar flat border="b" density="comfortable" class="preview-toolbar px-2">
      <v-btn
        variant="text"
        prepend-icon="mdi-arrow-left"
        :to="isOld ? '/admin/upload' : `/documents/${documentId}/review`"
      >{{ isOld ? 'กลับรายการ' : 'กลับแก้ไข' }}</v-btn>
      <span class="text-subtitle-1 font-weight-semibold ml-2">{{ sourceFile ? `ตัวอย่าง — ${sourceFile}` : 'ตัวอย่างเอกสาร' }}</span>
      <v-spacer />
      <v-btn
        v-if="isOld"
        color="primary"
        variant="flat"
        append-icon="mdi-arrow-right"
        :to="`/documents/${documentId}/law-info`"
      >กรอกข้อมูล</v-btn>
      <v-btn
        v-else
        variant="tonal"
        prepend-icon="mdi-printer-outline"
        @click="printPage()"
      >พิมพ์</v-btn>
    </v-toolbar>

    <div class="flex-grow-1 pa-8 d-flex justify-center">
      <div v-if="checking" class="d-flex flex-column align-center ga-3 pa-12">
        <v-progress-circular indeterminate color="primary" />
        <p>กำลังโหลดตัวอย่าง...</p>
      </div>

      <iframe
        v-else-if="isOld"
        :src="pdfUrl"
        class="preview-pdf"
        title="ตัวอย่างเอกสาร PDF"
      />

      <div v-else-if="previewStore.loading" class="d-flex flex-column align-center ga-3 pa-12">
        <v-progress-circular indeterminate color="primary" />
        <p>กำลังโหลดตัวอย่าง...</p>
      </div>
      <div v-else-if="previewStore.error" class="d-flex flex-column align-center ga-3 pa-12">
        <v-icon icon="mdi-alert-circle-outline" size="32" />
        <v-alert type="error" variant="tonal">{{ previewStore.error }}</v-alert>
        <v-btn variant="outlined" @click="previewStore.fetch(documentId, true)">โหลดใหม่</v-btn>
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
import { computed, onMounted, onUnmounted, ref } from 'vue';
import DOMPurify from 'dompurify';
import { usePreviewStore } from '../../stores/previewStore';
import { fetchStatus, documentFileUrl } from '../../api/client';

const props = defineProps<{ documentId: string }>();

const previewStore = usePreviewStore();

const checking = ref(true);
const isOld = ref(false);

const pdfUrl = computed(() => documentFileUrl(props.documentId));
const sourceFile = computed(() => previewStore.data?.source_file ?? '');

const safeHtml = computed(() => {
  const raw = previewStore.data?.html ?? previewStore.data?.draft_html ?? '';
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

onMounted(async () => {
  try {
    const status = await fetchStatus(props.documentId);
    isOld.value = status.document_type === 'old';
  } catch {
    isOld.value = false;
  }
  // Old docs have no review document; /preview would 404. Skip the fetch.
  if (!isOld.value) {
    await previewStore.fetch(props.documentId);
  }
  checking.value = false;
});
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
  font-family: 'TH Sarabun PSK', 'TH Sarabun New', 'Sarabun', 'Noto Sans Thai', sans-serif;
}

.preview-pdf {
  width: 210mm;
  height: calc(100vh - 120px);
  border: none;
  background: white;
  box-shadow: 0 2px 16px rgba(0, 0, 0, 0.15);
}

@media print {
  .preview-toolbar { display: none; }
  .preview-paper { box-shadow: none; width: 100%; padding: 0; }
}
</style>
