<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'ความสัมพันธ์กฎหมาย']"
    title="ความสัมพันธ์กฎหมาย"
    subtitle="จัดการความสัมพันธ์ระหว่างเอกสารที่เผยแพร่แล้ว"
  >
    <!-- Filter bar -->
    <div class="d-flex flex-wrap ga-3 mb-4 align-center">
      <v-text-field
        v-model="search"
        placeholder="ค้นหาชื่อกฎหมาย / หน่วยงาน / Document ID"
        prepend-inner-icon="mdi-magnify"
        variant="outlined"
        density="compact"
        hide-details
        style="max-width: 500px; flex: 1 1 300px"
      />
      <v-select
        v-model="filterStatus"
        :items="statusOptions"
        item-title="label"
        item-value="value"
        label="สถานะ"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        style="max-width: 160px"
      />
      <v-select
        v-model="sortOrder"
        :items="sortOptions"
        item-title="label"
        item-value="value"
        label="เรียงลำดับ"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        style="max-width: 160px"
      />
    </div>

    <!-- Main table (same style as /admin/laws) -->
    <v-card flat border rounded="lg">
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" />
      <v-table density="comfortable">
        <thead>
          <tr>
            <th>#</th>
            <th>ชื่อกฎหมาย / เอกสารสาระบบ</th>
            <th>ประเภท</th>
            <th>สถานะ</th>
            <th>ความสัมพันธ์</th>
            <th>แก้ไขล่าสุด</th>
            <th>จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && pagedDocs.length === 0">
            <td colspan="7" class="text-center pa-6 text-medium-emphasis">ไม่พบเอกสารที่ตรงกับเงื่อนไข</td>
          </tr>
          <tr v-for="(doc, idx) in pagedDocs" :key="doc.id" class="rh-row" @click="openQuickEdit(doc)">
            <td class="text-caption text-medium-emphasis">{{ (page - 1) * PAGE_SIZE + idx + 1 }}</td>
            <td class="py-3" style="max-width: 400px">
              <div class="d-flex align-center ga-2 flex-wrap mb-1">
                <span class="text-body-2 font-weight-bold">{{ doc.title }}</span>
                <v-chip v-if="doc.hasChildren" size="x-small" color="deep-purple" variant="tonal" rounded="pill">
                  <v-icon start icon="mdi-link-variant" size="11" />
                  กฎหมายที่อ้างถึง
                </v-chip>
              </div>
              <div class="d-flex flex-wrap ga-3 text-caption text-medium-emphasis">
                <span v-if="doc.org"><v-icon size="11" icon="mdi-domain" /> {{ doc.org }}</span>
                <span v-if="doc.group"><v-icon size="11" icon="mdi-tag" /> {{ doc.group }}</span>
                <span v-if="doc.pages > 0 || doc.sections != null">
                  <v-icon size="11" icon="mdi-file-multiple" />
                  {{ doc.pages }} หน้า<template v-if="doc.sections != null"> / {{ doc.sections }} ข้อ</template>
                </span>
              </div>
            </td>
            <td>
              <v-chip v-if="doc.lawType" size="small" variant="flat" :color="typeColor(doc.lawType)" rounded="pill" class="font-weight-bold text-white">
                {{ doc.lawType }}
              </v-chip>
            </td>
            <td>
              <v-chip
                size="x-small"
                :color="doc.metaStatus ? metaStatusColor(doc.metaStatus) : workflowStageColor(doc.workflowStage)"
                variant="tonal"
                rounded="pill"
              >
                <v-icon start icon="mdi-circle" size="8" />
                {{ doc.metaStatus || doc.workflowStage }}
              </v-chip>
            </td>
            <td>
              <v-chip
                v-if="changeLogCountById[doc.id]"
                size="x-small"
                color="admin-primary"
                variant="tonal"
                rounded="pill"
              >
                <v-icon start icon="mdi-history" size="10" />
                {{ changeLogCountById[doc.id] }} เปลี่ยนแปลง
              </v-chip>
              <span v-else class="text-caption text-medium-emphasis">—</span>
            </td>
            <td class="text-caption">{{ doc.editedAt }}</td>
            <td @click.stop>
              <div class="d-flex ga-1">
                <v-btn
                  icon="mdi-graph-outline"
                  size="x-small"
                  variant="tonal"
                  color="admin-primary"
                  title="จัดการความสัมพันธ์"
                  :to="`/documents/${doc.id}/relations`"
                />
                <v-btn
                  icon="mdi-eye-outline"
                  size="x-small"
                  variant="text"
                  color="grey"
                  title="ดูเอกสาร"
                  :to="`/law/${doc.id}`"
                />
              </div>
            </td>
          </tr>
        </tbody>
      </v-table>

      <v-divider />
      <div class="d-flex justify-space-between align-center pa-3">
        <span class="text-caption text-medium-emphasis">
          กำลังแสดงผล {{ rangeStart }} - {{ rangeEnd }} จากทั้งหมด {{ filteredDocs.length.toLocaleString('th-TH') }} รายการ
        </span>
        <v-pagination
          v-if="pageCount > 1"
          v-model="page"
          :length="pageCount"
          :total-visible="5"
          rounded="circle"
          density="compact"
        />
      </div>
    </v-card>

    <!-- Change log -->
    <v-expansion-panels v-if="changeLog.length" class="mt-4" variant="accordion">
      <v-expansion-panel rounded="lg" elevation="0" style="border: 1px solid #e5e7eb">
        <v-expansion-panel-title class="text-body-2 font-weight-bold">
          <v-icon icon="mdi-history" size="16" class="mr-2" color="admin-primary" />
          ประวัติการเปลี่ยนแปลงในเซสชันนี้
          <v-chip size="x-small" color="admin-primary" variant="tonal" rounded="pill" class="ml-2">
            {{ changeLog.length }}
          </v-chip>
        </v-expansion-panel-title>
        <v-expansion-panel-text class="pa-0">
          <v-table density="compact" class="rh-log-table">
            <thead>
              <tr>
                <th>เวลา</th>
                <th>เอกสาร</th>
                <th>การกระทำ</th>
                <th>ประเภทความสัมพันธ์</th>
                <th>เป้าหมาย</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in changeLog" :key="entry.id">
                <td class="text-caption">{{ formatDateTime(entry.timestamp) }}</td>
                <td class="text-caption text-truncate" style="max-width:160px">{{ entry.docTitle }}</td>
                <td>
                  <v-chip size="x-small" :color="entry.action === 'add' ? 'success' : 'error'" variant="tonal" rounded="pill">
                    {{ entry.action === 'add' ? '+ เพิ่ม' : '− ลบ' }}
                  </v-chip>
                </td>
                <td>
                  <v-chip size="x-small" :color="RELATION_TYPE_COLORS[entry.relationType]" variant="tonal" rounded="pill">
                    {{ relationTypeLabel(entry.relationType) }}
                  </v-chip>
                </td>
                <td class="text-caption text-truncate" style="max-width:200px">{{ entry.relationTarget }}</td>
              </tr>
            </tbody>
          </v-table>
          <div class="d-flex justify-end pa-3 border-t">
            <v-btn size="small" color="error" variant="text" class="text-none" @click="changeLog = []">
              <v-icon start icon="mdi-delete-outline" size="14" />
              ล้างประวัติ
            </v-btn>
          </div>
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>

    <!-- Quick-edit dialog -->
    <v-dialog v-model="quickEditOpen" max-width="700" scrollable>
      <v-card rounded="xl">
        <!-- Dialog header -->
        <div class="d-flex align-center ga-3 px-6 pt-5 pb-3">
          <v-icon icon="mdi-graph-outline" color="admin-primary" size="22" />
          <div class="flex-1 min-width-0">
            <div class="text-subtitle-1 font-weight-bold text-truncate">
              {{ documentStore.review?.law_meta?.title || quickEditTitle }}
            </div>
            <div class="text-caption text-medium-emphasis">{{ quickEditId }}</div>
          </div>
          <v-btn
            size="small"
            variant="tonal"
            color="admin-primary"
            :to="`/documents/${quickEditId}/relations`"
            prepend-icon="mdi-open-in-new"
            class="text-none flex-shrink-0"
          >
            เปิดหน้าเต็ม
          </v-btn>
          <v-btn icon size="small" variant="text" @click="quickEditOpen = false">
            <v-icon icon="mdi-close" size="18" />
          </v-btn>
        </div>
        <v-divider />

        <v-card-text class="px-6 py-4">
          <div v-if="documentStore.loading" class="d-flex justify-center pa-10">
            <v-progress-circular indeterminate color="admin-primary" />
          </div>

          <template v-else>
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

            <!-- Document-level relations -->
            <div class="rh-section-title mb-3">
              <v-icon icon="mdi-link-variant" color="admin-primary" size="16" />
              ความสัมพันธ์ระดับเอกสาร
              <v-spacer />
              <v-btn size="x-small" variant="outlined" prepend-icon="mdi-plus" :disabled="documentStore.saving" @click="openDocumentRelation()">
                เพิ่ม
              </v-btn>
            </div>
            <div v-if="documentLevelRelations.length" class="rh-rel-list mb-4">
              <div v-for="rel in documentLevelRelations" :key="rel.id" class="rh-rel-row">
                <v-chip size="x-small" :color="RELATION_TYPE_COLORS[rel.type]" variant="tonal" :prepend-icon="RELATION_TYPE_ICONS[rel.type]" class="flex-shrink-0">
                  {{ relationTypeLabel(rel.type) }}
                </v-chip>
                <span class="text-body-2 text-truncate flex-1">{{ formatRelationTarget(rel) }}</span>
                <v-btn icon variant="text" size="x-small" color="error" :disabled="documentStore.saving" @click="removeRelation(rel)">
                  <v-icon icon="mdi-delete-outline" size="15" />
                </v-btn>
              </div>
            </div>
            <div v-else class="text-body-2 text-medium-emphasis mb-4">ยังไม่มีความสัมพันธ์ระดับเอกสาร</div>

            <!-- Section-level relations -->
            <div class="rh-section-title mb-3">
              <v-icon icon="mdi-vector-link" color="admin-primary" size="16" />
              ความสัมพันธ์ระดับข้อ
            </div>
            <div v-if="sections.length === 0" class="text-body-2 text-medium-emphasis">ไม่พบข้อในเอกสาร</div>
            <div v-else class="d-flex flex-column ga-3">
              <div v-for="entry in sectionRelationEntries" :key="entry.section.id" class="rh-section-block">
                <div class="d-flex align-center ga-2 mb-2">
                  <v-chip size="small" variant="tonal" color="admin-primary">{{ entry.section.badge }}</v-chip>
                  <v-spacer />
                  <v-btn size="x-small" variant="tonal" color="admin-primary" prepend-icon="mdi-plus" :disabled="documentStore.saving" @click="openSectionRelation(entry.section)">
                    เพิ่ม
                  </v-btn>
                </div>
                <div v-if="entry.relations.length" class="rh-rel-list">
                  <div v-for="rel in entry.relations" :key="rel.id" class="rh-rel-row">
                    <v-chip size="x-small" :color="RELATION_TYPE_COLORS[rel.type]" variant="tonal" :prepend-icon="RELATION_TYPE_ICONS[rel.type]" class="flex-shrink-0">
                      {{ relationTypeLabel(rel.type) }}
                    </v-chip>
                    <span class="text-body-2 text-truncate flex-1">{{ formatRelationTarget(rel) }}</span>
                    <v-btn icon variant="text" size="x-small" color="error" :disabled="documentStore.saving" @click="removeRelation(rel)">
                      <v-icon icon="mdi-delete-outline" size="15" />
                    </v-btn>
                  </div>
                </div>
                <div v-else class="text-caption text-medium-emphasis">ยังไม่มีความสัมพันธ์</div>
              </div>
            </div>
          </template>
        </v-card-text>

        <v-divider />
        <v-card-actions class="pa-4 justify-end ga-2">
          <v-btn variant="text" class="text-none" :disabled="documentStore.saving" @click="quickEditOpen = false">
            ปิด
          </v-btn>
          <v-btn
            color="admin-primary"
            variant="flat"
            class="text-none"
            :loading="documentStore.saving"
            prepend-icon="mdi-content-save-outline"
            @click="openConfirmSave"
          >
            บันทึกความสัมพันธ์
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Confirm save dialog -->
    <v-dialog v-model="confirmSaveOpen" max-width="420" persistent>
      <v-card rounded="xl" class="pa-6">
        <div class="d-flex align-center ga-3 mb-4">
          <v-icon icon="mdi-content-save-check-outline" color="admin-primary" size="28" />
          <div>
            <div class="text-subtitle-1 font-weight-bold">ยืนยันการบันทึก</div>
            <div class="text-body-2 text-medium-emphasis">
              บันทึกความสัมพันธ์ทั้งหมดสำหรับเอกสารนี้?
            </div>
          </div>
        </div>
        <div class="rh-confirm-box mb-5">
          <div class="text-body-2 font-weight-bold text-truncate">{{ documentStore.review?.law_meta?.title || quickEditTitle }}</div>
          <div class="text-caption text-medium-emphasis mt-1">
            {{ documentLevelRelations.length }} ความสัมพันธ์ระดับเอกสาร ·
            {{ sectionRelationEntries.filter(e => e.relations.length).length }} ข้อที่มีความสัมพันธ์
          </div>
        </div>
        <div class="d-flex justify-end ga-2">
          <v-btn variant="text" class="text-none" @click="confirmSaveOpen = false">ยกเลิก</v-btn>
          <v-btn color="admin-primary" variant="flat" class="text-none" @click="confirmSave">
            ยืนยันบันทึก
          </v-btn>
        </div>
      </v-card>
    </v-dialog>

    <!-- Add relation dialog -->
    <AddRelationDialog
      v-if="relationDialog.open"
      :scope="relationDialog.scope"
      :block-id="relationDialog.blockId"
      :default-type="relationDialog.defaultType"
      :exclude-document-id="quickEditId ?? ''"
      :parent-document-ids="quickEditParentIds"
      :existing-relations="relations"
      :section-labels="sectionLabels"
      @close="closeRelationDialog"
      @save="onRelationAdd"
    />
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { fetchReportSummary } from '../../api/client';
import type { ReportSummary, LawRelation, RelationScope, RelationType } from '../../types/document';
import { useDocumentStore } from '../../stores/documentStore';
import { useSnackbarStore } from '../../stores/snackbarStore';
import { buildSections, documentRelations, relationsForSection, type LawSection } from '../../composables/useLawSections';
import {
  RELATION_TYPE_COLORS,
  RELATION_TYPE_ICONS,
  formatRelationTarget,
  relationTypeLabel,
} from '../../types/lawRelation';
import { createClientId } from '../../utils/createClientId';
import { formatThaiDate, formatThaiDateTime } from '../../utils/thaiDate';
import { parentIdsOf } from '../../composables/useLawCatalog';
import AppShell from '../../components/shared/AppShell.vue';
import AddRelationDialog from '../../components/shared/AddRelationDialog.vue';

