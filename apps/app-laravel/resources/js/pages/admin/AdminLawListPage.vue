<template>
  <AppShell
    :breadcrumbs="['เมนูหลัก', 'จัดการตัวบทกฎหมาย']"
    title="จัดการตัวบทกฎหมาย"
    subtitle="จัดการกฎหมายทั้งหมด ค้นหา แก้ไข ความสัมพันธ์ และเวอร์ชัน"
  >
    <template #title-actions>
      <v-btn color="admin-primary" prepend-icon="mdi-plus" class="text-none" rounded="lg" to="/admin/upload">
        เพิ่มกฎหมายใหม่
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

    <div class="d-flex flex-wrap ga-3 mb-4 align-center">
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

    <v-card flat border rounded="lg">
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" />
      <v-table density="comfortable">
        <thead>
          <tr>
            <th>#</th>
            <th>ชื่อกฎหมาย / เอกสารสาระบบ</th>
            <th>ประเภท</th>
            <th>สถานะ</th>
            <th>แก้ไขล่าสุด</th>
            <th>จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && pagedLaws.length === 0">
            <td colspan="6" class="text-center pa-6 text-medium-emphasis">ไม่พบกฎหมายที่ตรงกับเงื่อนไข</td>
          </tr>
          <tr v-for="(law, idx) in pagedLaws" :key="law.id">
            <td class="text-caption text-medium-emphasis">{{ (page - 1) * PAGE_SIZE + idx + 1 }}</td>
            <td class="py-3" style="max-width: 400px">
              <div class="d-flex align-center ga-2 flex-wrap mb-1">
                <span class="text-body-2 font-weight-bold">{{ law.title }}</span>
                <v-chip v-if="law.isParent" size="x-small" color="deep-purple" variant="tonal" rounded="pill">
                  <v-icon start icon="mdi-link-variant" size="11" />
                  กฎหมายแม่บท
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
                  มีกฎหมายลูก {{ law.childCount }} ฉบับ
                </v-chip>
              </div>
              <div class="d-flex flex-wrap ga-3 text-caption text-medium-emphasis">
                <span v-if="law.org"><v-icon size="11" icon="mdi-domain" /> {{ law.org }}</span>
                <span v-if="law.group"><v-icon size="11" icon="mdi-tag" /> {{ law.group }}</span>
                <span v-if="law.pages > 0 || law.sections != null">
                  <v-icon size="11" icon="mdi-file-multiple" />
                  {{ law.pages }} หน้า<template v-if="law.sections != null"> / {{ law.sections }} ข้อ/มาตรา</template>
                </span>
              </div>
            </td>
            <td>
              <v-chip v-if="law.lawType" size="small" variant="flat" :color="typeColor(law.lawType)" rounded="pill" class="font-weight-bold text-white">{{ law.lawType }}</v-chip>
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
              <div class="d-flex align-center ga-1">
                <v-btn icon="mdi-pencil-outline" size="x-small" variant="text" color="admin-primary" :to="`/documents/${law.id}/review`" />
                <v-btn icon="mdi-eye-outline" size="x-small" variant="text" color="grey" :to="`/law/${law.id}`" />
                <v-menu>
                  <template #activator="{ props: menuProps }">
                    <v-btn icon="mdi-dots-vertical" size="x-small" variant="text" color="grey" v-bind="menuProps" />
                  </template>
                  <v-list density="compact" rounded="lg" min-width="160">
                    <v-list-item :to="`/documents/${law.id}/law-info`" prepend-icon="mdi-information-outline" title="ข้อมูลกฎหมาย" />
                    <v-list-item :to="`/documents/${law.id}/relations`" prepend-icon="mdi-graph-outline" title="ความสัมพันธ์" />
                    <v-divider class="my-1" />
                    <v-list-item :to="`/documents/${law.id}/rag`" prepend-icon="mdi-database-export-outline" title="RAG Export" />
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
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { fetchReportSummary } from '../../api/client';
import type { ReportSummary } from '../../types/document';
import AppShell from '../../components/shared/AppShell.vue';

const PAGE_SIZE = 20;

