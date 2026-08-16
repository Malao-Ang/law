<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'เอกสารที่เกี่ยวข้อง']"
    title="นำเข้าเอกสารกฎหมาย"
    subtitle="ขั้นตอนที่ 5 จาก 6: ความสัมพันธ์กฎหมาย"
  >
    <WorkflowFooterBar
      :step="5"
      next-label="ถัดไป"
      extra-label="ส่งไป E-Sign"
      extra-icon="mdi-draw-pen"
      :next-loading="documentStore.saving && !esignLeaving"
      :extra-loading="documentStore.saving && esignLeaving"
      :next-disabled="documentStore.saving"
      :extra-disabled="documentStore.saving"
      @back="router.push(`/documents/${props.documentId}/law-info`)"
      @next="saveAndNext"
      @extra="saveAndEsign"
    />
    <div class="mx-auto relations-page" style="max-width:960px; padding-bottom:60px">
      <WorkflowStepper :step="5" />

      <div v-if="documentStore.loading" class="d-flex flex-column align-center justify-center pa-12 ga-3 text-medium-emphasis">
        <v-progress-circular indeterminate color="admin-primary" />
        <span>กำลังโหลด...</span>
      </div>

      <template v-else>
        <v-alert type="info" variant="tonal" density="compact" class="mb-4">
          ขั้นตอนนี้ยังไม่บังคับ กรอกตอนนี้หรือส่งไป E-Sign ก่อนแล้วค่อยกลับมาก็ได้
        </v-alert>

        <v-alert v-if="documentStore.saveError" type="error" variant="tonal" density="compact" closable class="mb-4"
          @click:close="documentStore.setSaveError()">
          {{ documentStore.saveError }}
        </v-alert>

        <!-- Hierarchy: parent document -->
        <v-card flat border rounded="lg" class="pa-6 mb-4">
          <div class="d-flex align-center ga-2 mb-2">
            <v-icon icon="mdi-file-tree" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">ลำดับชั้นเอกสาร</span>
          </div>
          <p class="text-caption text-medium-emphasis mb-4">
            เลือกได้ว่าเอกสารนี้ออกภายใต้กฎหมายฉบับใด เช่น ระเบียบนี้ออกตาม พ.ร.บ. เรื่องนั้น
            เลือกได้หลายฉบับ ไม่บังคับ
          </p>
          <v-autocomplete
            v-model="parentIds"
            :items="parentItems"
            item-title="title"
            item-value="document_id"
            label="ออกภายใต้กฎหมาย (ไม่บังคับ)"
            placeholder="ค้นหากฎหมายแม่"
            multiple
            chips
            closable-chips
            clearable
            prepend-inner-icon="mdi-file-tree"
            :loading="catalogLoading"
          />
        </v-card>

        <!-- Hierarchy: child documents (read-only) -->
        <v-card flat border rounded="lg" class="pa-6 mb-4">
          <div class="d-flex align-center ga-2 mb-2">
            <v-icon icon="mdi-file-tree-outline" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">เอกสารที่ออกภายใต้ฉบับนี้</span>
            <v-chip v-if="children.length" size="x-small" color="admin-primary" class="ml-1">
              {{ children.length }}
            </v-chip>
          </div>
          <p class="text-caption text-medium-emphasis mb-4">
            เอกสารอื่นที่เลือกฉบับนี้ไว้แล้ว ดูได้อย่างเดียว ถ้าจะเพิ่มต้องไปแก้ที่เอกสารนั้น
          </p>
          <div v-if="catalogLoading" class="d-flex align-center justify-center pa-6">
            <v-progress-circular indeterminate size="24" color="admin-primary" />
          </div>
          <div v-else-if="children.length === 0" class="text-body-2 text-medium-emphasis pa-2">
            ยังไม่มีเอกสารที่ออกภายใต้ฉบับนี้
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

        <!-- Document-level edges -->
        <v-card flat border rounded="lg" class="pa-6 mb-4">
          <div class="d-flex align-center ga-2 mb-2 flex-wrap">
            <v-icon icon="mdi-link-variant" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">ความสัมพันธ์ระดับเอกสาร</span>
            <v-spacer />
            <v-btn
              size="small"
              variant="outlined"
              prepend-icon="mdi-plus"
              :disabled="documentStore.saving"
              @click="openDocumentRelation()"
            >เพิ่ม</v-btn>
          </div>
          <p class="text-caption text-medium-emphasis mb-4">
            บอกว่าเอกสารทั้งฉบับเกี่ยวข้องกับกฎหมายอื่นอย่างไร เช่น แทนที่ ออกตามอำนาจ หรือเกี่ยวข้อง
            เพิ่มได้หลายรายการ เลือกได้เฉพาะเอกสารที่ออกภายใต้กฎหมายแม่ด้านบน
          </p>
          <div v-if="documentLevelRelations.length" class="relations-list">
            <div v-for="rel in documentLevelRelations" :key="rel.id" class="relations-list__row">
              <v-chip
                size="x-small"
                :color="RELATION_TYPE_COLORS[rel.type]"
                variant="tonal"
                :prepend-icon="RELATION_TYPE_ICONS[rel.type]"
                class="relations-list__type"
              >
                {{ relationTypeLabel(rel.type) }}
              </v-chip>
              <span class="relations-list__target">{{ formatRelationTarget(rel) }}</span>
              <span v-if="rel.note" class="relations-list__note text-caption text-medium-emphasis">{{ rel.note }}</span>
              <v-spacer />
              <v-btn
                icon
                variant="text"
                size="x-small"
                color="error"
                :disabled="documentStore.saving"
                @click="removeRelation(rel.id)"
              >
                <v-icon icon="mdi-delete-outline" size="18" />
              </v-btn>
            </div>
          </div>
          <div v-else class="text-body-2 text-medium-emphasis">ยังไม่มีความสัมพันธ์ระดับเอกสาร</div>
        </v-card>

        <!-- Section-level edges -->
        <v-card flat border rounded="lg" class="pa-6 mb-4">
          <div class="d-flex align-center ga-2 mb-2">
            <v-icon icon="mdi-vector-link" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">ความสัมพันธ์ระดับข้อ</span>
          </div>
          <p class="text-caption text-medium-emphasis mb-4">
            บอกว่าข้อไหนในเอกสารนี้เกี่ยวข้องกับกฎหมายอื่น เช่น แก้ไขหรือยกเลิกข้อของกฎหมายฉบับอื่น
            กดเพิ่มที่ข้อที่ต้องการ
          </p>
          <div v-if="sections.length === 0" class="text-body-2 text-medium-emphasis">
            ไม่พบข้อในเอกสาร
          </div>
          <div v-else class="d-flex flex-column ga-3">
            <div v-for="entry in sectionRelationEntries" :key="entry.section.id" class="relations-section">
              <div class="relations-section__head">
                <v-chip size="small" variant="tonal" color="admin-primary">{{ entry.section.badge }}</v-chip>
                <v-spacer />
                <v-btn
                  size="x-small"
                  variant="tonal"
                  color="admin-primary"
                  prepend-icon="mdi-plus"
                  :disabled="documentStore.saving"
                  @click="openSectionRelation(entry.section)"
                >เพิ่ม</v-btn>
              </div>
              <div v-if="entry.relations.length" class="relations-list mt-2">
                <div v-for="rel in entry.relations" :key="rel.id" class="relations-list__row">
                  <v-chip
                    size="x-small"
                    :color="RELATION_TYPE_COLORS[rel.type]"
                    variant="tonal"
                    :prepend-icon="RELATION_TYPE_ICONS[rel.type]"
                    class="relations-list__type"
                  >
                    {{ relationTypeLabel(rel.type) }}
                  </v-chip>
                  <span class="relations-list__target">{{ formatRelationTarget(rel) }}</span>
                  <span v-if="rel.note" class="relations-list__note text-caption text-medium-emphasis">{{ rel.note }}</span>
                  <v-spacer />
                  <v-btn
                    icon
                    variant="text"
                    size="x-small"
                    color="error"
                    :disabled="documentStore.saving"
                    @click="removeRelation(rel.id)"
                  >
                    <v-icon icon="mdi-delete-outline" size="18" />
                  </v-btn>
                </div>
              </div>
              <div v-else class="text-caption text-medium-emphasis mt-1 pl-1">ยังไม่มีความสัมพันธ์</div>
            </div>
          </div>
        </v-card>
      </template>
    </div>

    <AddRelationDialog
      v-if="relationDialog.open"
      :scope="relationDialog.scope"
      :block-id="relationDialog.blockId"
      :default-type="relationDialog.defaultType"
      :exclude-document-id="props.documentId"
      :parent-document-ids="parentIds"
      :existing-relations="relations"
      :section-labels="sectionLabels"
      @close="closeRelationDialog"
      @save="onRelationSave"
    />
  </AppShell>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useDocumentStore } from '../../stores/documentStore';
