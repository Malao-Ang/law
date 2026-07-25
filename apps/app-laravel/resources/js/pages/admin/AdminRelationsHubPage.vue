<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'ความสัมพันธ์กฎหมาย']"
    title="ความสัมพันธ์กฎหมาย"
    subtitle="จัดการความสัมพันธ์ระหว่างเอกสารที่เผยแพร่แล้ว"
  >
    <div class="relations-hub">
      <div class="relations-hub__list">
        <v-text-field
          v-model="search"
          placeholder="ค้นหา..."
          prepend-inner-icon="mdi-magnify"
          variant="outlined"
          density="compact"
          hide-details
          class="mb-3"
        />

        <div v-if="catalogLoading" class="d-flex justify-center pa-8">
          <v-progress-circular indeterminate color="admin-primary" />
        </div>

        <v-list v-else nav density="compact">
          <v-list-item
            v-for="doc in filteredCatalog"
            :key="doc.document_id"
            :title="doc.title || doc.document_id"
            :subtitle="doc.document_id"
            :active="selectedId === doc.document_id"
            color="admin-primary"
            rounded="lg"
            class="mb-1"
            @click="selectDocument(doc.document_id)"
          >
            <template #prepend>
              <v-icon icon="mdi-file-document-outline" size="18" class="mr-2" />
            </template>
          </v-list-item>
          <div v-if="filteredCatalog.length === 0" class="text-caption text-medium-emphasis text-center pa-4">
            ไม่พบเอกสาร
          </div>
        </v-list>
      </div>

      <div class="relations-hub__editor">
        <div v-if="!selectedId" class="d-flex flex-column align-center justify-center pa-16 text-medium-emphasis ga-3">
          <v-icon icon="mdi-graph-outline" size="48" />
          <span>เลือกเอกสารทางซ้ายเพื่อแก้ไขความสัมพันธ์</span>
        </div>

        <template v-else>
          <div v-if="documentStore.loading" class="d-flex justify-center pa-12">
            <v-progress-circular indeterminate color="admin-primary" />
          </div>

          <template v-else>
            <div class="d-flex align-center ga-3 mb-5">
              <v-icon icon="mdi-file-document-outline" color="admin-primary" size="22" />
              <div class="min-width-0">
                <div class="text-subtitle-1 font-weight-bold text-truncate">
                  {{ documentStore.review?.law_meta?.title || selectedTitle || selectedId }}
                </div>
                <div class="text-caption text-medium-emphasis">{{ selectedId }}</div>
              </div>
              <v-spacer />
              <v-btn
                size="small"
                variant="tonal"
                color="admin-primary"
                :to="`/documents/${selectedId}/relations`"
                prepend-icon="mdi-open-in-new"
                class="text-none"
              >
                เปิดหน้าเต็ม
              </v-btn>
            </div>

            <v-alert
              v-if="documentStore.saveError"
              type="error"
              variant="tonal"
              density="compact"
              closable
              class="mb-4"
              @click:close="documentStore.setSaveError()"
            >
              {{ documentStore.saveError }}
            </v-alert>

            <v-card flat border rounded="lg" class="pa-5 mb-4">
              <div class="d-flex align-center ga-2 mb-3">
                <v-icon icon="mdi-link-variant" color="admin-primary" size="18" />
                <span class="text-body-1 font-weight-bold">ความสัมพันธ์ระดับเอกสาร</span>
                <v-spacer />
                <v-btn
                  size="x-small"
                  variant="outlined"
                  prepend-icon="mdi-plus"
                  :disabled="documentStore.saving"
                  @click="openDocumentRelation()"
                >
                  เพิ่ม
                </v-btn>
              </div>

              <div v-if="documentLevelRelations.length" class="relations-list">
                <div v-for="rel in documentLevelRelations" :key="rel.id" class="relations-list__row">
                  <v-chip
                    size="x-small"
                    :color="RELATION_TYPE_COLORS[rel.type]"
                    variant="tonal"
                    :prepend-icon="RELATION_TYPE_ICONS[rel.type]"
                    class="flex-shrink-0"
                  >
                    {{ relationTypeLabel(rel.type) }}
                  </v-chip>
                  <span class="text-body-2 relations-list__target">{{ formatRelationTarget(rel) }}</span>
                  <span v-if="rel.note" class="text-caption text-medium-emphasis relations-list__note">{{ rel.note }}</span>
                  <v-spacer />
                  <v-btn icon variant="text" size="x-small" color="error" :disabled="documentStore.saving" @click="removeRelation(rel.id)">
                    <v-icon icon="mdi-delete-outline" size="16" />
                  </v-btn>
                </div>
              </div>
              <div v-else class="text-body-2 text-medium-emphasis">ยังไม่มีความสัมพันธ์ระดับเอกสาร</div>
            </v-card>

            <v-card flat border rounded="lg" class="pa-5">
              <div class="d-flex align-center ga-2 mb-3">
                <v-icon icon="mdi-vector-link" color="admin-primary" size="18" />
                <span class="text-body-1 font-weight-bold">ความสัมพันธ์ระดับมาตรา / ข้อ</span>
              </div>

              <div v-if="sections.length === 0" class="text-body-2 text-medium-emphasis">
                ไม่พบมาตรา/ข้อในเอกสาร
              </div>

              <div v-else class="d-flex flex-column ga-3">
                <div v-for="entry in sectionRelationEntries" :key="entry.section.id" class="relations-section">
                  <div class="d-flex align-center ga-2">
                    <v-chip size="small" variant="tonal" color="admin-primary">{{ entry.section.badge }}</v-chip>
                    <v-spacer />
                    <v-btn
                      size="x-small"
                      variant="tonal"
                      color="admin-primary"
                      prepend-icon="mdi-plus"
                      :disabled="documentStore.saving"
                      @click="openSectionRelation(entry.section)"
                    >
                      เพิ่ม
                    </v-btn>
                  </div>

                  <div v-if="entry.relations.length" class="relations-list mt-2">
                    <div v-for="rel in entry.relations" :key="rel.id" class="relations-list__row">
                      <v-chip
                        size="x-small"
                        :color="RELATION_TYPE_COLORS[rel.type]"
                        variant="tonal"
                        :prepend-icon="RELATION_TYPE_ICONS[rel.type]"
                        class="flex-shrink-0"
                      >
                        {{ relationTypeLabel(rel.type) }}
                      </v-chip>
                      <span class="text-body-2 relations-list__target">{{ formatRelationTarget(rel) }}</span>
                      <span v-if="rel.note" class="text-caption text-medium-emphasis relations-list__note">{{ rel.note }}</span>
                      <v-spacer />
                      <v-btn icon variant="text" size="x-small" color="error" :disabled="documentStore.saving" @click="removeRelation(rel.id)">
                        <v-icon icon="mdi-delete-outline" size="16" />
                      </v-btn>
                    </div>
                  </div>
                  <div v-else class="text-caption text-medium-emphasis mt-1">ยังไม่มีความสัมพันธ์</div>
                </div>
              </div>
            </v-card>
          </template>
        </template>
      </div>
    </div>

    <AddRelationDialog
      v-if="relationDialog.open"
      :scope="relationDialog.scope"
      :block-id="relationDialog.blockId"
      :default-type="relationDialog.defaultType"
      :exclude-document-id="selectedId ?? ''"
      :existing-relations="relations"
      :section-labels="sectionLabels"
      @close="closeRelationDialog"
      @save="onRelationSave"
    />
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { listDocuments } from '../../api/client';
import type { DocumentListItem, LawRelation, RelationScope, RelationType } from '../../types/document';
import { useDocumentStore } from '../../stores/documentStore';
import { useSnackbarStore } from '../../stores/snackbarStore';
import { buildSections, documentRelations, relationsForSection, type LawSection } from '../../composables/useLawSections';
import {
  RELATION_TYPE_COLORS,
  RELATION_TYPE_ICONS,
  formatRelationTarget,
  relationTypeLabel,
} from '../../types/lawRelation';
import AppShell from '../../components/shared/AppShell.vue';
import AddRelationDialog from '../../components/shared/AddRelationDialog.vue';

