<template>
  <AppShell
    :breadcrumbs="['เมนูหลัก', 'จัดการตัวบทกฎหมาย']"
    title="จัดการตัวบทกฎหมาย"
    subtitle="จัดการกฎหมายทั้งหมด ค้นหา แก้ไข ความสัมพันธ์ และเวอร์ชัน"
  >
    <template #title-actions>
      <v-btn color="admin-primary" prepend-icon="mdi-plus" class="text-none" rounded="lg" to="/admin/upload">
        เพิ่มกฎหมายล่าสุด
      </v-btn>
    </template>

    <!-- Type stat cards -->
    <div class="d-flex ga-4 mb-5 flex-wrap">
      <v-card
        v-for="stat in statCards"
        :key="stat.type"
        flat
        rounded="lg"
        class="flex-1-1 pa-5 adm-stat-card"
        :style="`min-width:180px; border:1px solid rgba(var(--v-theme-${stat.color}),0.35); border-top:3px solid rgb(var(--v-theme-${stat.color}))`"
      >
        <div class="d-flex align-center ga-3 mb-3">
          <div class="adm-stat-icon" :style="`--stat-color: rgb(var(--v-theme-${stat.color}))`">
            <v-icon :icon="stat.icon" :color="stat.color" size="18" />
          </div>
          <div class="flex-1 min-width-0">
            <div class="d-flex align-center ga-2">
              <span class="text-body-2 font-weight-bold text-truncate">{{ stat.type }}</span>
              <v-chip v-if="stat.recent > 0" size="x-small" :color="stat.color" variant="tonal" rounded="pill" class="flex-shrink-0">
                +{{ stat.recent }} เดือนนี้
              </v-chip>
            </div>
          </div>
        </div>
        <div class="d-flex align-end ga-1">
          <span class="text-h4 font-weight-bold" :style="`color:rgb(var(--v-theme-${stat.color}))`">
            {{ stat.count.toLocaleString('th-TH') }}
          </span>
          <span class="text-body-2 text-medium-emphasis mb-1">ฉบับ</span>
        </div>
      </v-card>
    </div>

    <div v-if="filtersReady" class="d-flex flex-wrap ga-3 mb-4 align-center">
      <v-text-field
        v-model="search"
        placeholder="ค้นหาชื่อกฎหมาย / หน่วยงาน / หมวดหมู่"
        prepend-inner-icon="mdi-magnify"
        variant="outlined"
        density="compact"
        hide-details
        style="max-width: 500px; flex: 1 1 300px"
      />
      <v-select
        v-model="filterType"
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
      <v-select
        v-model="filterLawType"
        :items="lawTypeOptions"
        item-title="label"
        item-value="value"
        label="ประเภทเอกสาร"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        style="max-width: 220px"
      />
      <v-select
        v-model="filterCategory"
        :items="categoryOptions"
        item-title="label"
        item-value="value"
        label="หมวดเอกสาร"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        style="max-width: 220px"
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

    <div v-if="filtersReady && incomingFilters.length" class="d-flex ga-2 mb-3">
      <v-chip v-for="filter in incomingFilters" :key="filter.key" closable @click:close="clearIncoming(filter.key)">
        กรองจาก: {{ filter.label || 'ไม่พบรายการ' }}
      </v-chip>
    </div>
    <v-card flat border rounded="lg">
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" />
      <v-table density="comfortable">
        <thead>
          <tr>
            <th>#</th>
            <th>ชื่อกฎหมาย / เอกสารสาระบบ</th>
            <th>ประเภท</th>
            <th>สถานะบังคับใช้</th>
            <th>การเข้าถึง</th>
            <th>แก้ไขล่าสุด</th>
            <th>จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && pagedLaws.length === 0">
            <td colspan="7" class="text-center pa-6 text-medium-emphasis">ไม่พบกฎหมายที่ตรงกับเงื่อนไข</td>
          </tr>
          <tr v-for="(law, idx) in pagedLaws" :key="law.id">
            <td class="text-center text-caption text-medium-emphasis">{{ (page - 1) * PAGE_SIZE + idx + 1 }}</td>
            <td class="py-3" style="max-width: 400px" :class="isEmptyTableValue(law.title) ? 'text-center' : 'text-start'">
              <div class="d-flex align-center ga-2 flex-wrap mb-1" :class="{ 'justify-center': isEmptyTableValue(law.title) }">
                <v-tooltip :text="law.title" location="top">
                  <template #activator="{ props: tooltipProps }">
                    <span v-bind="tooltipProps" class="adm-law-title text-body-2 font-weight-bold">{{ tableValue(law.title) }}</span>
                  </template>
                </v-tooltip>
                <v-chip v-if="law.isParent" size="x-small" color="deep-purple" variant="tonal" rounded="pill">
                  <v-icon start icon="mdi-link-variant" size="11" />
                  กฎหมายที่อ้างถึง
                </v-chip>
              </div>
              <div v-if="law.childCount" class="d-flex ga-2 mb-1">
                <v-chip
                  size="x-small"
                  color="teal"
                  variant="tonal"
                  rounded="pill"
                  :to="`/documents/${law.id}/relations`"
                  @click.stop
                >
                  <v-icon start icon="mdi-sitemap" size="10" />
                  มีเอกสารที่อ้างถึง {{ tableValue(law.childCount) }} ฉบับ
                </v-chip>
              </div>
              <div class="d-flex flex-wrap ga-3 text-caption text-medium-emphasis">
                <v-chip size="x-small" :color="workflowStageColor(law.workflowStage)" variant="tonal">{{ tableValue(law.workflowStage) }}</v-chip>
                <span v-if="law.org"><v-icon size="11" icon="mdi-domain" /> {{ tableValue(law.org) }}</span>
                <span v-if="law.group"><v-icon size="11" icon="mdi-tag" /> {{ tableValue(law.group) }}</span>
                <span v-if="law.pages > 0 || law.sections != null">
                  <v-icon size="11" icon="mdi-file-multiple" />
                  {{ tableValue(law.pages) }} หน้า<template v-if="law.sections != null"> / {{ tableValue(law.sections) }} ข้อ</template>
                </span>
              </div>
            </td>
            <td class="text-center">
              <v-chip v-if="law.lawType" size="small" variant="flat" :color="typeColor(law.lawType)" rounded="pill" class="font-weight-bold text-white">{{ lawTypes.typeLabel(law.lawType) }}</v-chip>
              <span v-else>-</span>
            </td>
            <td class="text-center">
              <v-chip
                size="x-small"
                :color="effectiveStatusColor(law)"
                variant="tonal"
                rounded="pill"
              >
                <v-icon start icon="mdi-circle" size="8" />
                {{ effectiveStatusLabel(law) }}
              </v-chip>
            </td>
            <td class="text-center">
              <v-chip size="x-small" :color="law.accessScope === 'public' ? 'success' : 'grey'" variant="tonal" rounded="pill">
                {{ tableValue(law.accessScope === 'public' ? 'Public' : 'Private') }}
              </v-chip>
            </td>
            <td class="text-center text-caption">{{ tableValue(law.editedAt) }}</td>
            <td class="text-center">
              <div class="d-flex justify-center align-center ga-1">
                <v-btn icon="mdi-pencil-outline" size="x-small" variant="text" color="admin-primary" :to="`/documents/${law.id}/edit`" />
                <v-btn icon="mdi-eye-outline" size="x-small" variant="text" color="grey" :to="`/law/${law.id}?from=admin`" />
                <v-menu>
                  <template #activator="{ props: menuProps }">
                    <v-btn icon="mdi-dots-vertical" size="x-small" variant="text" color="grey" v-bind="menuProps" />
                  </template>
                  <v-list density="compact" rounded="lg" min-width="160">
                    <v-list-item :to="`/documents/${law.id}/law-info`" prepend-icon="mdi-information-outline" title="ข้อมูลกฎหมาย" />
                    <v-list-item :to="`/documents/${law.id}/relations`" prepend-icon="mdi-graph-outline" title="ความสัมพันธ์" />
                    <v-list-item prepend-icon="mdi-history" title="ประวัติเวอร์ชัน" @click="openVersions(law.id)" />
                    <v-divider class="my-1" />
                    <v-list-item :to="`/documents/${law.id}/rag`" prepend-icon="mdi-database-export-outline" title="จัดลำดับเนื้อหา" />
                  </v-list>
                </v-menu>
              </div>
            </td>
          </tr>
        </tbody>
      </v-table>

      <v-divider />
      <div class="d-flex justify-space-between align-center pa-3">
        <span class="text-caption text-medium-emphasis">
          กำลังแสดงผล {{ rangeStart.toLocaleString('th-TH') }} - {{ rangeEnd.toLocaleString('th-TH') }} จากทั้งหมด {{ filteredLaws.length.toLocaleString('th-TH') }} รายการ
        </span>
        <v-pagination v-if="pageCount > 1" v-model="page" :length="pageCount" :total-visible="5" rounded="circle" density="compact" />
      </div>
    </v-card>

    <v-dialog v-model="versionDialogOpen" max-width="440">
      <v-card rounded="lg">
        <v-card-title class="d-flex align-center ga-2 text-body-1 font-weight-bold">
          <v-icon icon="mdi-history" size="small" /> ประวัติเวอร์ชัน
        </v-card-title>
        <v-card-text>
          <div v-if="versionStore.loading" class="text-center pa-6">
            <v-progress-circular indeterminate color="admin-primary" />
          </div>
          <div v-else-if="versionStore.versions.length === 0" class="text-center text-medium-emphasis pa-6">
            ไม่มีประวัติเวอร์ชัน
          </div>
          <VersionHistoryTimeline v-else :versions="versionStore.versions" viewed-document-id="" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="versionDialogOpen = false">ปิด</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </AppShell>