// ── Types ─────────────────────────────────────────────────
interface DocRow {
  id: string;
  title: string;
  lawType: string;
  metaStatus: string;
  workflowStage: string;
  hasChildren: boolean;
  org: string;
  group: string;
  pages: number;
  sections: number | null;
  editedAt: string;
  rawDate: string;
}

interface ChangeLogEntry {
  id: string;
  timestamp: Date;
  action: 'add' | 'remove';
  docId: string;
  docTitle: string;
  relationType: RelationType;
  relationTarget: string;
}

// ── Stores ────────────────────────────────────────────────
const documentStore = useDocumentStore();
const snackbar = useSnackbarStore();

// ── Table state ───────────────────────────────────────────
const PAGE_SIZE = 20;
const summary = ref<ReportSummary>({
  totals: { all: 0, published: 0, processing: 0, failed: 0, esign: 0, relations: 0, legacy_links: 0 },
  by_type: [], by_group: [], by_agency: [], by_year: [], documents: [],
});
const loading = ref(false);
const search = ref('');
const filterStatus = ref<string | null>(null);
const sortOrder = ref('newest');
const page = ref(1);

// ── Quick-edit dialog ─────────────────────────────────────
const quickEditOpen = ref(false);
const quickEditId = ref<string | null>(null);
const quickEditTitle = ref('');
const confirmSaveOpen = ref(false);

