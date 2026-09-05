<template>
  <div class="psr">
    <ELawNavbar @go-admin="router.push('/admin')" />

    <div class="psr-topbar">
      <v-container style="max-width:1200px" class="py-0">
        <div class="d-flex align-center ga-2 py-3">
          <v-btn
            variant="text"
            size="small"
            prepend-icon="mdi-arrow-left"
            class="text-none"
            @click="selectedRow ? goList() : router.push('/database')"
          >
            ย้อนกลับ
          </v-btn>
          <span class="text-body-2 text-medium-emphasis">ดูโครงสร้างความสัมพันธ์</span>
        </div>
      </v-container>
    </div>

    <v-container style="max-width:1200px" class="py-6">
    <div v-if="loading" class="d-flex align-center justify-center pa-12">
      <v-progress-circular indeterminate color="primary" />
    </div>

    <template v-else-if="!selectedId">
      <div class="d-flex flex-wrap ga-3 mb-4 align-center">
        <v-text-field
          v-model="listSearch"
          placeholder="ค้นหาชื่อกฎหมาย / หน่วยงาน / หมวดหมู่"
          prepend-inner-icon="mdi-magnify"
          variant="outlined"
          density="compact"
          hide-details
          style="max-width: 500px; flex: 1 1 300px"
        />
        <v-select
          v-model="listStatus"
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
          v-model="listSort"
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
        <v-select
          v-model="listType"
          :items="typeOptions"
          item-title="label"
          item-value="value"
          label="ประเภทกฎหมาย"
          variant="outlined"
          density="compact"
          hide-details
          rounded="lg"
          style="max-width: 180px"
        />
      </div>

      <v-card flat border rounded="lg">
        <div class="px-4 pt-4 pb-2 text-subtitle-2 font-weight-bold">กฎหมายที่ดูล่าสุด</div>
        <v-table density="comfortable">
          <thead>
            <tr>
              <th>#</th>
              <th>ชื่อกฎหมาย / เอกสารสาระบบ</th>
              <th>ประเภท</th>
              <th>สถานะ</th>
              <th>แก้ไขล่าสุด</th>
              <th>ดูข้อมูล</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="pagedList.length === 0">
              <td colspan="6" class="text-center pa-6 text-medium-emphasis">ไม่พบกฎหมายที่ตรงกับเงื่อนไข</td>
            </tr>
            <tr v-for="(law, idx) in pagedList" :key="law.id">
              <td class="text-caption text-medium-emphasis">{{ (listPage - 1) * PAGE_SIZE + idx + 1 }}</td>
              <td class="py-3" style="max-width: 420px">
                <div class="d-flex align-center ga-2 flex-wrap mb-1">
                  <span class="text-body-2 font-weight-bold">{{ law.title }}</span>
                  <v-chip v-if="law.isParent" size="x-small" color="deep-purple" variant="tonal" rounded="pill">
                    <v-icon start icon="mdi-link-variant" size="11" />
                    กฎหมายที่อ้างถึง
                  </v-chip>
                </div>
                <div v-if="law.childCount" class="mb-1">
                  <v-chip size="x-small" color="teal" variant="tonal" rounded="pill">
                    <v-icon start icon="mdi-sitemap" size="10" />
                    มีกฎหมายลูก {{ law.childCount }} ฉบับ
                  </v-chip>
                </div>
                <div class="d-flex flex-wrap ga-3 text-caption text-medium-emphasis">
                  <span v-if="law.org"><v-icon size="11" icon="mdi-domain" /> {{ law.org }}</span>
                  <span v-if="law.group"><v-icon size="11" icon="mdi-tag" /> {{ law.group }}</span>
                  <span v-if="law.pages > 0 || law.sections != null">
                    <v-icon size="11" icon="mdi-file-multiple" />
                    {{ law.pages }} หน้า<template v-if="law.sections != null"> / {{ law.sections }} ข้อ</template>
                  </span>
                </div>
              </td>
              <td>
                <v-chip
                  v-if="law.lawType"
                  size="small"
                  variant="flat"
                  :color="typeColor(law.lawType)"
                  rounded="pill"
                  class="font-weight-bold text-white"
                >{{ law.typeShort }}</v-chip>
              </td>
              <td>
                <v-chip
                  size="x-small"
                  :color="law.metaStatus ? metaStatusColor(law.metaStatus) : workflowStageColor(law.workflowStage)"
                  variant="tonal"
                  rounded="pill"
                >
                  <v-icon start icon="mdi-circle" size="8" />
                  {{ law.metaStatus || law.workflowStage }}
                </v-chip>
              </td>
              <td class="text-caption">{{ law.editedAt }}</td>
              <td>
                <v-btn
                  variant="text"
                  color="primary"
                  size="small"
                  class="text-none"
                  append-icon="mdi-arrow-right"
                  @click="openDetail(law.id)"
                >ดูความสัมพันธ์</v-btn>
              </td>
            </tr>
          </tbody>
        </v-table>
        <v-divider />
        <div class="d-flex justify-space-between align-center pa-3">
          <span class="text-caption text-medium-emphasis">
            กำลังแสดงผล {{ listRangeStart.toLocaleString('th-TH') }} - {{ listRangeEnd.toLocaleString('th-TH') }}
            จากทั้งหมด {{ filteredList.length.toLocaleString('th-TH') }} รายการ
          </span>
          <v-pagination
            v-if="listPageCount > 1"
            v-model="listPage"
            :length="listPageCount"
            :total-visible="5"
            rounded="circle"
            density="compact"
          />
        </div>
      </v-card>
    </template>

    <template v-else-if="selectedRow">
      <v-card flat border rounded="xl" class="rel-root pa-6 mb-4">
        <div class="d-flex align-center ga-2 mb-2">
          <v-icon icon="mdi-scale-balance" size="20" color="primary" />
          <span class="text-caption font-weight-bold text-medium-emphasis">กฎหมายหลัก</span>
        </div>
        <div class="rel-root__title mb-3">{{ selectedRow.title }}</div>
        <div class="d-flex flex-wrap ga-2 mb-4">
          <DocBadge v-if="lawTypeBadge" :type="lawTypeBadge" />
          <v-chip v-if="selectedRow.isParent" size="small" variant="tonal" color="grey" rounded="lg">
            กฎหมายแม่
          </v-chip>
          <v-chip
            size="small"
            :color="selectedRow.metaStatus ? metaStatusColor(selectedRow.metaStatus) : workflowStageColor(selectedRow.workflowStage)"
            variant="tonal"
            rounded="lg"
          >
            <v-icon start icon="mdi-circle" size="8" />
            {{ selectedRow.metaStatus || selectedRow.workflowStage }}
          </v-chip>
          <v-spacer />
          <v-btn
            size="small"
            variant="outlined"
            prepend-icon="mdi-download"
            class="text-none"
            :loading="downloadLoading === selectedRow.id"
            @click="downloadSingle"
          >
            ดาวน์โหลด PDF
          </v-btn>
          <v-btn
            size="small"
            variant="outlined"
            prepend-icon="mdi-download-multiple"
            class="text-none"
            :loading="downloadAllLoading"
            @click="downloadAll"
          >
            ดาวน์โหลดทั้งหมด
          </v-btn>
          <v-btn size="small" variant="text" class="text-none" prepend-icon="mdi-swap-horizontal" @click="pickerOpen = true">
            เปลี่ยนกฎหมาย
          </v-btn>
        </div>
        <div class="rel-root__meta">
          <div>
            <div class="rel-root__meta-label"><v-icon icon="mdi-calendar-outline" size="14" /> วันที่ประกาศ</div>
            <div class="rel-root__meta-value">{{ displayLawDate(rootMeta?.promulgation_date) }}</div>
          </div>
          <div>
            <div class="rel-root__meta-label"><v-icon icon="mdi-calendar-check-outline" size="14" /> วันที่มีผลใช้บังคับ</div>
            <div class="rel-root__meta-value">{{ displayLawDate(rootMeta?.effective_date) }}</div>
          </div>
          <div>
            <div class="rel-root__meta-label"><v-icon icon="mdi-office-building-outline" size="14" /> หน่วยงาน</div>
            <div class="rel-root__meta-value">{{ selectedRow.org || '—' }}</div>
          </div>
          <div v-if="rootMeta?.law_group">
            <div class="rel-root__meta-label"><v-icon icon="mdi-folder-outline" size="14" /> กลุ่มกฎหมาย</div>
            <div class="rel-root__meta-value">{{ rootMeta.law_group }}</div>
          </div>
        </div>
      </v-card>

      <v-card flat border rounded="xl" class="pa-6 mb-4">
        <div class="text-subtitle-1 font-weight-bold mb-4">โครงสร้างความสัมพันธ์ (กฎหมายลำดับรอง)</div>
        <div class="rel-stats mb-5">
          <div v-for="stat in stats" :key="stat.label" class="rel-stat">
            <div class="rel-stat__label">
              <v-icon v-if="stat.icon" :icon="stat.icon" size="16" class="mr-1" />
              {{ stat.label }}
            </div>
            <div class="rel-stat__value" :class="stat.tone">{{ stat.value }}</div>
          </div>
        </div>

        <div class="d-flex flex-wrap align-center ga-2 mb-4">
          <span class="text-caption font-weight-bold text-medium-emphasis mr-1">ประเภทความสัมพันธ์</span>
          <v-chip
            v-for="filter in RELATION_FILTERS"
            :key="filter.value"
            size="small"
            :variant="typeFilters.includes(filter.value) ? 'flat' : 'outlined'"
            :color="typeFilters.includes(filter.value) ? 'primary' : 'default'"
            class="rel-filter-chip"
            @click="toggleTypeFilter(filter.value)"
          >{{ filter.label }}</v-chip>
          <v-spacer />
          <v-btn-toggle v-model="viewMode" mandatory density="compact" color="primary" rounded="lg" divided>
            <v-btn value="hierarchy" class="text-none px-3" size="small" prepend-icon="mdi-graph-outline">Hierarchy</v-btn>
            <v-btn value="tree" class="text-none px-3" size="small" prepend-icon="mdi-file-tree-outline">Tree</v-btn>
          </v-btn-toggle>
        </div>

        <div class="d-flex flex-wrap ga-3 mb-5 align-center">
          <v-text-field
            v-model="treeSearch"
            placeholder="ค้นหาชื่อกฎหมาย / หน่วยงาน / หมวดหมู่"
            prepend-inner-icon="mdi-magnify"
            variant="outlined"
            density="compact"
            hide-details
            rounded="lg"
            style="max-width: 500px; flex: 1 1 300px"
          />
          <v-select
            v-model="treeStatus"
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
            v-model="treeSort"
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
          <v-select
            v-model="treeType"
            :items="typeOptions"
            item-title="label"
            item-value="value"
            label="ประเภทกฎหมาย"
            variant="outlined"
            density="compact"
            hide-details
            rounded="lg"
            style="max-width: 180px"
          />
        </div>

        <div v-if="detailLoading" class="d-flex justify-center pa-8">
          <v-progress-circular indeterminate color="primary" />
        </div>
        <template v-else>
          <div v-if="viewMode === 'tree'" class="rel-tree-wrap">
            <RelationTreeView
              v-if="pagedRootNode"
              :node="pagedRootNode"
              :current-id="selectedId"
              theme-color="primary"
              @select="openDetail"
            />
            <div v-else class="text-body-2 text-medium-emphasis text-center pa-8">
              ไม่พบกฎหมายลำดับรองภายใต้กฎหมายแม่ที่เลือก
            </div>
          </div>
          <div v-else class="hierarchy-wrap pa-2">
            <div v-if="!pagedRootNode && !filteredRootNode" class="text-body-2 text-medium-emphasis text-center pa-8">
              ไม่พบกฎหมายลำดับรองภายใต้กฎหมายแม่ที่เลือก
            </div>
            <HierarchyList v-if="pagedRootNode || filteredRootNode" :node="(pagedRootNode ?? filteredRootNode)!" theme-color="primary" />
          </div>
        </template>

        <v-divider class="mt-4" />
        <div class="d-flex justify-space-between align-center pa-3 px-0">
          <span class="text-caption text-medium-emphasis">
            กำลังแสดงผล {{ treeRangeStart.toLocaleString('th-TH') }} - {{ treeRangeEnd.toLocaleString('th-TH') }}
            จากทั้งหมด {{ descendantCount.toLocaleString('th-TH') }} รายการ
          </span>
          <v-pagination
            v-if="treePageCount > 1"
            v-model="treePage"
            :length="treePageCount"
            :total-visible="5"
            rounded="circle"
            density="compact"
          />
        </div>
      </v-card>
    </template>

    <v-dialog v-model="pickerOpen" max-width="640">
      <v-card>
        <v-card-title>เลือกกฎหมายเพื่อดูความสัมพันธ์</v-card-title>
        <v-card-text>
          <v-autocomplete
            v-model="pickerId"
            :items="rows"
            item-title="title"
            item-value="id"
            label="ค้นหากฎหมาย"
            prepend-inner-icon="mdi-magnify"
            variant="outlined"
            auto-select-first
            autofocus
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn class="text-none" @click="pickerOpen = false">ยกเลิก</v-btn>
          <v-btn color="primary" class="text-none" :disabled="!pickerId" @click="confirmPick">ดูความสัมพันธ์</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
    </v-container>

    <ELawFooter />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { documentFileDownloadUrl, downloadPdfExport, fetchReportSummary, fetchReview } from '../../api/client';