</template>

<script lang="ts">
let cachedLawSummary: import('../../types/document').ReportSummary | null = null;
</script>

<script setup lang="ts">
import { tableValue, isEmptyTableValue } from '../../utils/tableValue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { fetchReportSummary } from '../../api/client';
import type { ReportSummary } from '../../types/document';
import { formatThaiDateNumeric } from '../../utils/thaiDate';
import { parentIdsOf } from '../../composables/useLawCatalog';
import AppShell from '../../components/shared/AppShell.vue';
import { useVersionStore } from '../../stores/versionStore';
import VersionHistoryTimeline from '../../components/law/VersionHistoryTimeline.vue';
import { useLawStatus } from '../../composables/useLawStatus';
import { useLawType } from '../../composables/useLawType';
import { useLookups, withInactiveCurrent } from '../../composables/useLookups';

const PAGE_SIZE = 20;

const summary = ref<ReportSummary>(cachedLawSummary ?? {
  totals: { all: 0, published: 0, processing: 0, failed: 0, esign: 0, relations: 0, legacy_links: 0 },
  by_type: [],
  by_group: [],
  by_agency: [],
  by_year: [],
  documents: [],
});
const loading = ref(false);
const search = ref('');
const filterType = ref<string | null>(null);
const filterLawType = ref<string | null>(null);
const filterCategory = ref<string | null>(null);
const filterStatus = ref<string | null>(null);
const sortOrder = ref('newest');
const page = ref(1);

