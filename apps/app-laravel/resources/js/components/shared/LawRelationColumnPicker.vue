<template>
  <div class="law-rel-picker">
    <div class="law-rel-picker__toolbar">
      <v-btn
        size="small"
        variant="tonal"
        color="primary"
        :disabled="!canGoBack"
        prepend-icon="mdi-chevron-left"
        @click="goBack"
      >
        ย้อนกลับ
      </v-btn>
      <div class="law-rel-picker__crumbs text-body-2 text-medium-emphasis">
        <template v-if="breadcrumbs.length === 0">เลือกกฎหมายเป้าหมาย</template>
        <template v-else>
          <span
            v-for="(crumb, index) in breadcrumbs"
            :key="crumb.key"
            class="law-rel-picker__crumb"
          >
            <span v-if="index > 0" class="law-rel-picker__sep">›</span>
            {{ crumb.label }}
          </span>
        </template>
      </div>
      <v-text-field
        v-model="globalSearch"
        density="compact"
        hide-details
        placeholder="ค้นหา..."
        prepend-inner-icon="mdi-magnify"
        variant="outlined"
        class="law-rel-picker__global-search"
      />
    </div>

    <div v-if="loading" class="law-rel-picker__loading">
      <v-progress-circular indeterminate color="primary" size="28" />
      <span class="text-body-2 text-medium-emphasis">กำลังโหลดรายการกฎหมาย...</span>
    </div>

    <div v-else class="law-rel-picker__columns">
      <!-- Column 1: root laws -->
      <div class="law-rel-col">
        <div class="law-rel-col__head">กฎหมายหลัก</div>
        <v-text-field
          v-model="searchCol1"
          density="compact"
          hide-details
          placeholder="ค้นหา..."
          prepend-inner-icon="mdi-magnify"
          variant="outlined"
          class="law-rel-col__search"
        />
        <div class="law-rel-col__list" role="listbox" aria-label="กฎหมายหลัก">
          <button
            v-for="doc in filteredCol1"
            :key="doc.document_id"
            type="button"
            class="law-rel-col__item"
            :class="{ 'is-active': selectedRoot?.document_id === doc.document_id }"
            role="option"
            :aria-selected="selectedRoot?.document_id === doc.document_id"
            @click="selectRoot(doc)"
          >
            <span class="law-rel-col__label">{{ doc.title }}</span>
            <v-icon
              v-if="hasChildren(doc.document_id)"
              icon="mdi-chevron-right"
              size="18"
              class="law-rel-col__chev"
            />
          </button>
          <div v-if="filteredCol1.length === 0" class="law-rel-col__empty">ไม่พบรายการ</div>
        </div>
      </div>

      <!-- Column 2: child laws -->
      <div class="law-rel-col">
        <div class="law-rel-col__head">กฎหมายย่อย</div>
        <v-text-field
          v-model="searchCol2"
          density="compact"
          hide-details
          placeholder="ค้นหา..."
          prepend-inner-icon="mdi-magnify"
          variant="outlined"
          class="law-rel-col__search"
          :disabled="!selectedRoot"
        />
        <div class="law-rel-col__list" role="listbox" aria-label="กฎหมายย่อย">
          <template v-if="selectedRoot">
            <button
              v-if="hasChildren(selectedRoot.document_id)"
              type="button"
              class="law-rel-col__item"
              :class="{ 'is-active': rootWholeSelected && !selectedChild }"
              role="option"
              :aria-selected="rootWholeSelected && !selectedChild"
              @click="selectRootWhole"
            >
              <span class="law-rel-col__label">— ทั้งกฎหมายหลัก —</span>
              <v-icon icon="mdi-chevron-right" size="18" class="law-rel-col__chev" />
            </button>
            <button
              v-for="doc in filteredCol2"
              :key="doc.document_id"
              type="button"
              class="law-rel-col__item"
              :class="{ 'is-active': selectedChild?.document_id === doc.document_id }"
              role="option"
              :aria-selected="selectedChild?.document_id === doc.document_id"
              @click="selectChild(doc)"
            >
              <span class="law-rel-col__label">{{ doc.title }}</span>
              <v-icon icon="mdi-chevron-right" size="18" class="law-rel-col__chev" />
            </button>
            <div v-if="filteredCol2.length === 0" class="law-rel-col__empty">
              {{ hasChildren(selectedRoot.document_id) ? 'ไม่พบรายการ' : 'ไม่มีกฎหมายย่อย — เลือกมาตราในคอลัมน์ถัดไป' }}
            </div>
          </template>
          <div v-else class="law-rel-col__empty">เลือกกฎหมายหลักก่อน</div>
        </div>
      </div>

      <!-- Column 3: sections / articles -->
      <div class="law-rel-col">
        <div class="law-rel-col__head">มาตรา / ข้อ</div>
        <v-text-field
          v-model="searchCol3"
          density="compact"
          hide-details
          placeholder="ค้นหา..."
          prepend-inner-icon="mdi-magnify"
          variant="outlined"
          class="law-rel-col__search"
          :disabled="!activeDocumentId"
        />
        <div class="law-rel-col__list" role="listbox" aria-label="มาตรา / ข้อ">
          <template v-if="activeDocumentId">
            <div v-if="sectionsLoading" class="law-rel-col__empty">
              <v-progress-circular indeterminate color="primary" size="20" />
            </div>
            <template v-else>
              <button
                type="button"
                class="law-rel-col__item"
                :class="{ 'is-active': selectedSection === null && sectionTouched }"
                role="option"
                :aria-selected="selectedSection === null && sectionTouched"
                @click="selectWholeDocument"
              >
                <span class="law-rel-col__label">— ทั้งฉบับ —</span>
              </button>
              <button
                v-for="section in filteredCol3"
                :key="section.block_id"
                type="button"
                class="law-rel-col__item"
                :class="{ 'is-active': selectedSection?.block_id === section.block_id }"
                role="option"
                :aria-selected="selectedSection?.block_id === section.block_id"
                @click="selectSection(section)"
              >
                <span class="law-rel-col__label">{{ section.badge }}</span>
                <span v-if="section.preview" class="law-rel-col__preview">{{ section.preview }}</span>
              </button>
              <div v-if="!sectionsLoading && filteredCol3.length === 0" class="law-rel-col__empty">
                ไม่พบมาตรา/ข้อ
              </div>
            </template>
          </template>
          <div v-else class="law-rel-col__empty">เลือกกฎหมายก่อน</div>
        </div>
      </div>
    </div>

    <div v-if="selectionSummary" class="law-rel-picker__summary text-body-2">
      <v-icon icon="mdi-check-circle" size="18" color="primary" class="mr-1" />
      {{ selectionSummary }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { fetchReview, listDocuments } from '../../api/client';
import { buildSections } from '../../composables/useLawSections';
import {
  childDocuments,
  documentHasChildren,
  filterByQuery,
  rootDocuments,
} from '../../composables/useLawCatalog';
import type { DocumentListItem, LawCatalogSection, LawRelationTarget } from '../../types/document';

const props = defineProps<{
  excludeDocumentId?: string | null;
  modelValue?: LawRelationTarget | null;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: LawRelationTarget | null];
}>();