import type { LawMeta, LawRelation, RelationType, ReportSummary } from '../../types/document';
import DocBadge from '../../components/shared/DocBadge.vue';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import ELawFooter from '../../components/shared/ELawFooter.vue';
import RelationTreeView from '../../components/shared/RelationTreeView.vue';
import HierarchyList from '../../components/shared/HierarchyList.vue';
import { lawTypeToBadge, type LawTypeBadge } from '../../components/shared/lawBadge';
import {
  RELATION_FILTERS,
  buildRelationTree,
  collectDescendantIds,
  displayLawDate,
  flattenTree,
  loadRecentIds,
  mapShowRelRows,
  maxTreeLevel,
  metaStatusColor,
  rememberRecentId,
  typeColor,
  workflowStageColor,
  type RelTreeNode,
  type ShowRelRow,
  isActiveStatus,
  isCancelledStatus,
  isKeptInRelationGraph,
} from '../../composables/useShowRelations';

const PAGE_SIZE = 20;

const props = defineProps<{ documentId?: string }>();
const route = useRoute();
const router = useRouter();

const loading = ref(false);
const detailLoading = ref(false);
const downloadLoading = ref<string | null>(null);
const downloadAllLoading = ref(false);
const summary = ref<ReportSummary>({
  totals: { all: 0, published: 0, processing: 0, failed: 0, esign: 0, relations: 0, legacy_links: 0 },
  by_type: [],
  by_group: [],
  by_agency: [],
  by_year: [],
  documents: [],
});
const rootMeta = ref<LawMeta | null>(null);
const rootRelations = ref<Record<string, LawRelation[]>>({});
const pickerOpen = ref(false);
const pickerId = ref<string | null>(null);
const viewMode = ref<'tree' | 'hierarchy'>('tree');
const typeFilters = ref<RelationType[]>(RELATION_FILTERS.map((item) => item.value));

