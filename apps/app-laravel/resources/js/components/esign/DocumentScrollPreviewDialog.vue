<template>
  <v-dialog
    :model-value="modelValue"
    fullscreen
    transition="dialog-bottom-transition"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <v-card class="scroll-preview">
      <div class="scroll-preview__toolbar">
        <div class="d-flex align-center ga-1">
          <v-btn icon="mdi-minus" size="small" variant="text" @click="zoomOut" />
          <span class="text-caption font-weight-medium" style="min-width:48px;text-align:center">{{ zoom }}%</span>
          <v-btn icon="mdi-plus" size="small" variant="text" @click="zoomIn" />
        </div>
        <div class="d-flex align-center ga-2">
          <v-btn icon="mdi-chevron-left" size="small" variant="text" :disabled="page <= 1" @click="page--" />
          <span class="text-caption">หน้า {{ page }} / {{ pageCount }}</span>
          <v-btn icon="mdi-chevron-right" size="small" variant="text" :disabled="page >= pageCount" @click="page++" />
        </div>
        <div class="d-flex align-center ga-1">
          <v-chip
            size="x-small"
            :color="signed ? 'success' : 'warning'"
            variant="flat"
            class="font-weight-bold"
          >{{ signed ? 'ลงนามแล้ว' : 'ยังไม่ลงนาม' }}</v-chip>
          <v-btn icon="mdi-close" size="small" variant="text" @click="emit('update:modelValue', false)" />
        </div>
      </div>

      <div ref="viewportEl" class="scroll-preview__viewport" @scroll="onScroll">
        <div v-if="loading" class="d-flex flex-column align-center ga-3 pa-16">
          <v-progress-circular indeterminate color="admin-primary" />
          <span class="text-medium-emphasis">กำลังโหลดตัวอย่าง...</span>
        </div>
        <v-alert v-else-if="error" type="error" variant="tonal" class="ma-6">{{ error }}</v-alert>
        <div v-else class="scroll-preview__stage" :style="{ transform: `scale(${zoom / 100})` }">
          <article ref="paperEl" class="scroll-preview__paper">
            <div class="scroll-preview__crest">
              <v-avatar color="admin-primary" size="56">
                <v-icon icon="mdi-bank-outline" color="white" />
              </v-avatar>
              <div class="text-subtitle-2 font-weight-bold mt-2">{{ agencyLabel }}</div>
            </div>
            <div class="scroll-preview__html" v-html="safeHtml" />
            <div class="scroll-preview__sign-block">
              <div class="text-body-2 mb-6">ให้ไว้ ณ วันที่ {{ dateLabel }}</div>
              <div class="scroll-preview__sign-line">
                <div v-if="signed" class="scroll-preview__signature">{{ signerName }}</div>
                <div v-else class="scroll-preview__signature is-empty" />
                <div class="text-body-2 font-weight-bold">({{ signerName || 'ผู้ลงนาม' }})</div>
                <div class="text-caption text-medium-emphasis">{{ signerPosition || 'ตำแหน่ง' }}</div>
              </div>
            </div>
          </article>
        </div>
      </div>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import DOMPurify from 'dompurify';
import { fetchPreview } from '../../api/client';

const A4_HEIGHT_PX = 1123;

const props = defineProps<{
  modelValue: boolean;
  documentId: string;
  signed?: boolean;
  signerName?: string;
  signerPosition?: string;
  agency?: string;
  dateLabel?: string;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
}>();

const zoom = ref(100);
const page = ref(1);
const pageCount = ref(1);
const loading = ref(false);
const error = ref('');
const html = ref('');
const viewportEl = ref<HTMLElement | null>(null);
const paperEl = ref<HTMLElement | null>(null);

const agencyLabel = computed(() => props.agency || 'มหาวิทยาลัยบูรพา');
const dateLabel = computed(() => props.dateLabel || '—');

const safeHtml = computed(() =>
  DOMPurify.sanitize(html.value, {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 's', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
      'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
      'span', 'div', 'section', 'header', 'article', 'sub', 'sup',
      'img', 'figure', 'figcaption'],
    ALLOWED_ATTR: ['class', 'style', 'colspan', 'rowspan', 'src', 'alt', 'width', 'height',
      'data-block-id', 'data-block-type', 'data-page-no', 'data-reading-order'],
  }),
);

function zoomIn(): void {
  zoom.value = Math.min(150, zoom.value + 10);
}

function zoomOut(): void {
  zoom.value = Math.max(50, zoom.value - 10);
}

function recomputePages(): void {
  const paper = paperEl.value;
  if (!paper) {
    pageCount.value = 1;
    return;
  }
  pageCount.value = Math.max(1, Math.ceil(paper.scrollHeight / A4_HEIGHT_PX));
  page.value = Math.min(page.value, pageCount.value);
}

function onScroll(): void {
  const viewport = viewportEl.value;
  if (!viewport) return;
  const scaledPage = A4_HEIGHT_PX * (zoom.value / 100);
  page.value = Math.min(pageCount.value, Math.max(1, Math.floor(viewport.scrollTop / scaledPage) + 1));
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const data = await fetchPreview(props.documentId);
    html.value = data.draft_html || data.html || '';
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'โหลดตัวอย่างไม่สำเร็จ';
  } finally {
    loading.value = false;
    await nextTick();
    recomputePages();
  }
}

watch(() => props.modelValue, (open) => {
  if (open) {
    zoom.value = 100;
    page.value = 1;
    void load();
  }
});

watch([safeHtml, zoom], async () => {
  await nextTick();
  recomputePages();
});

watch(page, (next) => {
  const viewport = viewportEl.value;
  if (!viewport) return;
  const scaledPage = A4_HEIGHT_PX * (zoom.value / 100);
  const target = (next - 1) * scaledPage;
  if (Math.abs(viewport.scrollTop - target) > scaledPage * 0.4) {
    viewport.scrollTo({ top: target, behavior: 'smooth' });
  }
});
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
  overflow: auto;
  padding: 28px 16px 48px;
  display: flex;
  justify-content: center;
  align-items: flex-start;
}

.scroll-preview__stage {
  transform-origin: top center;
  transition: transform 0.15s ease;
}

.scroll-preview__paper {
  background: #fff;
  width: 210mm;
  min-height: 297mm;
  padding: 22mm 28mm 28mm;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
  color: #1e293b;
  font-size: 15pt;
  line-height: 1.8;
}

.scroll-preview__crest {
  text-align: center;
  margin-bottom: 18px;
}

.scroll-preview__html :deep(h1),
.scroll-preview__html :deep(h2) {
  text-align: center;
}

.scroll-preview__sign-block {
  margin-top: 48px;
  text-align: center;
}

.scroll-preview__sign-line {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  min-width: 220px;
}

.scroll-preview__signature {
  min-height: 48px;
  font-family: 'Segoe Script', 'Brush Script MT', cursive;
  font-size: 28px;
  color: #1d4ed8;
  margin-bottom: 4px;
}

.scroll-preview__signature.is-empty {
  border-bottom: 1px solid #94a3b8;
  width: 220px;
  margin-bottom: 8px;
}
</style>