const loading = ref(true);
const documents = ref<DocumentListItem[]>([]);
const selectedRoot = ref<DocumentListItem | null>(null);
const selectedChild = ref<DocumentListItem | null>(null);
const rootWholeSelected = ref(false);
const selectedSection = ref<LawCatalogSection | null>(null);
const sectionTouched = ref(false);
const sections = ref<LawCatalogSection[]>([]);
const sectionsLoading = ref(false);
const sectionsCache = new Map<string, LawCatalogSection[]>();

const globalSearch = ref('');
const searchCol1 = ref('');
const searchCol2 = ref('');
const searchCol3 = ref('');

const filteredCol1 = computed(() => {
  const roots = rootDocuments(documents.value, props.excludeDocumentId);
  const query = globalSearch.value.trim() || searchCol1.value.trim();
  return filterByQuery(roots, query) as DocumentListItem[];
});

const filteredCol2 = computed(() => {
  if (!selectedRoot.value) return [];
  const children = childDocuments(documents.value, selectedRoot.value.document_id, props.excludeDocumentId);
  const query = globalSearch.value.trim() || searchCol2.value.trim();
  return filterByQuery(children, query) as DocumentListItem[];
});

const filteredCol3 = computed(() => {
  const query = globalSearch.value.trim() || searchCol3.value.trim();
  if (!query) return sections.value;
  const needle = query.toLowerCase();
  return sections.value.filter(
    (section) =>
      section.badge.toLowerCase().includes(needle)
      || section.preview.toLowerCase().includes(needle),
  );
});