const listSearch = ref('');
const listStatus = ref<string | null>(null);
const listType = ref<string | null>(null);
const listSort = ref('newest');
const listPage = ref(1);

const treeSearch = ref('');
const treeStatus = ref<string | null>(null);
const treeType = ref<string | null>(null);
const treeSort = ref('newest');
const treePage = ref(1);

const selectedId = computed(() => props.documentId || (typeof route.params.documentId === 'string' ? route.params.documentId : ''));

const rows = computed(() => mapShowRelRows(summary.value.documents));
const selectedRow = computed(() => rows.value.find((row) => row.id === selectedId.value) ?? null);
const lawTypeBadge = computed<LawTypeBadge | null>(() =>
  selectedRow.value ? lawTypeToBadge(selectedRow.value.lawType) : null,
);


const statusOptions = [
  { label: 'ทุกสถานะ', value: null },
  { label: 'ดำเนินการ', value: 'ดำเนินการ' },
  { label: 'รอการเชื่อมโยงความสัมพันธ์', value: 'รอการเชื่อมโยงความสัมพันธ์' },
  { label: 'รอส่ง eSign', value: 'รอส่ง eSign' },
  { label: 'พร้อมเผยแพร่', value: 'พร้อมเผยแพร่' },
  { label: 'เผยแพร่', value: 'เผยแพร่' },
  { label: 'ยกเลิก', value: 'ยกเลิก' },
];

