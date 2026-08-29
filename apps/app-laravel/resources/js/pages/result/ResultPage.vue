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
            <v-chip v-if="meta?.promulgation_date" size="small" variant="outlined">ประกาศ {{ formatThaiDate(meta.promulgation_date) }}</v-chip>
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

          <!-- Confirm-error dialog -->
          <v-dialog v-model="showConfirmErrorDialog" max-width="480" persistent>
            <v-card rounded="lg" class="pa-2">
              <v-card-title class="d-flex align-center ga-2 pa-4">
                <v-icon icon="mdi-alert-circle-outline" color="error" />
                <span>เผยแพร่ไม่สำเร็จ</span>
              </v-card-title>
              <v-card-text class="pa-4 pt-0">
                <p class="text-body-2 text-medium-emphasis mb-3">{{ confirmErrorMessage }}</p>
                <v-alert type="info" variant="tonal" density="compact">
                  ตรวจสอบว่าเอกสารมีข้อมูลครบถ้วน จากนั้นลองอีกครั้ง หรือไปที่ฐานข้อมูลเพื่อตรวจสอบสถานะ
                </v-alert>
              </v-card-text>
              <v-card-actions class="pa-4 pt-0 d-flex flex-wrap ga-2">
                <v-btn color="elaw-gold" :loading="confirming" prepend-icon="mdi-refresh" @click="retryConfirm">
                  ลองอีกครั้ง
                </v-btn>
                <v-btn variant="outlined" prepend-icon="mdi-database-search-outline" @click="goToDatabase">
                  ไปที่ฐานข้อมูล
                </v-btn>
                <v-btn variant="text" :disabled="confirming" @click="showConfirmErrorDialog = false">ปิด</v-btn>
              </v-card-actions>
            </v-card>
          </v-dialog>

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
              จัดลำดับเนื้อหา
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
import { formatThaiDate } from '../../utils/thaiDate';
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
const showConfirmErrorDialog = ref(false);
const confirmErrorMessage = ref('');

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
  try {
    await confirmEsign(props.documentId);
    await router.push('/database');
  } catch (error) {
    confirmErrorMessage.value = error instanceof Error ? error.message : 'ยืนยันไม่สำเร็จ';
    showConfirmErrorDialog.value = true;
    confirming.value = false;
  }
}

async function retryConfirm(): Promise<void> {
  showConfirmErrorDialog.value = false;
  await handleConfirmEsign();
}

function goToDatabase(): void {
  showConfirmErrorDialog.value = false;
  void router.push('/database');
}
</script>

<style scoped>
.result-page {
  max-width: 1400px;
}
</style>