// ── Pending relations (staged before confirm-save) ────────
const pendingRelations = ref<LawRelation[]>([]);
const quickEditParentIds = computed(() => {
  const meta = documentStore.review?.law_meta;
  if (!meta) return [];
  return parentIdsOf(meta);
});

// ── Change log ────────────────────────────────────────────
const changeLog = ref<ChangeLogEntry[]>([]);

// ── Relation dialog ───────────────────────────────────────
const relationDialog = ref<{
  open: boolean;
  scope: RelationScope;
  blockId: string | null;
  defaultType?: RelationType;
}>({ open: false, scope: 'document', blockId: null });

// ── Options ───────────────────────────────────────────────
const statusOptions = [
  { label: 'ทุกสถานะ', value: null },
  { label: 'เผยแพร่', value: 'เผยแพร่' },
  { label: 'พร้อมเผยแพร่', value: 'พร้อมเผยแพร่' },
  { label: 'รอส่ง eSign', value: 'รอส่ง eSign' },
  { label: 'ดำเนินการ', value: 'ดำเนินการ' },
];

const sortOptions = [
  { label: 'ล่าสุด', value: 'newest' },
  { label: 'เก่าสุด', value: 'oldest' },
  { label: 'ชื่อ ก-ฮ', value: 'name' },
];