const sortOptions = [
  { label: 'ล่าสุด', value: 'newest' },
  { label: 'เก่าสุด', value: 'oldest' },
  { label: 'ชื่อ ก-ฮ', value: 'name' },
];

const typeOptions = computed(() => {
  const counts = new Map<string, number>();
  for (const row of rows.value) {
    if (row.lawType) counts.set(row.lawType, (counts.get(row.lawType) ?? 0) + 1);
  }
  return [
    { label: 'ทุกประเภท', value: null },
    ...[...counts.entries()]
      .sort((a, b) => b[1] - a[1])
      .map(([key]) => ({ label: key, value: key })),
  ];
});

function applyListFilters(source: ShowRelRow[], search: string, status: string | null, type: string | null, sort: string): ShowRelRow[] {
  let result = source;
  if (type) result = result.filter((row) => row.lawType === type);
  if (status) result = result.filter((row) => row.workflowStage === status || row.metaStatus === status);
  if (search.trim()) {
    const q = search.trim().toLowerCase();
    result = result.filter(
      (row) =>
        row.title.toLowerCase().includes(q)
        || row.org.toLowerCase().includes(q)
        || row.group.toLowerCase().includes(q),
    );
  }
  if (sort === 'oldest') return [...result].sort((a, b) => a.rawDate.localeCompare(b.rawDate));
  if (sort === 'name') return [...result].sort((a, b) => a.title.localeCompare(b.title, 'th'));
  return [...result].sort((a, b) => b.rawDate.localeCompare(a.rawDate));
}

