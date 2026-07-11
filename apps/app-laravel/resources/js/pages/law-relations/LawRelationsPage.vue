<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'เอกสารที่เกี่ยวข้อง']"
    title="นำเข้าเอกสารกฎหมาย"
    subtitle="ขั้นตอนที่ 5 จาก 6: เอกสารที่เกี่ยวข้อง"
  >
    <WorkflowFooterBar
      :step="5"
      next-label="ถัดไป"
      :next-loading="documentStore.saving"
      @back="router.push(`/documents/${props.documentId}/law-info`)"
      @next="saveAndNext"
    />
    <div class="mx-auto" style="max-width:860px; padding-bottom:60px">
      <WorkflowStepper :step="5" />

      <div v-if="documentStore.loading" class="d-flex flex-column align-center justify-center pa-12 ga-3 text-medium-emphasis">
        <v-progress-circular indeterminate color="admin-primary" />
        <span>กำลังโหลด...</span>
      </div>

      <template v-else>
        <v-alert v-if="documentStore.saveError" type="error" variant="tonal" density="compact" closable class="mb-4"
          @click:close="documentStore.setSaveError()">
          {{ documentStore.saveError }}
        </v-alert>

        <!-- กฎหมายแม่ -->
        <v-card flat border rounded="lg" class="pa-6 mb-4">
          <div class="d-flex align-center ga-2 mb-5">
            <v-icon icon="mdi-file-tree" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">เอกสารต้นสังกัด (กฎหมายแม่)</span>
          </div>
          <v-row dense>
            <v-col cols="12">
              <v-select
                v-model="parentId"
                :items="parentItems"
                item-title="title"
                item-value="document_id"
                label="กฎหมายแม่ (ไม่บังคับ)"
                placeholder="- ไม่มี / เป็นกฎหมายหลัก -"
                clearable
                prepend-inner-icon="mdi-file-tree"
                :loading="catalogLoading"
              />
            </v-col>
          </v-row>
        </v-card>

        <!-- เอกสารที่สังกัด (ลูก) -->
        <v-card flat border rounded="lg" class="pa-6">
          <div class="d-flex align-center ga-2 mb-4">
            <v-icon icon="mdi-file-tree-outline" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">เอกสารที่สังกัด (เอกสารลูก)</span>
            <v-chip v-if="children.length" size="x-small" color="admin-primary" class="ml-1">
              {{ children.length }}
            </v-chip>
          </div>
          <div v-if="catalogLoading" class="d-flex align-center justify-center pa-6">
            <v-progress-circular indeterminate size="24" color="admin-primary" />
          </div>
          <div v-else-if="children.length === 0" class="text-body-2 text-medium-emphasis pa-2">
            ยังไม่มีเอกสารที่อ้างอิงเอกสารนี้เป็นกฎหมายแม่
          </div>
          <v-list v-else density="compact" lines="one">
            <v-list-item
              v-for="doc in children"
              :key="doc.document_id"
              :title="doc.title"
              :subtitle="doc.document_id"
              prepend-icon="mdi-file-document-outline"
              :href="`/documents/${doc.document_id}/law-info`"
            >
              <template #append>
                <v-chip size="x-small" :color="statusColor(doc.status)" variant="tonal">
                  {{ doc.status }}
                </v-chip>
              </template>
            </v-list-item>
          </v-list>
        </v-card>
      </template>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useDocumentStore } from '../../stores/documentStore';
import { listDocuments } from '../../api/client';
import type { DocumentListItem } from '../../types/document';
import { childDocuments, isPickableDocument } from '../../composables/useLawCatalog';
import AppShell from '../../components/shared/AppShell.vue';
import WorkflowStepper from '../../components/shared/WorkflowStepper.vue';
import WorkflowFooterBar from '../../components/shared/WorkflowFooterBar.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();

const catalog = ref<DocumentListItem[]>([]);
const catalogLoading = ref(false);

const parentId = ref<string | null>(null);

const parentItems = computed(() =>
  catalog.value.filter(
    (doc) => doc.document_id !== props.documentId && isPickableDocument(doc),
  ),
);

const children = computed(() =>
  childDocuments(catalog.value, props.documentId),
);

function statusColor(status: string): string {
  if (status === 'exported' || status === 'ingested') return 'success';
  if (status === 'done') return 'admin-primary';
  return 'default';
}

async function saveAndNext(): Promise<void> {
  const saved = await documentStore.saveLawMeta({ parent_document_id: parentId.value });
  if (!saved) return;
  const relationProgressed = await documentStore.completeWorkflowStep(5);
  if (!relationProgressed) return;
  router.push(`/documents/${props.documentId}/permissions`);
}

onMounted(async () => {
  await documentStore.fetch(props.documentId);
  parentId.value = documentStore.review?.law_meta?.parent_document_id ?? null;
  catalogLoading.value = true;
  try {
    const res = await listDocuments();
    catalog.value = res.documents;
  } catch {
    catalog.value = [];
  } finally {
    catalogLoading.value = false;
  }
});
onBeforeUnmount(() => documentStore.reset());
</script>