import { useSnackbarStore } from '../../stores/snackbarStore';
import { listDocuments } from '../../api/client';
import type { DocumentListItem, LawRelation, RelationScope, RelationType } from '../../types/document';
import { childDocuments, isPickableDocument } from '../../composables/useLawCatalog';
import { buildSections, documentRelations, relationsForSection, type LawSection } from '../../composables/useLawSections';
import {
  RELATION_TYPE_COLORS,
  RELATION_TYPE_ICONS,
  formatRelationTarget,
  relationTypeLabel,
} from '../../types/lawRelation';
import AppShell from '../../components/shared/AppShell.vue';
import WorkflowStepper from '../../components/shared/WorkflowStepper.vue';
import WorkflowFooterBar from '../../components/shared/WorkflowFooterBar.vue';
import AddRelationDialog from '../../components/shared/AddRelationDialog.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();
const snackbar = useSnackbarStore();

const catalog = ref<DocumentListItem[]>([]);
const catalogLoading = ref(false);
const parentIds = ref<string[]>([]);
const esignLeaving = ref(false);

const relationDialog = ref<{
  open: boolean;
  scope: RelationScope;
  blockId: string | null;
  defaultType?: RelationType;
}>({
  open: false,
  scope: 'document',
  blockId: null,
});

const relations = computed<LawRelation[]>(() => documentStore.review?.relations ?? []);
const sections = computed(() => buildSections(documentStore.review));
const sectionLabels = computed<Record<string, string>>(() =>
  Object.fromEntries(sections.value.map((section) => [section.id, section.badge])),
);
const documentLevelRelations = computed(() => documentRelations(relations.value));

