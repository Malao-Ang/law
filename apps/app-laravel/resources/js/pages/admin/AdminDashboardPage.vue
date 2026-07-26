<template>
  <AppShell :breadcrumbs="['เมนูหลัก', 'หน้าแรก']" title="" hide-top-bar show-bell>
    <v-row class="mb-2">
      <v-col cols="12" sm="6" md="3">
        <ReportStatCard
          tone="danger"
          title="เอกสารที่ต้องตรวจสอบ"
          :value="summary.totals.failed"
          footnote="* เอกสารที่ประมวลผลไม่สำเร็จ ต้องตรวจสอบ"
        />
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <ReportStatCard
          title="รอปรับปรุง (LEGACY LINK)"
          :value="summary.totals.legacy_links"
          footnote="* จำนวนการอ้างอิงกฎหมายฉบับที่ยกเลิกแล้ว"
        />
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <ReportStatCard
          tone="primary"
          title="คิวประมวลผล OCR"
          :value="summary.totals.processing"
          footnote="* เอกสารที่รอประมวลผล/ยืนยันความถูกต้อง"
        />
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <ReportStatCard
          title="ความสัมพันธ์ที่บันทึกแล้ว"
          :value="summary.totals.relations"
          footnote="เส้นเชื่อมโยง (Linkage)"
        />
      </v-col>
    </v-row>

    <v-row class="mb-2">
      <v-col cols="12" md="6">
        <v-card border rounded="lg" class="dashboard-panel pa-6">
          <div class="d-flex align-baseline mb-4">
            <span class="text-h6 font-weight-bold">การกระจายตามประเภทกฎหมาย</span>
            <span class="text-caption text-medium-emphasis ml-2">(จำนวนเอกสารต่อประเภท)</span>
          </div>
          <div v-if="typeBars.length === 0" class="text-medium-emphasis">ยังไม่มีข้อมูล</div>
          <div v-for="bar in typeBars" :key="bar.key" class="mb-4">
            <div class="d-flex justify-space-between mb-1 ga-3">
              <span class="text-body-2 font-weight-medium text-truncate">{{ bar.label }}</span>
              <span class="text-body-2 font-weight-bold type-bar-count">
                {{ bar.count.toLocaleString('th-TH') }} ฉบับ
              </span>
            </div>
            <div class="type-bar-track">
              <div class="type-bar-fill" :style="{ width: `${bar.pct}%` }" />
            </div>
          </div>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card border rounded="lg" class="dashboard-panel pa-6">
          <div class="d-flex align-center mb-4">
            <v-icon icon="mdi-alert-outline" color="warning" class="me-2" />
            <span class="text-h6 font-weight-bold">รายการที่ต้องตรวจสอบเร่งด่วน</span>
          </div>
          <div v-if="attentionItems.length === 0" class="text-medium-emphasis">ไม่มีรายการที่ต้องตรวจสอบ</div>
          <div
            v-for="item in attentionItems"
            :key="item.id"
            class="attention-item"
            :class="item.tone === 'error' ? 'attention-item--error' : 'attention-item--warn'"
          >
            <div class="min-width-0">
              <div class="text-body-2 font-weight-bold attention-item__title">{{ item.title }}</div>
              <div class="text-caption text-medium-emphasis">{{ item.reason }}</div>
            </div>
            <v-btn
              size="small"
              variant="tonal"
              :color="item.tone"
              class="text-none flex-shrink-0"
              @click="router.push(`/documents/${item.id}/review`)"
            >
              ตรวจสอบ
            </v-btn>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-card border rounded="lg">
      <div class="d-flex align-center pa-6 pb-4">
        <v-icon icon="mdi-history" class="me-2" />
        <span class="text-h6 font-weight-bold flex-grow-1">รายการกฎหมายที่เพิ่งนำเข้าล่าสุด</span>
        <v-btn
          variant="text"
          color="admin-primary"
          append-icon="mdi-arrow-right"
          class="text-none"
          @click="router.push('/admin/reports')"
        >
          ดูทั้งหมด
        </v-btn>
      </div>
      <v-divider />
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" />
      <v-table class="recent-table">
        <thead>
          <tr>
            <th>ชื่อกฎหมาย</th>
            <th>สถานะ</th>
            <th>ความซับซ้อน</th>
            <th>การดำเนินการ</th>
          </tr>
        </thead>
        <tbody>
          <template v-if="loading">
            <tr v-for="n in 3" :key="n">
              <td><v-skeleton-loader type="text" width="260" /></td>
              <td><v-skeleton-loader type="chip" width="80" /></td>
              <td><v-skeleton-loader type="text" width="90" /></td>
              <td><v-skeleton-loader type="text" width="60" /></td>
            </tr>
          </template>
          <template v-else-if="recentDocs.length === 0">
            <tr>
              <td colspan="4" class="text-center pa-6 text-medium-emphasis">ยังไม่มีเอกสาร</td>
            </tr>
          </template>
          <template v-else>
            <tr
              v-for="doc in recentDocs"
              :key="doc.id"
              class="recent-row"
              @click="router.push(`/documents/${doc.id}/review`)"
            >
              <td class="py-4 text-body-2 font-weight-medium recent-row__cell">
                <span class="recent-row__title">{{ doc.title }}</span>
              </td>
              <td>
                <v-chip size="small" :color="STATUS_CHIP[doc.status] ?? 'default'" rounded="pill" variant="tonal">
                  {{ doc.meta_status || doc.status }}
                </v-chip>
              </td>
              <td class="text-body-2">{{ complexityLabel(doc.section_count) }}</td>
              <td>
                <v-btn
                  variant="text"
                  size="small"
                  color="admin-primary"
                  :prepend-icon="isPublished(doc.status) ? 'mdi-graph-outline' : 'mdi-file-search-outline'"
                  class="text-none"
                  @click.stop="openAction(doc)"
                >
                  {{ isPublished(doc.status) ? 'เปิดดูผัง' : 'ตรวจสอบเอกสาร' }}
                </v-btn>
              </td>
            </tr>
          </template>
        </tbody>
      </v-table>
    </v-card>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchReportSummary } from '../../api/client';