// ── Type colors (same as AdminLawListPage) ─────────────────
const TYPE_META: Record<string, { color: string }> = {
  กฎหมายภายนอก: { color: 'doc-phaainok' },
  ข้อบังคับ: { color: 'doc-kho-bangkhab' },
  ระเบียบ: { color: 'doc-rabiap' },
  ประกาศ: { color: 'doc-prakat' },
  ประกาศที่ออกโดยมหาวิทยาลัย: { color: 'doc-prakat' },
  ประกาศที่ออกโดยสภามหาวิทยาลัย: { color: 'doc-prakat' },
};

// ── Derived table rows ─────────────────────────────────────
const childCountMap = computed<Record<string, number>>(() => {
  const map: Record<string, number> = {};
  for (const doc of summary.value.documents) {
    for (const parentId of parentIdsOf(doc)) {
      map[parentId] = (map[parentId] ?? 0) + 1;
    }
  }
  return map;
});

function workflowStageLabel(doc: { status: string; meta_status: string; workflow_completed_step: number | null }): string {
  if (doc.meta_status === 'ยกเลิก') return 'ยกเลิก';
  const step = doc.workflow_completed_step ?? 0;
  if (doc.status === 'exported' || doc.status === 'ingested') return 'เผยแพร่';
  if (step >= 6) return 'พร้อมเผยแพร่';
  if (step >= 5) return 'รอส่ง eSign';
  if (step >= 4) return 'รอการเชื่อมโยงความสัมพันธ์';
  if (doc.status === 'done') return 'ดำเนินการ';
  return 'กำลังประมวลผล';
}