const versionStore = useVersionStore();
const { draftCode, inForceCode, isRepealed, statusColor, statusLabel } = useLawStatus();
const lawTypes = useLawType();
const route = useRoute();
const router = useRouter();
const lookups = useLookups();
const filtersReady = ref(false);
const filterChange = ref<string | null>(null);
const filterStructure = ref<string | null>(null);
const filterStage = ref<string | null>(null);
const versionDialogOpen = ref(false);

function openVersions(id: string): void {
  versionDialogOpen.value = true;
  void versionStore.fetch(id);
}

let applyingQuery = false;
let searchTimer: ReturnType<typeof setTimeout> | undefined;
onUnmounted(() => clearTimeout(searchTimer));
function readQuery(): void {
  applyingQuery = true;
  const value = (key: string) => typeof route.query[key] === 'string' ? route.query[key] as string : null;
  filterType.value = value('family');
  filterLawType.value = value('type');
  filterCategory.value = value('category');
  filterStatus.value = value('status');
  filterChange.value = value('change');
  filterStructure.value = value('structure');
  filterStage.value = value('stage');
  search.value = value('q') ?? '';
  sortOrder.value = value('sort') ?? 'newest';
  page.value = 1;
  applyingQuery = false;
}
function writeQuery(): void {
  const query = { ...route.query };
  const values = { family: filterType.value, type: filterLawType.value, category: filterCategory.value,
    status: filterStatus.value, change: filterChange.value, structure: filterStructure.value,
    stage: filterStage.value, q: search.value.trim(), sort: sortOrder.value === 'newest' ? null : sortOrder.value };
  for (const [key, value] of Object.entries(values)) {
    if (value) query[key] = value;
    else delete query[key];
  }
  void router.replace({ query });
}
onMounted(async () => {
  loading.value = !cachedLawSummary;
  try {
    await lookups.load();
    readQuery();
    filtersReady.value = true;
    summary.value = await fetchReportSummary();
    cachedLawSummary = summary.value;
  } finally {
    loading.value = false;
  }
});
watch(() => route.query, () => {
  if (!filtersReady.value) return;
  clearTimeout(searchTimer);
  readQuery();
});
watch([filterType, filterLawType, filterCategory, filterStatus, filterChange, filterStructure, filterStage, sortOrder], () => {
  if (applyingQuery || !filtersReady.value) return;
  page.value = 1;
  clearTimeout(searchTimer);
  writeQuery();
}, { flush: 'sync' });
watch(search, () => {
  if (applyingQuery || !filtersReady.value) return;
  page.value = 1;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(writeQuery, 300);
}, { flush: 'sync' });
const incomingFilters = computed(() => [
  { key: 'change', code: filterChange.value, label: lookups.changeStatusTypesAll.value.find((x) => x.value === filterChange.value)?.title },
  { key: 'structure', code: filterStructure.value, label: lookups.legalStructuresAll.value.find((x) => x.code === filterStructure.value)?.title },
  { key: 'stage', code: filterStage.value, label: filterStage.value },
].filter((x) => x.code));
function clearIncoming(key: string): void {
  if (key === 'change') filterChange.value = null;
  if (key === 'structure') filterStructure.value = null;
  if (key === 'stage') filterStage.value = null;
}

