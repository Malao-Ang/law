<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'บทสรุปการดำเนินการ']"
    title="บทสรุปการดำเนินการ"
    subtitle="ตรวจสอบและส่งออกเอกสารสำหรับ e-Sign"
  >
    <div class="result-page mx-auto">
      <div v-if="loading" class="d-flex align-center justify-center pa-12 ga-3 text-medium-emphasis">
        <v-progress-circular indeterminate />
        <span>กำลังโหลด...</span>
      </div>

      <template v-else>
        <v-card border rounded="lg" class="mb-5 pa-6">
          <div class="d-flex align-center ga-3 mb-4">
            <v-icon icon="mdi-file-document-outline" size="32" color="admin-primary" />
            <div>
              <div class="text-h6 font-weight-bold">{{ docTitle }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ meta?.law_type || 'เอกสาร' }}<span v-if="meta?.agency"> · {{ meta.agency }}</span>
              </div>
            </div>
          </div>
          <div class="d-flex flex-wrap ga-2">
            <v-chip v-if="meta?.status" size="small" color="primary" variant="tonal">{{ meta.status }}</v-chip>
            <v-chip v-if="meta?.promulgation_date" size="small" variant="outlined">ประกาศ {{ meta.promulgation_date }}</v-chip>
            <v-chip
              v-if="meta?.access_scope === 'private'"
              size="small"
              color="warning"
              variant="tonal"
              prepend-icon="mdi-lock-outline"
            >
              Private
            </v-chip>
          </div>
        </v-card>

        <v-card border rounded="lg" class="mb-5 pa-6">
          <div class="d-flex align-center ga-3 mb-4">
            <v-icon icon="mdi-draw-pen" size="28" color="elaw-gold" />
            <div class="text-subtitle-1 font-weight-bold">ส่งออกและลงนาม e-Sign</div>
          </div>

          <!-- Published -->
          <template v-if="isPublished">
            <v-alert type="success" variant="tonal" density="comfortable" class="mb-4" prepend-icon="mdi-check-decagram-outline">
              เผยแพร่แล้ว — เอกสารนี้สามารถค้นหาได้บนหน้าสาธารณะ
            </v-alert>
            <v-alert v-if="pdfExportError" type="error" variant="tonal" density="compact" class="mb-3">{{ pdfExportError }}</v-alert>
            <v-alert v-if="docxExportError" type="error" variant="tonal" density="compact" class="mb-3">{{ docxExportError }}</v-alert>
            <div class="d-flex flex-wrap ga-3">
              <v-btn color="admin-primary" prepend-icon="mdi-earth" @click="router.push(`/law/${props.documentId}`)">
                ดูหน้าเผยแพร่
              </v-btn>
              <v-btn
                color="admin-primary"
                variant="outlined"
                prepend-icon="mdi-file-pdf-box"
                :loading="exportingPdf"
                @click="handlePdfExport"
              >
                ส่งออก PDF
              </v-btn>
              <v-btn
                variant="outlined"
                prepend-icon="mdi-microsoft-word"
                :loading="exportingDocx"
                @click="handleWordExport"
              >
                ส่งออก Word
              </v-btn>
              <v-btn
                v-if="canExportOriginalPdf"
                size="small"
                variant="outlined"
                prepend-icon="mdi-file-word-box"
                :loading="exportingOriginalPdf"
                @click="handleOriginalPdfExport"
              >
                PDF ตรงจาก Word
              </v-btn>
            </div>
          </template>

          <!-- Exported, awaiting confirm -->
          <template v-else-if="esignExportedAt">
            <v-alert type="info" variant="tonal" density="comfortable" class="mb-4">
              ส่งออกแล้วเมื่อ {{ formatThaiDate(esignExportedAt) }} — รอการยืนยันลงนาม
            </v-alert>
            <v-alert v-if="confirmError" type="error" variant="tonal" density="compact" class="mb-3">
              {{ confirmError }}
            </v-alert>
            <v-alert v-if="pdfExportError" type="error" variant="tonal" density="compact" class="mb-3">
              {{ pdfExportError }}
            </v-alert>
            <v-alert v-if="docxExportError" type="error" variant="tonal" density="compact" class="mb-3">
              {{ docxExportError }}
            </v-alert>
            <v-alert v-if="originalPdfExportError" type="error" variant="tonal" density="compact" class="mb-3">
              {{ originalPdfExportError }}
            </v-alert>
            <div class="d-flex flex-wrap ga-3">
              <v-btn
                color="elaw-gold"
                prepend-icon="mdi-check-circle-outline"
                :loading="confirming"
                @click="handleConfirmEsign"
              >
                ยืนยันลงนาม (เผยแพร่)
              </v-btn>
              <v-btn
                color="admin-primary"
                prepend-icon="mdi-file-pdf-box"
                :loading="exportingPdf"
                @click="handlePdfExport"
              >
                ส่งออก PDF อีกครั้ง
              </v-btn>
              <v-btn
                variant="outlined"
                prepend-icon="mdi-microsoft-word"
                :loading="exportingDocx"
                @click="handleWordExport"
              >
                ส่งออก Word อีกครั้ง
              </v-btn>
              <v-btn
                v-if="canExportOriginalPdf"
                size="small"
                variant="outlined"
                prepend-icon="mdi-file-word-box"
                :loading="exportingOriginalPdf"
                @click="handleOriginalPdfExport"
              >
                PDF ตรงจาก Word
              </v-btn>
            </div>
          </template>

          <!-- Not yet exported -->
          <template v-else>
            <v-alert v-if="pdfExportError" type="error" variant="tonal" density="compact" class="mb-3">
              {{ pdfExportError }}
            </v-alert>
            <v-alert v-if="docxExportError" type="error" variant="tonal" density="compact" class="mb-3">
              {{ docxExportError }}
            </v-alert>
            <v-alert v-if="originalPdfExportError" type="error" variant="tonal" density="compact" class="mb-3">
              {{ originalPdfExportError }}
            </v-alert>
            <div class="d-flex flex-wrap ga-3">
              <v-btn
                color="admin-primary"
                prepend-icon="mdi-file-pdf-box"
                :loading="exportingPdf"
                @click="handlePdfExport"
              >
                ส่งออก PDF สำหรับ e-Sign
              </v-btn>
              <v-btn
                variant="outlined"
                prepend-icon="mdi-microsoft-word"
                :loading="exportingDocx"
                @click="handleWordExport"
              >
                ส่งออก Word สำหรับ e-Sign
              </v-btn>
              <v-btn
                v-if="canExportOriginalPdf"
                size="small"
                variant="outlined"
                prepend-icon="mdi-file-word-box"
                :loading="exportingOriginalPdf"
                @click="handleOriginalPdfExport"
              >
                PDF ตรงจาก Word
              </v-btn>
            </div>
          </template>
        </v-card>

        <v-card border rounded="lg" class="pa-6">
          <div class="text-subtitle-2 font-weight-bold mb-4">ดำเนินการต่อ</div>
          <div class="d-flex flex-wrap ga-3">
            <v-btn
              variant="outlined"
              color="admin-primary"
              prepend-icon="mdi-database-cog-outline"
              @click="router.push(`/documents/${props.documentId}/rag`)"
            >
              แก้ไข RAG บล็อก
            </v-btn>
            <v-btn
              variant="outlined"
              color="admin-primary"
              prepend-icon="mdi-information-outline"
              @click="router.push(`/documents/${props.documentId}/law-info`)"
            >
              แก้ไขข้อมูลเอกสาร
            </v-btn>
            <v-btn
              variant="outlined"
              color="admin-primary"
              prepend-icon="mdi-graph-outline"
              @click="router.push(`/documents/${props.documentId}/relations`)"
            >
              แก้ไขความสัมพันธ์
            </v-btn>
            <v-btn
              variant="outlined"
              color="admin-primary"
              prepend-icon="mdi-shield-lock-outline"
              @click="router.push(`/documents/${props.documentId}/permissions`)"
            >
              จัดการสิทธิ์
            </v-btn>
            <v-btn
              variant="tonal"
              color="admin-primary"
              prepend-icon="mdi-eye-outline"
              @click="router.push(`/law/${props.documentId}`)"
            >
              ดูหน้าเผยแพร่
            </v-btn>
          </div>
        </v-card>
      </template>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { confirmEsign, downloadOriginalPdfExport, downloadPdfExport, downloadWordExport, fetchReview, fetchStatus } from '../../api/client';