const publishedDocs = computed(() =>
  summary.value.documents.filter((d) => (d.workflow_completed_step ?? 0) >= 4),
);

const docs = computed<DocRow[]>(() =>
  publishedDocs.value.map((doc) => ({
    id: doc.id,
    title: doc.title,
    lawType: doc.type !== 'ไม่ระบุ' ? doc.type : '',
    metaStatus: doc.meta_status ?? '',
    workflowStage: workflowStageLabel(doc),
    hasChildren: (childCountMap.value[doc.id] ?? 0) > 0,
    org: doc.agency !== 'ไม่ระบุ' ? doc.agency : '',
    group: doc.group !== 'ไม่ระบุ' ? doc.group : '',
    pages: doc.page_count ?? 0,
    sections: doc.section_count ?? null,
    editedAt: formatThaiDate(doc.date) || '-',
    rawDate: doc.date ?? '',
  })),
);

const filteredDocs = computed(() => {
  let r = docs.value;
  if (filterStatus.value) r = r.filter((d) => d.workflowStage === filterStatus.value);
  if (search.value.trim()) {
    const q = search.value.trim().toLowerCase();
    r = r.filter(
      (d) =>
        d.title.toLowerCase().includes(q) ||
        d.org.toLowerCase().includes(q) ||
        d.group.toLowerCase().includes(q),
    );
  }
  if (sortOrder.value === 'oldest') return [...r].sort((a, b) => a.rawDate.localeCompare(b.rawDate));
  if (sortOrder.value === 'name') return [...r].sort((a, b) => a.title.localeCompare(b.title, 'th'));
  return [...r].sort((a, b) => b.rawDate.localeCompare(a.rawDate));
});