const FAMILY_META: Record<string, { color: string; icon: string }> = {
  LFM04: { color: 'doc-phaainok', icon: 'mdi-bank-outline' },
  LFM01: { color: 'doc-kho-bangkhab', icon: 'mdi-scale-balance' },
  LFM02: { color: 'doc-rabiap', icon: 'mdi-folder-outline' },
  LFM03: { color: 'doc-prakat', icon: 'mdi-bullhorn-variant-outline' },
};

const statCards = computed(() => {
  const thirtyDaysAgo = Date.now() - 30 * 24 * 60 * 60 * 1000;
  return lawTypes.familiesOrdered.value.map((family) => {
    const docsForType = summary.value.documents.filter((d) => d.law_family_code === family.code);
    const count = docsForType.length;
    const recent = docsForType.filter(
      (d) => d.date && new Date(d.date).getTime() > thirtyDaysAgo,
    ).length;
    const meta = FAMILY_META[family.code] ?? { color: 'grey', icon: 'mdi-file-outline' };
    return { type: family.title, count, recent, ...meta };
  });
});

const childCountMap = computed<Record<string, number>>(() => {
  const map: Record<string, number> = {};
  for (const doc of summary.value.documents) {
    for (const parentId of parentIdsOf(doc)) {
      map[parentId] = (map[parentId] ?? 0) + 1;
    }
  }
  return map;
});