const filteredList = computed(() => {
  const recent = loadRecentIds();
  const recentSet = new Set(recent);
  const filtered = applyListFilters(rows.value, listSearch.value, listStatus.value, listType.value, listSort.value);
  if (!listSearch.value.trim() && !listStatus.value && !listType.value && recent.length) {
    const recentRows = recent
      .map((id) => filtered.find((row) => row.id === id))
      .filter((row): row is ShowRelRow => Boolean(row));
    const rest = filtered.filter((row) => !recentSet.has(row.id));
    return [...recentRows, ...rest];
  }
  return filtered;
});

const listPageCount = computed(() => Math.max(1, Math.ceil(filteredList.value.length / PAGE_SIZE)));
const pagedList = computed(() => filteredList.value.slice((listPage.value - 1) * PAGE_SIZE, listPage.value * PAGE_SIZE));
const listRangeStart = computed(() => (filteredList.value.length === 0 ? 0 : (listPage.value - 1) * PAGE_SIZE + 1));
const listRangeEnd = computed(() => Math.min(listPage.value * PAGE_SIZE, filteredList.value.length));

const allowedTypes = computed(() => (typeFilters.value.length ? typeFilters.value : null));

const rootNode = computed(() => {
  if (!selectedId.value) return null;
  return buildRelationTree(
    selectedId.value,
    rows.value,
    rootRelations.value,
    allowedTypes.value,
  );
});