const pageCount = computed(() => Math.ceil(filteredDocs.value.length / PAGE_SIZE));
const pagedDocs = computed(() => filteredDocs.value.slice((page.value - 1) * PAGE_SIZE, page.value * PAGE_SIZE));
const rangeStart = computed(() => (filteredDocs.value.length === 0 ? 0 : (page.value - 1) * PAGE_SIZE + 1));
const rangeEnd = computed(() => Math.min(page.value * PAGE_SIZE, filteredDocs.value.length));

watch([search, filterStatus, sortOrder], () => { page.value = 1; });

// ── Relations computed (from staged pendingRelations) ──────
const relations = computed<LawRelation[]>(() => pendingRelations.value);
const sections = computed(() => buildSections(documentStore.review));
const sectionLabels = computed<Record<string, string>>(() =>
  Object.fromEntries(sections.value.map((s) => [s.id, s.badge])),
);
const documentLevelRelations = computed(() => documentRelations(relations.value));
const sectionRelationEntries = computed(() =>
  sections.value.map((section) => ({
    section,
    relations: relationsForSection(relations.value, section.id),
  })),
);

// ── Change log helpers ────────────────────────────────────
const changeLogCountById = computed<Record<string, number>>(() => {
  const map: Record<string, number> = {};
  for (const e of changeLog.value) {
    map[e.docId] = (map[e.docId] ?? 0) + 1;
  }
  return map;
});

function logChange(action: 'add' | 'remove', relation: LawRelation): void {
  changeLog.value.unshift({
    id: createClientId('change'),
    timestamp: new Date(),
    action,
    docId: quickEditId.value ?? '',
    docTitle: documentStore.review?.law_meta?.title || quickEditTitle.value,
    relationType: relation.type,
    relationTarget: formatRelationTarget(relation),
  });
}

function formatDateTime(date: Date): string {
  return formatThaiDateTime(date) || '-';
}