function workflowStageLabel(doc: { status: string; meta_status: string; published_date?: string; workflow_completed_step: number | null }): string {
  if (isRepealed(doc.meta_status)) return 'ยกเลิก';
  // เผยแพร่ = ต้องมี published_date จริง ๆ ไม่ใช่ดูจาก pipeline status
  if (doc.published_date) return 'เผยแพร่';
  const step = doc.workflow_completed_step ?? 0;
  if (step >= 6) return 'รอลงนาม';
  if (step >= 5) return 'รอส่ง eSign';
  if (step >= 4) return 'รอการเชื่อมโยงความสัมพันธ์';
  if (doc.status === 'done' || doc.status === 'exported' || doc.status === 'ingested') return 'ดำเนินการ';
  return 'กำลังประมวลผล';
}

function workflowStageColor(stage: string): string {
  if (stage === 'เผยแพร่') return 'success';
  if (stage === 'รอลงนาม') return 'admin-primary';
  if (stage === 'รอส่ง eSign') return 'deep-purple';
  if (stage === 'รอการเชื่อมโยงความสัมพันธ์') return 'orange';
  if (stage === 'ยกเลิก') return 'error';
  if (stage === 'ดำเนินการ') return 'teal';
  return 'grey';
}

interface LawRow {
  id: string;
  title: string;
  lawType: string;
  metaStatus: string;
  changeStatus: string;
  publishedDate: string;
  workflowStage: string;
  isParent: boolean;
  childCount: number;
  org: string;
  group: string;
  familyCode: string;
  groupCodes: string[];
  structureCodes: string[];
  changeCode: string;
  pages: number;
  sections: number | null;
  editedAt: string;
  rawDate: string;
  accessScope: 'public' | 'private';
}

const laws = computed<LawRow[]>(() =>
  summary.value.documents.map((doc) => ({
    id: doc.id,
    title: doc.title,
    lawType: doc.law_type_code ?? '',
    familyCode: doc.law_family_code ?? '',
    groupCodes: doc.group_codes ?? [],
    structureCodes: doc.structure_codes ?? [],
    changeCode: doc.change_status_code ?? '',
    metaStatus: doc.meta_status_code ?? '',
    changeStatus: doc.change_status ?? '',
    publishedDate: doc.published_date ?? '',
    workflowStage: workflowStageLabel(doc),
    isParent: (childCountMap.value[doc.id] ?? 0) > 0,
    childCount: childCountMap.value[doc.id] ?? 0,
    org: doc.agency !== 'ไม่ระบุ' ? doc.agency : '',
    group: doc.group !== 'ไม่ระบุ' ? doc.group : '',
    pages: doc.page_count ?? 0,
    sections: doc.section_count ?? null,
    editedAt: formatThaiDateNumeric(doc.date) || '-',
    rawDate: doc.date ?? '',
    accessScope: doc.access_scope === 'public' ? 'public' : 'private',
  })),
);

// Count families over all documents, including documents still in progress.
const typeCounts = computed<Array<{ key: string; count: number }>>(() => {
  const counts = new Map<string, number>();
  for (const law of laws.value) {
    const familyCode = law.familyCode;
    if (familyCode) counts.set(familyCode, (counts.get(familyCode) ?? 0) + 1);
  }
  return [...counts.entries()]
    .map(([key, count]) => ({ key, count }))
    .sort((a, b) => b.count - a.count);
});

const typeOptions = computed(() => [
  { label: 'ทุกประเภท', value: null },
  ...withInactiveCurrent(lookups.lawFamilies.value, lookups.lawFamiliesAll.value, filterType.value).map((family) => ({
    label: `${family.title} (${typeCounts.value.find((b) => b.key === family.code)?.count ?? 0})`,
    value: family.code,
  })),
]);

