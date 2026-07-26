<template>
  <AppShell :breadcrumbs="['LAWSPACE', 'รายงาน']" title="รายงานสรุปเอกสาร" hide-top-bar>
    <!-- Stat cards -->
    <v-row class="mb-6">
      <v-col
        v-for="stat in statCards"
        :key="stat.label"
        cols="12"
        sm="6"
        md="2"
        class="d-flex"
      >
        <AdminStatCard
          :icon="stat.icon"
          :icon-color="stat.iconColor"
          :icon-bg="stat.iconBg"
          :number="stat.number"
          :label="stat.label"
        />
      </v-col>
    </v-row>

    <!-- Filter bar -->
    <div class="report-filters mb-4">
      <v-text-field v-model="filters.date_from" label="จากวันที่ (นำเข้า)" type="date" density="compact" variant="outlined" hide-details />
      <v-text-field v-model="filters.date_to" label="ถึงวันที่ (นำเข้า)" type="date" density="compact" variant="outlined" hide-details />
      <v-select v-model="filters.type" :items="typeOptions" label="ประเภท" density="compact" variant="outlined" hide-details clearable />
      <v-select v-model="filters.status" :items="STATUS_OPTIONS" item-title="title" item-value="value" label="สถานะ" density="compact" variant="outlined" hide-details clearable />
      <v-select v-model="filters.group" :items="groupOptions" label="หมวดหมู่" density="compact" variant="outlined" hide-details multiple chips closable-chips />
      <v-select v-model="filters.agency" :items="agencyOptions" label="หน่วยงาน" density="compact" variant="outlined" hide-details multiple chips closable-chips />
      <v-btn variant="text" color="grey" prepend-icon="mdi-close" @click="clearFilters">ล้างตัวกรอง</v-btn>
    </div>

    <!-- Breakdown cards -->
    <v-row>
      <v-col v-for="dim in dimensions" :key="dim.key" cols="12" md="6">
        <v-card flat border rounded="lg" class="pa-4 mb-2">
          <div class="text-subtitle-1 font-weight-bold mb-2">{{ dim.title }}</div>
          <div v-if="dim.buckets.length === 0" class="text-medium-emphasis text-body-2 pa-4 text-center">ไม่มีข้อมูล</div>
          <template v-else>
            <ApexChart
              :type="dim.chartType"
              height="240"
              :options="chartOptions(dim)"
              :series="chartSeries(dim)"
            />
            <v-table density="compact" class="mt-2">
              <tbody>
                <tr
                  v-for="b in dim.buckets"
                  :key="b.key"
                  class="report-count-row"
                  :class="{ 'report-count-row--active': isDrill(dim.key, b.key) }"
                  @click="toggleDrill(dim.key, b.key)"
                >
                  <td>{{ b.label }}</td>
                  <td class="text-right font-weight-bold">{{ b.count }}</td>
                  <td class="text-right text-caption text-medium-emphasis">{{ pct(b.count, dim.total) }}%</td>
                </tr>
              </tbody>
            </v-table>
          </template>
        </v-card>
      </v-col>
    </v-row>

    <!-- Drill-down document list -->
    <v-card flat border rounded="lg" class="mt-4">
      <div class="pa-3 d-flex align-center ga-2">
        <span class="text-subtitle-1 font-weight-bold flex-grow-1">รายการเอกสาร ({{ drilledDocuments.length }})</span>
        <v-chip v-if="drill" closable color="admin-primary" size="small" @click:close="drill = null">
          {{ drillLabel }}
        </v-chip>
      </div>
      <v-divider />
      <v-table density="comfortable">
        <thead>
          <tr>
            <th>ชื่อเอกสาร</th>
            <th>ประเภท</th>
            <th>หมวดหมู่</th>
            <th>หน่วยงาน</th>
            <th>สถานะ</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="drilledDocuments.length === 0">
            <td colspan="5" class="text-center pa-6 text-medium-emphasis">ไม่พบเอกสาร</td>
          </tr>
          <tr v-for="doc in drilledDocuments" :key="doc.id" class="report-doc-row" @click="router.push(`/documents/${doc.id}/review`)">
            <td class="py-2" style="max-width:320px">
              <span class="d-block text-body-2 font-weight-medium" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ doc.title }}</span>
            </td>
            <td class="text-caption">{{ doc.type }}</td>
            <td class="text-caption">{{ doc.group }}</td>
            <td class="text-caption">{{ doc.agency }}</td>
            <td><v-chip size="x-small" rounded="pill">{{ doc.status }}</v-chip></td>
          </tr>
        </tbody>
      </v-table>
    </v-card>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import ApexChart from 'vue3-apexcharts/core';
import 'apexcharts/bar';
import 'apexcharts/donut';
import 'apexcharts/features/legend';
import { fetchReportSummary } from '../../api/client';
import type { ReportBucket, ReportFilters, ReportSummary } from '../../types/document';
import AdminStatCard from '../../components/admin/AdminStatCard.vue';
import AppShell from '../../components/shared/AppShell.vue';

const router = useRouter();

const summary = ref<ReportSummary>({
  totals: { all: 0, published: 0, processing: 0, failed: 0, esign: 0, relations: 0, legacy_links: 0 },
  by_type: [], by_group: [], by_agency: [], by_year: [], documents: [],
});
const filters = reactive<ReportFilters>({ date_from: '', date_to: '', type: '', status: '', group: [], agency: [] });
const drill = ref<{ dimension: string; value: string } | null>(null);