// ── Table helpers ─────────────────────────────────────────
function typeColor(type: string): string {
  return TYPE_META[type]?.color ?? (type.includes('ประกาศ') ? 'doc-prakat' : 'grey');
}

function workflowStageColor(stage: string): string {
  if (stage === 'เผยแพร่') return 'success';
  if (stage === 'พร้อมเผยแพร่') return 'admin-primary';
  if (stage === 'รอส่ง eSign') return 'deep-purple';
  if (stage === 'รอการเชื่อมโยงความสัมพันธ์') return 'orange';
  if (stage === 'ยกเลิก') return 'error';
  return 'teal';
}

function metaStatusColor(status: string): string {
  if (status === 'active' || status === 'มีผลบังคับใช้' || status === 'มีผลใช้บังคับ' || status === 'ใช้บังคับ' || status === 'บังคับใช้') return 'success';
  if (status === 'ยกเลิก' || status === 'ถูกยกเลิก') return 'error';
  if (status === 'พักใช้' || status === 'ระงับใช้') return 'warning';
  return 'grey';
}

// ── Quick-edit open/close ─────────────────────────────────
async function openQuickEdit(doc: DocRow): Promise<void> {
  quickEditId.value = doc.id;
  quickEditTitle.value = doc.title;
  quickEditOpen.value = true;
  documentStore.reset();
  await documentStore.fetch(doc.id);
  // Seed pending relations from the loaded document
  pendingRelations.value = [...(documentStore.review?.relations ?? [])];
}

// ── Relation dialog ───────────────────────────────────────
function openDocumentRelation(defaultType?: RelationType): void {
  relationDialog.value = { open: true, scope: 'document', blockId: null, defaultType };
}

function openSectionRelation(section: LawSection, defaultType?: RelationType): void {
  relationDialog.value = { open: true, scope: 'section', blockId: section.id, defaultType };
}

function closeRelationDialog(): void {
  relationDialog.value.open = false;
}

// Add to staging (not saved yet)
function onRelationAdd(relation: LawRelation): void {
  closeRelationDialog();
  pendingRelations.value = [...pendingRelations.value, relation];
  logChange('add', relation);
}

// Remove from staging (not saved yet)
function removeRelation(rel: LawRelation): void {
  pendingRelations.value = pendingRelations.value.filter((r) => r.id !== rel.id);
  logChange('remove', rel);
}

// ── Save with confirm ─────────────────────────────────────
function openConfirmSave(): void {
  confirmSaveOpen.value = true;
}

async function confirmSave(): Promise<void> {
  confirmSaveOpen.value = false;
  const ok = await documentStore.saveRelations(pendingRelations.value);
  if (ok) {
    snackbar.success('บันทึกความสัมพันธ์แล้ว');
    quickEditOpen.value = false;
  }
}

// ── Data load ─────────────────────────────────────────────
onMounted(async () => {
  loading.value = true;
  try {
    summary.value = await fetchReportSummary();
  } finally {
    loading.value = false;
  }
});

onBeforeUnmount(() => documentStore.reset());
</script>

<style scoped>
/* ── Table rows ───────────────────────────────────────────── */
.rh-row {
  cursor: pointer;
  transition: background 0.1s;
}

.rh-row:hover {
  background: rgba(var(--v-theme-admin-primary), 0.04);
}

/* ── Relation rows in dialog ──────────────────────────────── */
.rh-rel-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.rh-rel-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}

/* ── Dialog section titles ────────────────────────────────── */
.rh-section-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
}

/* ── Section block ────────────────────────────────────────── */
.rh-section-block {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 12px 14px;
}

/* ── Confirm box ──────────────────────────────────────────── */
.rh-confirm-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 12px 16px;
}

/* ── Log table ────────────────────────────────────────────── */
.rh-log-table :deep(thead th) {
  font-size: 0.72rem !important;
  font-weight: 700 !important;
  color: rgba(var(--v-theme-secondary), 0.55) !important;
}
</style>