const lawTypeOptions = computed(() => [
  { label: 'ทุกประเภทเอกสาร', value: null },
  ...withInactiveCurrent(lookups.documentTypes.value, lookups.documentTypesAll.value, filterLawType.value).map((type) => ({ label: type.title, value: type.code })),
]);

const categoryOptions = computed(() => [
  { label: 'ทุกหมวด', value: null },
  ...withInactiveCurrent(lookups.lawGroups.value, lookups.lawGroupsAll.value, filterCategory.value).map((cat) => ({
    label: cat.title,
    value: cat.code,
  })),
]);

const statusOptions = computed(() => [
  { label: 'ทุกสถานะบังคับใช้', value: null },
  ...withInactiveCurrent(lookups.statuses.value, lookups.statusesAll.value, filterStatus.value).map((item) => ({ label: item.title, value: item.code })),
]);

const sortOptions = [
  { label: 'ล่าสุด', value: 'newest' },
  { label: 'เก่าสุด', value: 'oldest' },
  { label: 'ชื่อ ก-ฮ', value: 'name' },
];

const filteredLaws = computed(() => {
  let result = laws.value;
  if (filterType.value) result = result.filter((l) => l.familyCode === filterType.value);
  if (filterLawType.value) result = result.filter((l) => l.lawType === filterLawType.value);
  if (filterCategory.value) result = result.filter((l) => l.groupCodes.includes(filterCategory.value!));
  if (filterStatus.value) result = result.filter((l) => l.metaStatus === filterStatus.value);
  if (filterChange.value) result = result.filter((l) => l.changeCode === filterChange.value);
  if (filterStructure.value) result = result.filter((l) => l.structureCodes.includes(filterStructure.value!));
  if (filterStage.value) result = result.filter((l) => l.workflowStage === filterStage.value);
  if (search.value.trim()) {
    const q = search.value.trim().toLowerCase();
    result = result.filter(
      (l) => l.title.toLowerCase().includes(q) || l.org.toLowerCase().includes(q) || l.group.toLowerCase().includes(q),
    );
  }
  if (sortOrder.value === 'oldest') return [...result].sort((a, b) => a.rawDate.localeCompare(b.rawDate));
  if (sortOrder.value === 'name') return [...result].sort((a, b) => a.title.localeCompare(b.title, 'th'));
  return [...result].sort((a, b) => b.rawDate.localeCompare(a.rawDate));
});

const pageCount = computed(() => Math.ceil(filteredLaws.value.length / PAGE_SIZE));
const pagedLaws = computed(() => filteredLaws.value.slice((page.value - 1) * PAGE_SIZE, page.value * PAGE_SIZE));
const rangeStart = computed(() => (filteredLaws.value.length === 0 ? 0 : (page.value - 1) * PAGE_SIZE + 1));
const rangeEnd = computed(() => Math.min(page.value * PAGE_SIZE, filteredLaws.value.length));

function typeColor(type: string): string {
  return FAMILY_META[lawTypes.typeFamily(type)]?.color ?? 'grey';
}

function metaStatusColor(status: string): string {
  const color = statusColor(status);
  if (color) return color;
  if (status === 'พักใช้' || status === 'ระงับใช้') return 'warning';
  return 'grey';
}

// สถานะกฎหมายที่แสดง: ยังไม่เผยแพร่ = "ร่าง" เสมอ; เผยแพร่แล้ว = ตาม meta_status จริง
function effectiveStatusLabel(law: LawRow): string {
  if (!law.publishedDate) return statusLabel(draftCode.value);
  if (law.metaStatus) return statusLabel(law.metaStatus);
  return statusLabel(inForceCode.value);
}

function effectiveStatusColor(law: LawRow): string {
  if (!law.publishedDate) return 'warning';
  if (law.metaStatus) return metaStatusColor(law.metaStatus);
  return 'success';
}
</script>

<style scoped>
.adm-stat-icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  background: color-mix(in srgb, var(--stat-color) 12%, transparent);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.adm-law-title {
  display: inline-block;
  max-width: min(520px, 42vw);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  vertical-align: bottom;
}
</style>