const STATUS_OPTIONS = [
  { title: 'เสร็จสิ้น', value: 'done' },
  { title: 'กำลังประมวลผล', value: 'processing' },
  { title: 'รอดำเนินการ', value: 'queued' },
  { title: 'ล้มเหลว', value: 'failed' },
];

async function load(): Promise<void> {
  summary.value = await fetchReportSummary(filters);
}

onMounted(load);
watch(filters, load, { deep: true });

const typeOptions = computed(() => summary.value.by_type.map((b) => b.key).filter((k) => k !== 'ไม่ระบุ'));
const groupOptions = computed(() => summary.value.by_group.map((b) => b.key).filter((k) => k !== 'ไม่ระบุ'));
const agencyOptions = computed(() => summary.value.by_agency.map((b) => b.key).filter((k) => k !== 'ไม่ระบุ'));

const statCards = computed(() => [
  { icon: 'mdi-file-document-multiple-outline', iconColor: '#2563eb', iconBg: '#dbeafe', number: summary.value.totals.all, label: 'เอกสารทั้งหมด' },
  { icon: 'mdi-check-circle-outline', iconColor: '#16a34a', iconBg: '#dcfce7', number: summary.value.totals.published, label: 'เผยแพร่แล้ว' },
  { icon: 'mdi-file-edit-outline', iconColor: '#64748b', iconBg: '#f1f5f9', number: summary.value.totals.failed, label: 'ล้มเหลว' },
  { icon: 'mdi-clock-outline', iconColor: '#d97706', iconBg: '#fef3c7', number: summary.value.totals.processing, label: 'รอตรวจสอบ' },
  { icon: 'mdi-draw-pen', iconColor: '#7c3aed', iconBg: '#ede9fe', number: summary.value.totals.esign, label: 'รอลงนาม (eSign)' },
]);

interface Dimension { key: string; title: string; buckets: ReportBucket[]; total: number; chartType: 'donut' | 'bar' }

const dimensions = computed<Dimension[]>(() => [
  { key: 'type', title: 'จำแนกตามประเภท', buckets: summary.value.by_type, total: sum(summary.value.by_type), chartType: 'donut' },
  { key: 'group', title: 'จำแนกตามหมวดหมู่', buckets: summary.value.by_group, total: sum(summary.value.by_group), chartType: 'donut' },
  { key: 'agency', title: 'จำแนกตามหน่วยงาน', buckets: summary.value.by_agency, total: sum(summary.value.by_agency), chartType: 'donut' },
  { key: 'year', title: 'จำแนกตามปี (พ.ศ.)', buckets: summary.value.by_year, total: sum(summary.value.by_year), chartType: 'bar' },
]);

function sum(buckets: ReportBucket[]): number {
  return buckets.reduce((acc, b) => acc + b.count, 0);
}
function pct(count: number, total: number): string {
  return total > 0 ? ((count / total) * 100).toFixed(0) : '0';
}

function chartSeries(dim: Dimension): number[] | { name: string; data: number[] }[] {
  return dim.chartType === 'bar'
    ? [{ name: 'จำนวน', data: dim.buckets.map((b) => b.count) }]
    : dim.buckets.map((b) => b.count);
}
function chartOptions(dim: Dimension): Record<string, unknown> {
  const labels = dim.buckets.map((b) => b.label);
  return dim.chartType === 'bar'
    ? { chart: { toolbar: { show: false } }, xaxis: { categories: labels }, plotOptions: { bar: { horizontal: false } }, dataLabels: { enabled: false } }
    : { chart: { toolbar: { show: false } }, labels, legend: { position: 'bottom' } };
}

function isDrill(dimension: string, value: string): boolean {
  return drill.value?.dimension === dimension && drill.value?.value === value;
}
function toggleDrill(dimension: string, value: string): void {
  drill.value = isDrill(dimension, value) ? null : { dimension, value };
}
const drillLabel = computed(() => (drill.value ? drill.value.value : ''));

const drilledDocuments = computed(() => {
  const d = drill.value;
  if (!d) return summary.value.documents;
  return summary.value.documents.filter((doc) => {
    if (d.dimension === 'type') return doc.type === d.value;
    if (d.dimension === 'group') return doc.group === d.value;
    if (d.dimension === 'agency') return doc.agency === d.value;
    if (d.dimension === 'year') return (doc.date ?? '').includes(String(Number(d.value) - 543));
    return true;
  });
});

function clearFilters(): void {
  filters.date_from = '';
  filters.date_to = '';
  filters.type = '';
  filters.status = '';
  filters.group = [];
  filters.agency = [];
  drill.value = null;
}
</script>

<style scoped>
.report-filters {
  display: grid;
  grid-template-columns: repeat(3, minmax(160px, 1fr)) auto;
  gap: 10px;
  align-items: center;
}
.report-count-row { cursor: pointer; }
.report-count-row:hover { background: rgba(0, 0, 0, 0.03); }
.report-count-row--active { background: rgba(37, 99, 235, 0.1); }
.report-doc-row { cursor: pointer; }
.report-doc-row:hover { background: rgba(0, 0, 0, 0.03); }
@media (max-width: 960px) {
  .report-filters { grid-template-columns: 1fr; }
}
</style>