import type { DocumentStatus, LawMeta, ReviewDocument } from '../../types/document';
import AppShell from '../../components/shared/AppShell.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();

const loading = ref(true);
const review = ref<ReviewDocument | null>(null);
const docStatus = ref<DocumentStatus | null>(null);
const exportingPdf = ref(false);
const exportingDocx = ref(false);
const exportingOriginalPdf = ref(false);
const pdfExportError = ref('');
const docxExportError = ref('');
const originalPdfExportError = ref('');
const confirming = ref(false);
const confirmError = ref('');

onMounted(async () => {
  try {
    [review.value, docStatus.value] = await Promise.all([
      fetchReview(props.documentId),
      fetchStatus(props.documentId),
    ]);
  } catch {
    // non-fatal: render whatever data was loaded
  } finally {
    loading.value = false;
  }
});

const meta = computed<LawMeta | undefined>(() => review.value?.law_meta);
const docTitle = computed(() => meta.value?.title || review.value?.source_file || props.documentId);
const esignExportedAt = computed(() => docStatus.value?.esign_exported_at ?? null);
const canExportOriginalPdf = computed(() => /\.(docx?|doc)$/i.test(docStatus.value?.source_file ?? ''));
// Published = the RAG export actually ran and was indexed (searchable on /law).
// Reaching workflow step 6 only means the permissions step was completed — the
// document is not searchable until it has been exported/ingested.
const isPublished = computed(() => {
  const s = docStatus.value?.status;
  return s === 'ingested' || s === 'exported';
});

