<template>
  <v-dialog
    :model-value="modelValue"
    fullscreen
    transition="dialog-bottom-transition"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <v-card class="scroll-preview">
      <div class="scroll-preview__toolbar">
        <div class="text-body-2 font-weight-bold">ตัวอย่าง PDF จากเอกสารที่ตรวจทานแล้ว</div>
        <div class="d-flex align-center ga-1">
          <v-chip
            size="x-small"
            :color="signed ? 'success' : 'warning'"
            variant="flat"
            class="font-weight-bold"
          >{{ signed ? 'ลงนามแล้ว' : 'ยังไม่ลงนาม' }}</v-chip>
          <v-btn icon="mdi-refresh" size="small" variant="text" title="สร้างตัวอย่างใหม่" @click="refreshPreview" />
          <v-btn icon="mdi-download" size="small" variant="text" title="ดาวน์โหลด PDF" :loading="downloading" @click="downloadPdf" />
          <v-btn icon="mdi-close" size="small" variant="text" @click="emit('update:modelValue', false)" />
        </div>
      </div>

      <div class="scroll-preview__viewport">
        <object :key="previewKey" class="scroll-preview__pdf" :data="pdfUrl" type="application/pdf">
          <div class="scroll-preview__fallback">
            <v-icon icon="mdi-file-pdf-box" size="42" color="error" />
            <div class="text-body-2 font-weight-bold mt-2">เบราว์เซอร์ไม่สามารถแสดง PDF ในหน้านี้ได้</div>
            <v-btn class="mt-3" color="admin-primary" :href="pdfUrl" target="_blank" rel="noopener">
              เปิด PDF ในแท็บใหม่
            </v-btn>
          </div>
        </object>
      </div>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { downloadPdfExport, reviewPdfPreviewUrl } from '../../api/client';

const props = defineProps<{
  modelValue: boolean;
  documentId: string;
  signed?: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
}>();

const previewKey = ref(0);
const downloading = ref(false);
const pdfUrl = computed(() => `${reviewPdfPreviewUrl(props.documentId)}?v=${previewKey.value}`);

function refreshPreview(): void {
  previewKey.value += 1;
}

watch(() => props.modelValue, (open) => {
  if (open) {
    refreshPreview();
  }
});

async function downloadPdf(): Promise<void> {
  downloading.value = true;
  try {
    await downloadPdfExport(props.documentId);
  } finally {
    downloading.value = false;
  }
}
</script>

<style scoped>
.scroll-preview {
  background: #1e293b;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.scroll-preview__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 16px;
  background: #0f172a;
  color: #e2e8f0;
  border-bottom: 1px solid #334155;
}

.scroll-preview__viewport {
  flex: 1;
  min-height: 0;
  padding: 0;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  background: #111827;
}

.scroll-preview__pdf {
  width: 100%;
  height: calc(100vh - 57px);
  border: 0;
  background: #fff;
}

.scroll-preview__fallback {
  width: 100%;
  height: calc(100vh - 57px);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: #e2e8f0;
}
</style>
