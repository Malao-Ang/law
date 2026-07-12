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
            <v-btn color="primary" prepend-icon="mdi-earth" @click="router.push(`/law/${props.documentId}`)">
              ดูหน้าเผยแพร่
            </v-btn>
          </template>

          <!-- Exported, awaiting confirm -->
          <template v-else-if="esignExportedAt">
            <v-alert type="info" variant="tonal" density="comfortable" class="mb-4">
              ส่งออกแล้วเมื่อ {{ formatThaiDate(esignExportedAt) }} — รอการยืนยันลงนาม
            </v-alert>
            <v-alert v-if="confirmError" type="error" variant="tonal" density="compact" class="mb-3">
              {{ confirmError }}
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
                variant="outlined"
                prepend-icon="mdi-microsoft-word"
                :loading="exporting"
                @click="handleWordExport"
              >
                ส่งออก Word อีกครั้ง
              </v-btn>
            </div>
          </template>

          <!-- Not yet exported -->
          <template v-else>
            <v-alert v-if="exportError" type="error" variant="tonal" density="compact" class="mb-3">
              {{ exportError }}
            </v-alert>
            <v-btn
              color="admin-primary"
              prepend-icon="mdi-microsoft-word"
              :loading="exporting"
              @click="handleWordExport"
            >
              Export as Word for e-Sign
            </v-btn>
          </template>
        </v-card>

        <v-card border rounded="lg" class="pa-6">
          <div class="text-subtitle-2 font-weight-bold mb-4">ดำเนินการต่อ</div>
          <div class="d-flex flex-wrap ga-3">
            <v-btn
              variant="outlined"
              prepend-icon="mdi-database-cog-outline"
              @click="router.push(`/documents/${props.documentId}/rag`)"
            >
              แก้ไข RAG บล็อก
            </v-btn>
            <v-btn
              variant="outlined"
              prepend-icon="mdi-information-outline"
              @click="router.push(`/documents/${props.documentId}/law-info`)"
            >
              แก้ไขข้อมูลเอกสาร
            </v-btn>
            <v-btn
              variant="outlined"
              prepend-icon="mdi-graph-outline"
              @click="router.push(`/documents/${props.documentId}/relations`)"
            >
              แก้ไขความสัมพันธ์
            </v-btn>
            <v-btn
              variant="tonal"
              color="primary"
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
import { confirmEsign, downloadWordExport, fetchReview, fetchStatus } from '../../api/client';
import type { DocumentStatus, LawMeta, ReviewDocument } from '../../types/document';
import AppShell from '../../components/shared/AppShell.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();

const loading = ref(true);
const review = ref<ReviewDocument | null>(null);
const docStatus = ref<DocumentStatus | null>(null);
const exporting = ref(false);
const exportError = ref('');
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
const isPublished = computed(() => (docStatus.value?.workflow_completed_step ?? 0) >= 6);

function formatThaiDate(iso: string): string {
  return new Date(iso).toLocaleString('th-TH');
}

async function handleWordExport(): Promise<void> {
  exporting.value = true;
  exportError.value = '';
  try {
    await downloadWordExport(props.documentId);
    docStatus.value = await fetchStatus(props.documentId);
  } catch (error) {
    exportError.value = error instanceof Error ? error.message : 'ส่งออกไม่สำเร็จ';
  } finally {
    exporting.value = false;
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
