<template>
  <AppShell :breadcrumbs="['เมนูหลัก', 'หน้าแรก']" title="" hide-top-bar show-bell>
    <v-row class="mb-6">
      <v-col v-for="card in statCards" :key="card.label" cols="12" sm="6" md="3">
        <AdminStatCard
          :icon="card.icon"
          :icon-color="card.iconColor"
          :icon-bg="card.iconBg"
          :number="card.number"
          :label="card.label"
        />
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
      <v-table class="recent-table">
        <thead>
          <tr>
            <th>ชื่อเอกสาร</th>
            <th>สถานะ</th>
            <th>วันที่นำเข้า</th>
            <th>การดำเนินการ</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="recentDocs.length === 0">
            <td colspan="4" class="text-center pa-6 text-medium-emphasis">ยังไม่มีเอกสาร</td>
          </tr>
          <tr
            v-for="doc in recentDocs"
            :key="doc.id"
            class="recent-row"
            @click="router.push(`/documents/${doc.id}/review`)"
          >
            <td class="py-4 text-body-2 font-weight-medium" style="max-width: 320px">
              <span class="recent-row__title">{{ doc.title }}</span>
            </td>
            <td>
              <v-chip size="small" :color="STATUS_CHIP[doc.status] ?? 'default'" rounded="pill" variant="tonal">
                {{ doc.status }}
              </v-chip>
            </td>
            <td class="text-body-2">{{ (doc.date ?? '').slice(0, 10) }}</td>
            <td>
              <v-btn
                variant="text"
                size="small"
                color="admin-primary"
                prepend-icon="mdi-eye-outline"
                class="text-none"
              >
                เปิดดู
              </v-btn>
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchReportSummary } from '../../api/client';
import type { ReportSummary } from '../../types/document';
import AdminStatCard from '../../components/admin/AdminStatCard.vue';
import AppShell from '../../components/shared/AppShell.vue';

const router = useRouter();

const STATUS_CHIP: Record<string, string> = {
  done: 'success',
  exported: 'success',
  ingested: 'success',
  processing: 'warning',
  queued: 'info',
  failed: 'error',
};

const summary = ref<ReportSummary>({
  totals: { all: 0, published: 0, processing: 0, failed: 0, esign: 0 },
  by_type: [],
  by_group: [],
  by_agency: [],
  by_year: [],
  documents: [],
});

onMounted(async () => {
  summary.value = await fetchReportSummary();
});

const statCards = computed(() => [
  { icon: 'mdi-file-document-multiple-outline', iconColor: '#2563eb', iconBg: '#dbeafe', number: summary.value.totals.all, label: 'เอกสารทั้งหมด' },
  { icon: 'mdi-check-circle-outline', iconColor: '#16a34a', iconBg: '#dcfce7', number: summary.value.totals.published, label: 'เผยแพร่แล้ว' },
  { icon: 'mdi-clock-outline', iconColor: '#d97706', iconBg: '#fef3c7', number: summary.value.totals.processing, label: 'รอประมวลผล' },
  { icon: 'mdi-alert-circle-outline', iconColor: '#dc2626', iconBg: '#fee2e2', number: summary.value.totals.failed, label: 'ล้มเหลว' },
]);

const recentDocs = computed(() => summary.value.documents.slice(0, 5));
</script>

<style scoped>
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

.recent-row__title {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