const activeDocument = computed(() => {
  if (selectedChild.value) return selectedChild.value;
  if (selectedRoot.value && (rootWholeSelected.value || !hasChildren(selectedRoot.value.document_id))) {
    return selectedRoot.value;
  }
  return null;
});
const activeDocumentId = computed(() => activeDocument.value?.document_id ?? null);

const breadcrumbs = computed(() => {
  const crumbs: Array<{ key: string; label: string }> = [];
  if (selectedRoot.value) {
    crumbs.push({ key: selectedRoot.value.document_id, label: selectedRoot.value.title });
  }
  if (selectedChild.value) {
    crumbs.push({ key: selectedChild.value.document_id, label: selectedChild.value.title });
  }
  if (sectionTouched.value) {
    crumbs.push({
      key: selectedSection.value?.block_id ?? 'whole',
      label: selectedSection.value?.badge ?? 'ทั้งฉบับ',
    });
  }
  return crumbs;
});

const canGoBack = computed(() => Boolean(selectedRoot.value));

const selectionSummary = computed(() => {
  if (!activeDocument.value || !sectionTouched.value) return '';
  const parts = [activeDocument.value.title];
  if (selectedSection.value) parts.push(selectedSection.value.badge);
  else parts.push('ทั้งฉบับ');
  return parts.join(' › ');
});

function hasChildren(documentId: string): boolean {
  return documentHasChildren(documents.value, documentId);
}

async function loadDocuments(): Promise<void> {
  loading.value = true;
  try {
    const res = await listDocuments();
    documents.value = res.documents;
  } catch {
    documents.value = [];
  } finally {
    loading.value = false;
  }
}

async function loadSections(documentId: string): Promise<void> {
  if (sectionsCache.has(documentId)) {
    sections.value = sectionsCache.get(documentId) ?? [];
    return;
  }

  sectionsLoading.value = true;
  try {
    const review = await fetchReview(documentId);
    const built = buildSections(review).map((section) => ({
      block_id: section.id,
      badge: section.badge,
      preview: section.headBodyText.slice(0, 48),
    }));
    sectionsCache.set(documentId, built);
    sections.value = built;
  } catch {
    sections.value = [];
  } finally {
    sectionsLoading.value = false;
  }
}

function emitSelection(): void {
  if (!activeDocument.value || !sectionTouched.value) {
    emit('update:modelValue', null);
    return;
  }

  emit('update:modelValue', {
    document_id: activeDocument.value.document_id,
    title: activeDocument.value.title,
    section: selectedSection.value?.badge ?? null,
    block_id: selectedSection.value?.block_id ?? null,
  });
}

function selectRoot(doc: DocumentListItem): void {
  selectedRoot.value = doc;
  selectedChild.value = null;
  rootWholeSelected.value = false;
  selectedSection.value = null;
  sectionTouched.value = false;
  searchCol2.value = '';
  searchCol3.value = '';
  sections.value = [];

  if (!hasChildren(doc.document_id)) {
    void loadSections(doc.document_id);
  }

  emitSelection();
}

function selectRootWhole(): void {
  if (!selectedRoot.value) return;
  selectedChild.value = null;
  rootWholeSelected.value = true;
  selectedSection.value = null;
  sectionTouched.value = false;
  searchCol3.value = '';
  void loadSections(selectedRoot.value.document_id);
  emitSelection();
}