function formatThaiDate(iso: string): string {
  return new Date(iso).toLocaleString('th-TH');
}

async function handleWordExport(): Promise<void> {
  exportingDocx.value = true;
  docxExportError.value = '';
  try {
    await downloadWordExport(props.documentId);
    docStatus.value = await fetchStatus(props.documentId);
  } catch (error) {
    docxExportError.value = error instanceof Error ? error.message : 'ส่งออก Word ไม่สำเร็จ';
  } finally {
    exportingDocx.value = false;
  }
}

async function handlePdfExport(): Promise<void> {
  exportingPdf.value = true;
  pdfExportError.value = '';
  try {
    await downloadPdfExport(props.documentId);
    docStatus.value = await fetchStatus(props.documentId);
  } catch (error) {
    pdfExportError.value = error instanceof Error ? error.message : 'ส่งออก PDF ไม่สำเร็จ';
  } finally {
    exportingPdf.value = false;
  }
}

async function handleOriginalPdfExport(): Promise<void> {
  exportingOriginalPdf.value = true;
  originalPdfExportError.value = '';
  try {
    await downloadOriginalPdfExport(props.documentId);
  } catch (error) {
    originalPdfExportError.value = error instanceof Error ? error.message : 'ส่งออก PDF ตรงจาก Word ไม่สำเร็จ';
  } finally {
    exportingOriginalPdf.value = false;
  }
}

async function handleConfirmEsign(): Promise<void> {
  confirming.value = true;
  confirmError.value = '';
  try {
    await confirmEsign(props.documentId);
    await router.push(`/law/${props.documentId}`);
  } catch (error) {
    confirmError.value = error instanceof Error ? error.message : 'ยืนยันไม่สำเร็จ';
    confirming.value = false;
  }
}
</script>

<style scoped>
.result-page {
  max-width: 720px;
}
</style>