function filterTree(node: RelTreeNode | null): RelTreeNode | null {
  if (!node) return null;
  const q = treeSearch.value.trim().toLowerCase();
  const children = node.children
    .map((child) => filterTree(child))
    .filter((child): child is RelTreeNode => Boolean(child));

  const selfMatch = node.level === 0 || isKeptInRelationGraph(node.row) || (
    (!treeType.value || node.row.lawType === treeType.value)
    && (!treeStatus.value || node.row.workflowStage === treeStatus.value || node.row.metaStatus === treeStatus.value)
    && (!q || node.row.title.toLowerCase().includes(q) || node.row.org.toLowerCase().includes(q) || node.row.group.toLowerCase().includes(q))
  );

  if (node.level === 0) return { ...node, children };
  if (selfMatch || children.length) return { ...node, children };
  return null;
}

function sortChildren(node: RelTreeNode): RelTreeNode {
  const children = [...node.children].map(sortChildren);
  if (treeSort.value === 'oldest') children.sort((a, b) => a.row.rawDate.localeCompare(b.row.rawDate));
  else if (treeSort.value === 'name') children.sort((a, b) => a.row.title.localeCompare(b.row.title, 'th'));
  else children.sort((a, b) => b.row.rawDate.localeCompare(a.row.rawDate));
  return { ...node, children };
}

const filteredRootNode = computed(() => {
  const filtered = filterTree(rootNode.value);
  return filtered ? sortChildren(filtered) : null;
});

const descendantCount = computed(() => flattenTree(filteredRootNode.value).length);
const treePageCount = computed(() => Math.max(1, Math.ceil((filteredRootNode.value?.children.length ?? 0) / PAGE_SIZE)));
const pagedRootNode = computed(() => {
  const root = filteredRootNode.value;
  if (!root) return null;
  const start = (treePage.value - 1) * PAGE_SIZE;
  return { ...root, children: root.children.slice(start, start + PAGE_SIZE) };
});
const treeRangeStart = computed(() => (descendantCount.value === 0 ? 0 : (treePage.value - 1) * PAGE_SIZE + 1));
const treeRangeEnd = computed(() => {
  const shown = flattenTree(pagedRootNode.value).length;
  if (shown === 0) return 0;
  return treeRangeStart.value + shown - 1;
});

const stats = computed(() => {
  const nodes = flattenTree(rootNode.value);
  return [
    { label: 'กฎหมายลำดับรองทั้งหมด', value: `${nodes.length} ฉบับ`, tone: '', icon: 'mdi-layers-outline' },
    { label: 'มีผลบังคับใช้', value: String(nodes.filter((n) => isActiveStatus(n.row.metaStatus)).length), tone: 'is-success', icon: '' },
    { label: 'ถูกยกเลิก', value: String(nodes.filter((n) => isCancelledStatus(n.row.metaStatus)).length), tone: 'is-muted', icon: '' },
    { label: 'ต้องตรวจสอบ', value: String(nodes.filter((n) => !n.row.metaStatus).length), tone: 'is-warning', icon: '' },
    { label: 'ระดับสูงสุด', value: String(maxTreeLevel(rootNode.value)), tone: 'is-primary', icon: '' },
  ];
});

function toggleTypeFilter(type: RelationType): void {
  if (typeFilters.value.includes(type)) {
    typeFilters.value = typeFilters.value.filter((item) => item !== type);
  } else {
    typeFilters.value = [...typeFilters.value, type];
  }
}

function openDetail(id: string): void {
  rememberRecentId(id);
  router.push(`/law/relations/${encodeURIComponent(id)}`);
}

function goList(): void {
  router.push('/law/relations');
}

function confirmPick(): void {
  if (!pickerId.value) return;
  pickerOpen.value = false;
  openDetail(pickerId.value);
}

function safePdfName(title: string): string {
  return `${title.replace(/[/\\?%*:|"<>]/g, '_').substring(0, 100)}.pdf`;
}

async function downloadRowPdf(row: ShowRelRow): Promise<void> {
  const fileName = safePdfName(row.title || row.id);
  if (row.documentType === 'old') {
    const anchor = document.createElement('a');
    anchor.href = documentFileDownloadUrl(row.id);
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    return;
  }

  await downloadPdfExport(row.id, fileName);
}