const summary = ref<ReportSummary>({
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
const filterStatus = ref<string | null>(null);
const sortOrder = ref('newest');
const page = ref(1);

onMounted(async () => {
  loading.value = true;
  try {
    summary.value = await fetchReportSummary();
  } finally {
    loading.value = false;
  }
});

watch([search, filterType, filterStatus, sortOrder], () => {
  page.value = 1;
});

const TYPE_META: Record<string, { color: string; icon: string }> = {
  พระราชบัญญัติ: { color: 'doc-phrb', icon: 'mdi-bank-outline' },
  ข้อบังคับ: { color: 'doc-kho-bangkhab', icon: 'mdi-scale-balance' },
  ระเบียบ: { color: 'doc-rabiap', icon: 'mdi-folder-outline' },
  ประกาศ: { color: 'doc-prakat', icon: 'mdi-bullhorn-variant-outline' },
};

const FEATURED_TYPES = ['พระราชบัญญัติ', 'ข้อบังคับ', 'ระเบียบ', 'ประกาศ'];

const statCards = computed(() => {
  const thirtyDaysAgo = Date.now() - 30 * 24 * 60 * 60 * 1000;
  return FEATURED_TYPES.map((typeName) => {
    const bucket = summary.value.by_type.find((b) => b.key === typeName);
    const recent = summary.value.documents.filter(
      (d) => d.type === typeName && d.date && new Date(d.date).getTime() > thirtyDaysAgo,
    ).length;
    const meta = TYPE_META[typeName] ?? { color: 'grey', icon: 'mdi-file-outline' };
    return { type: typeName, count: bucket?.count ?? 0, recent, ...meta };
  });
});

// Only laws that have passed the info step (ข้อมูล = step 4) belong in this catalog.
// Raw uploads still being processed / not yet filled in are hidden.
const infoCompletedDocs = computed(() =>
  summary.value.documents.filter((doc) => (doc.workflow_completed_step ?? 0) >= 4),
);

const childCountMap = computed<Record<string, number>>(() => {
  const map: Record<string, number> = {};
  for (const doc of infoCompletedDocs.value) {
    if (doc.parent_document_id) {
      map[doc.parent_document_id] = (map[doc.parent_document_id] ?? 0) + 1;
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

function workflowStageColor(stage: string): string {
  if (stage === 'เผยแพร่') return 'success';
  if (stage === 'พร้อมเผยแพร่') return 'admin-primary';
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
  workflowStage: string;
  isParent: boolean;
  childCount: number;
  org: string;
  group: string;
  pages: number;
  sections: number | null;
  editedAt: string;
  rawDate: string;
}

const laws = computed<LawRow[]>(() =>
  infoCompletedDocs.value.map((doc) => ({
    id: doc.id,
    title: doc.title,
    lawType: doc.type !== 'ไม่ระบุ' ? doc.type : '',
    metaStatus: doc.meta_status ?? '',
    workflowStage: workflowStageLabel(doc),
    isParent: (childCountMap.value[doc.id] ?? 0) > 0,
    childCount: childCountMap.value[doc.id] ?? 0,
    org: doc.agency !== 'ไม่ระบุ' ? doc.agency : '',
    group: doc.group !== 'ไม่ระบุ' ? doc.group : '',
    pages: doc.page_count ?? 0,
    sections: doc.section_count ?? null,
    editedAt: doc.date
      ? new Date(doc.date).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' })
      : '-',
    rawDate: doc.date ?? '',
  })),
);

// Count law types over the filtered (info-completed) list so the dropdown matches the table.
const typeCounts = computed<Array<{ key: string; count: number }>>(() => {
  const counts = new Map<string, number>();
  for (const law of laws.value) {
    if (law.lawType) counts.set(law.lawType, (counts.get(law.lawType) ?? 0) + 1);
  }
  return [...counts.entries()]
    .map(([key, count]) => ({ key, count }))
    .sort((a, b) => b.count - a.count);
});

const typeOptions = computed(() => [
  { label: 'ทุกประเภท', value: null },
  ...typeCounts.value.map((b) => ({ label: b.key, value: b.key })),
]);

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

const filteredLaws = computed(() => {
  let result = laws.value;
  if (filterType.value) result = result.filter((l) => l.lawType === filterType.value);
  if (filterStatus.value) result = result.filter((l) => l.workflowStage === filterStatus.value);
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
  return TYPE_META[type]?.color ?? 'grey';
}

function metaStatusColor(status: string): string {
  if (status === 'active' || status === 'มีผลบังคับใช้' || status === 'มีผลใช้บังคับ' || status === 'ใช้บังคับ' || status === 'บังคับใช้') return 'success';
  if (status === 'ยกเลิก' || status === 'ถูกยกเลิก') return 'error';
  if (status === 'พักใช้' || status === 'ระงับใช้') return 'warning';
  return 'grey';
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
</style>