function selectChild(doc: DocumentListItem): void {
  selectedChild.value = doc;
  rootWholeSelected.value = false;
  selectedSection.value = null;
  sectionTouched.value = false;
  searchCol3.value = '';
  void loadSections(doc.document_id);
  emitSelection();
}

function selectSection(section: LawCatalogSection): void {
  selectedSection.value = section;
  sectionTouched.value = true;
  emitSelection();
}

function selectWholeDocument(): void {
  selectedSection.value = null;
  sectionTouched.value = true;
  emitSelection();
}

function goBack(): void {
  if (sectionTouched.value || selectedSection.value) {
    selectedSection.value = null;
    sectionTouched.value = false;
    emitSelection();
    return;
  }

  if (selectedChild.value || rootWholeSelected.value) {
    selectedChild.value = null;
    rootWholeSelected.value = false;
    selectedSection.value = null;
    sectionTouched.value = false;
    sections.value = [];
    emitSelection();
    return;
  }

  if (selectedRoot.value) {
    selectedRoot.value = null;
    selectedChild.value = null;
    rootWholeSelected.value = false;
    selectedSection.value = null;
    sectionTouched.value = false;
    sections.value = [];
    emitSelection();
  }
}

onMounted(() => {
  void loadDocuments();
});
</script>

<style scoped>
.law-rel-picker {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.law-rel-picker__toolbar {
  display: grid;
  grid-template-columns: auto 1fr minmax(140px, 220px);
  gap: 12px;
  align-items: center;
}

.law-rel-picker__crumbs {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.law-rel-picker__crumb + .law-rel-picker__crumb {
  margin-left: 0;
}

.law-rel-picker__sep {
  margin: 0 6px;
  opacity: 0.6;
}

.law-rel-picker__loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  min-height: 280px;
}

.law-rel-picker__columns {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  min-height: 320px;
}

.law-rel-col {
  display: flex;
  flex-direction: column;
  min-width: 0;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  overflow: hidden;
  background: rgb(var(--v-theme-surface));
}

.law-rel-col__head {
  padding: 10px 12px;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #fff;
  background: #1a3673;
}

.law-rel-col__search {
  margin: 8px;
}

.law-rel-col__list {
  flex: 1;
  overflow: auto;
  padding: 0 8px 8px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.law-rel-col__item {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 10px 12px;
  border: 1px solid rgba(var(--v-border-color), 0.5);
  border-radius: 6px;
  background: #fff;
  text-align: left;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
}

.law-rel-col__item:hover {
  border-color: rgba(26, 54, 115, 0.35);
  background: rgba(26, 54, 115, 0.04);
}

.law-rel-col__item.is-active {
  border-color: #1a3673;
  background: rgba(26, 54, 115, 0.1);
}

.law-rel-col__label {
  flex: 1;
  min-width: 0;
  font-size: 0.875rem;
  line-height: 1.35;
  word-break: break-word;
}

.law-rel-col__preview {
  flex: 1 1 100%;
  font-size: 0.75rem;
  color: rgba(var(--v-theme-on-surface), 0.55);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.law-rel-col__item:has(.law-rel-col__preview) {
  flex-wrap: wrap;
}

.law-rel-col__chev {
  flex-shrink: 0;
  color: #1a3673;
}

.law-rel-col__empty {
  padding: 24px 12px;
  text-align: center;
  font-size: 0.8125rem;
  color: rgba(var(--v-theme-on-surface), 0.55);
}

.law-rel-picker__summary {
  display: flex;
  align-items: center;
  padding: 10px 12px;
  border-radius: 8px;
  background: rgba(26, 54, 115, 0.06);
  color: #1a3673;
  font-weight: 600;
}

@media (max-width: 900px) {
  .law-rel-picker__toolbar {
    grid-template-columns: 1fr;
  }

  .law-rel-picker__columns {
    grid-template-columns: 1fr;
  }
}
</style>