import type { ReportDocument, ReportSummary } from '../../types/document';
import ReportStatCard from '../../components/admin/ReportStatCard.vue';
import AppShell from '../../components/shared/AppShell.vue';

const router = useRouter();

const STATUS_CHIP: Record<string, string> = {
  active: 'success',
  มีผลบังคับใช้: 'success',
  มีผลใช้บังคับ: 'success',
  ใช้บังคับ: 'success',
  บังคับใช้: 'success',
  done: 'success',
  exported: 'success',
  ingested: 'success',
  processing: 'warning',
  queued: 'info',
  ingesting: 'info',
  failed: 'error',
};

const PUBLISHED = ['exported', 'ingested'];
const PROCESSING = ['queued', 'processing', 'ingesting'];

const loading = ref(false);
const summary = ref<ReportSummary>({
  totals: { all: 0, published: 0, processing: 0, failed: 0, esign: 0, relations: 0, legacy_links: 0 },
  by_type: [],
  by_group: [],
  by_agency: [],
  by_year: [],
  documents: [],
});

onMounted(async () => {
  loading.value = true;
  try {
    summary.value = await fetchReportSummary();
  } finally {
    loading.value = false;
  }
});

function isPublished(status: string): boolean {
  return PUBLISHED.includes(status);
}

function complexityLabel(sectionCount: number | null): string {
  if (sectionCount === null || sectionCount <= 0) {
    return '-';
  }

  const level = sectionCount >= 10 ? 'สูง' : 'ต่ำ';
  return `${level} (${sectionCount} มาตรา)`;
}

function openAction(doc: ReportDocument): void {
  router.push(isPublished(doc.status) ? `/documents/${doc.id}/relations` : `/documents/${doc.id}/review`);
}

const typeBars = computed(() => {
  const max = Math.max(1, ...summary.value.by_type.map((b) => b.count));
  return summary.value.by_type.slice(0, 6).map((b) => ({
    key: b.key,
    label: b.label,
    count: b.count,
    pct: Math.round((b.count / max) * 100),
  }));
});

const attentionItems = computed(() =>
  summary.value.documents
    .filter((d) => d.status === 'failed' || PROCESSING.includes(d.status))
    .slice(0, 5)
    .map((d) => ({
      id: d.id,
      title: d.title,
      tone: d.status === 'failed' ? 'error' : 'warning',
      reason: d.status === 'failed' ? 'ประมวลผลไม่สำเร็จ ต้องตรวจสอบ' : 'อยู่ระหว่างประมวลผล/รอยืนยัน',
    })),
);

const recentDocs = computed(() => summary.value.documents.slice(0, 5));
</script>

<style scoped>
.dashboard-panel {
  min-height: 260px;
}

.type-bar-count {
  color: #2563eb;
  flex-shrink: 0;
}

.type-bar-track {
  height: 12px;
  background: #eef2f7;
  border-radius: 999px;
  overflow: hidden;
}

.type-bar-fill {
  height: 100%;
  background: #2563eb;
  border-radius: 999px;
}

.attention-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 8px;
  margin-bottom: 10px;
}

.attention-item--error {
  background: #fef2f2;
}

.attention-item--warn {
  background: #fffbeb;
}

.attention-item__title {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.recent-table :deep(thead th) {
  color: rgba(var(--v-theme-secondary), 0.6) !important;
  font-size: 0.75rem !important;
  font-weight: 600 !important;
  letter-spacing: 0.05em;
}

.recent-row {
  cursor: pointer;
}

.recent-row:hover {
  background: rgba(0, 0, 0, 0.03);
}

.recent-row__cell {
  max-width: 360px;
}

.recent-row__title {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.min-width-0 {
  min-width: 0;
}
</style>