const sectionRelationEntries = computed(() =>
  sections.value.map((section) => ({
    section,
    relations: relationsForSection(relations.value, section.id),
  })),
);

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

function openSectionRelation(section: LawSection, defaultType?: RelationType): void {
  relationDialog.value = {
    open: true,
    scope: 'section',
    blockId: section.id,
    defaultType,
  };
}

function openDocumentRelation(defaultType?: RelationType): void {
  relationDialog.value = {
    open: true,
    scope: 'document',
    blockId: null,
    defaultType,
  };
}

function closeRelationDialog(): void {
  relationDialog.value.open = false;
}

async function removeRelation(id: string): Promise<void> {
  const ok = await documentStore.saveRelations(relations.value.filter((r) => r.id !== id));
  if (ok) snackbar.success('ลบความสัมพันธ์แล้ว');
}

async function onRelationSave(relation: LawRelation): Promise<void> {
  closeRelationDialog();
  const ok = await documentStore.saveRelations([...relations.value, relation]);
  if (ok) snackbar.success('เพิ่มความสัมพันธ์แล้ว');
}

async function persistStepFive(): Promise<boolean> {
  const saved = await documentStore.saveLawMeta({
    parent_document_ids: parentIds.value,
    parent_document_id: parentIds.value[0] ?? null,
  });
  if (!saved) return false;
  return documentStore.completeWorkflowStep(5);
}

async function saveAndNext(): Promise<void> {
  esignLeaving.value = false;
  if (!await persistStepFive()) return;
  router.push(`/documents/${props.documentId}/permissions`);
}

async function saveAndEsign(): Promise<void> {
  esignLeaving.value = true;
  try {
    if (!await persistStepFive()) return;
    router.push(`/documents/${props.documentId}/esign`);
  } finally {
    esignLeaving.value = false;
  }
}

onMounted(async () => {
  await documentStore.fetch(props.documentId);
  const meta = documentStore.review?.law_meta;
  parentIds.value = meta?.parent_document_ids?.length
    ? [...meta.parent_document_ids]
    : (meta?.parent_document_id ? [meta.parent_document_id] : []);
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

<style scoped>
.relations-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.relations-list__row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 8px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  flex-wrap: wrap;
}

.relations-list__type {
  flex-shrink: 0;
}

.relations-list__target {
  font-size: 14px;
  color: #334155;
}

.relations-list__note {
  flex-basis: 100%;
  padding-left: 2px;
}

.relations-section {
  padding: 12px 14px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
}

.relations-section__head {
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