const documentStore = useDocumentStore();
const snackbar = useSnackbarStore();

const catalog = ref<DocumentListItem[]>([]);
const catalogLoading = ref(false);
const selectedId = ref<string | null>(null);
const search = ref('');

const relationDialog = ref<{
  open: boolean;
  scope: RelationScope;
  blockId: string | null;
  defaultType?: RelationType;
}>({ open: false, scope: 'document', blockId: null });

const filteredCatalog = computed(() => {
  const published = catalog.value.filter(
    (doc) => doc.status === 'exported' || doc.status === 'ingested' || doc.status === 'done',
  );
  if (!search.value.trim()) return published;
  const query = search.value.trim().toLowerCase();
  return published.filter(
    (doc) => (doc.title || '').toLowerCase().includes(query) || doc.document_id.toLowerCase().includes(query),
  );
});

const relations = computed<LawRelation[]>(() => documentStore.review?.relations ?? []);
const selectedTitle = computed(() =>
  catalog.value.find((doc) => doc.document_id === selectedId.value)?.title ?? '',
);
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

async function selectDocument(id: string): Promise<void> {
  if (selectedId.value === id) return;
  documentStore.reset();
  selectedId.value = id;
  await documentStore.fetch(id);
}

function openDocumentRelation(defaultType?: RelationType): void {
  relationDialog.value = { open: true, scope: 'document', blockId: null, defaultType };
}

function openSectionRelation(section: LawSection, defaultType?: RelationType): void {
  relationDialog.value = { open: true, scope: 'section', blockId: section.id, defaultType };
}

function closeRelationDialog(): void {
  relationDialog.value.open = false;
}

async function removeRelation(id: string): Promise<void> {
  const ok = await documentStore.saveRelations(relations.value.filter((relation) => relation.id !== id));
  if (ok) snackbar.success('ลบความสัมพันธ์แล้ว');
}

async function onRelationSave(relation: LawRelation): Promise<void> {
  closeRelationDialog();
  const ok = await documentStore.saveRelations([...relations.value, relation]);
  if (ok) snackbar.success('เพิ่มความสัมพันธ์แล้ว');
}

async function loadCatalog(): Promise<void> {
  catalogLoading.value = true;
  try {
    const res = await listDocuments();
    catalog.value = res.documents ?? [];
  } catch {
    catalog.value = [];
  } finally {
    catalogLoading.value = false;
  }
}

onMounted(() => void loadCatalog());
onBeforeUnmount(() => documentStore.reset());
</script>

<style scoped>
.relations-hub {
  align-items: start;
  display: grid;
  gap: 20px;
  grid-template-columns: 300px 1fr;
}

.relations-hub__list {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  max-height: calc(100vh - 200px);
  overflow-y: auto;
  padding: 16px;
  position: sticky;
  top: 20px;
}

.relations-hub__editor {
  min-height: 400px;
}

.relations-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.relations-list__row {
  align-items: center;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 8px 10px;
}

.relations-list__target {
  color: #334155;
}

.relations-list__note {
  flex-basis: 100%;
}

.relations-section {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 12px 14px;
}

@media (max-width: 768px) {
  .relations-hub {
    grid-template-columns: 1fr;
  }

  .relations-hub__list {
    max-height: none;
    position: static;
  }
}
</style>