async function downloadSingle(): Promise<void> {
  if (!selectedRow.value || downloadLoading.value) return;
  downloadLoading.value = selectedRow.value.id;
  try {
    await downloadRowPdf(selectedRow.value);
  } finally {
    downloadLoading.value = null;
  }
}

async function downloadAll(): Promise<void> {
  if (!selectedRow.value || downloadAllLoading.value) return;
  downloadAllLoading.value = true;
  try {
    await downloadRowPdf(selectedRow.value);
    const relatedRows = flattenTree(filteredRootNode.value).map((node) => node.row);
    for (const row of relatedRows) {
      await new Promise((resolve) => setTimeout(resolve, 500));
      await downloadRowPdf(row);
    }
  } finally {
    downloadAllLoading.value = false;
  }
}

async function loadDetail(id: string): Promise<void> {
  detailLoading.value = true;
  try {
    const review = await fetchReview(id);
    rootMeta.value = review.law_meta ?? null;
    const bag: Record<string, LawRelation[]> = { [id]: review.relations ?? [] };
    const extraIds = collectDescendantIds(id, rows.value);
    const extras = await Promise.all(
      extraIds.map((documentId) =>
        fetchReview(documentId)
          .then((item) => [documentId, item.relations ?? []] as const)
          .catch(() => [documentId, []] as const),
      ),
    );
    for (const [documentId, rels] of extras) {
      bag[documentId] = rels;
    }
    rootRelations.value = bag;
    rememberRecentId(id);
  } catch {
    rootMeta.value = null;
    rootRelations.value = {};
  } finally {
    detailLoading.value = false;
  }
}

onMounted(async () => {
  loading.value = true;
  try {
    summary.value = await fetchReportSummary();
  } finally {
    loading.value = false;
  }
  if (selectedId.value) {
    pickerId.value = selectedId.value;
    await loadDetail(selectedId.value);
  }
});

watch(selectedId, async (id) => {
  pickerId.value = id || null;
  treePage.value = 1;
  if (id) await loadDetail(id);
  else {
    rootMeta.value = null;
    rootRelations.value = {};
  }
});

watch([listSearch, listStatus, listType, listSort], () => { listPage.value = 1; });
watch([treeSearch, treeStatus, treeType, treeSort, typeFilters, viewMode], () => { treePage.value = 1; });
</script>

<style scoped>
.psr {
  background: #f8fafc;
  min-height: 100vh;
}

.psr-topbar {
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
}

.rel-root__title {
  color: #0f172a;
  font-size: 1.5rem;
  font-weight: 800;
  line-height: 1.35;
}

.rel-root__meta {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  padding: 16px 20px;
}

.rel-root__meta-label {
  align-items: center;
  color: #64748b;
  display: flex;
  font-size: 12px;
  font-weight: 600;
  gap: 4px;
  margin-bottom: 4px;
}

.rel-root__meta-value {
  color: #1e293b;
  font-size: 14px;
  font-weight: 700;
}

.rel-stats {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(5, minmax(120px, 1fr));
}

.rel-stat {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 14px 16px;
}

.rel-stat__label {
  align-items: center;
  color: #64748b;
  display: flex;
  font-size: 12px;
  margin-bottom: 8px;
}

.rel-stat__value {
  color: #0f172a;
  font-size: 1.5rem;
  font-weight: 800;
  line-height: 1.1;
}

.rel-stat__value.is-success { color: #16a34a; }
.rel-stat__value.is-muted { color: #64748b; }
.rel-stat__value.is-warning { color: #ea580c; }
.rel-stat__value.is-primary { color: #1e3a8a; }

.rel-filter-chip {
  cursor: pointer;
}

.rel-tree-wrap {
  min-height: 240px;
  overflow-x: auto;
  padding: 8px 0 16px;
}

.hierarchy-wrap {
  min-height: 240px;
  overflow-x: auto;
}

@media (max-width: 960px) {
  .rel-root__meta,
  .rel-stats {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
