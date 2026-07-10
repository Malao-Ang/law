<template>
  <AppShell :breadcrumbs="['เมนูหลัก', 'หน้าแรก']" title="" hide-top-bar>
    <!-- Row 1: metric cards -->
    <v-row class="mb-6">
      <v-col v-for="card in METRIC_CARDS" :key="card.label" cols="12" sm="6" md="3">
        <DashboardMetricCard
          :label="card.label"
          :value="card.value"
          :footnote="card.footnote"
          :accent="card.accent"
          :filled="card.filled"
        />
      </v-col>
    </v-row>

    <!-- Row 2: completeness + urgent -->
    <v-row class="mb-6">
      <v-col cols="12" md="6">
        <v-card border rounded="lg" height="100%">
          <v-card-text class="pa-6">
            <div class="text-h6 font-weight-bold mb-1">
              ความสมบูรณ์ของกฎหมายลำดับรอง
              <span class="text-caption text-medium-emphasis font-weight-regular">(แยกตามประเภทแม่บท)</span>
            </div>
            <div class="mt-6">
              <div v-for="row in COMPLETENESS_ROWS" :key="row.label" class="mb-5">
                <div class="d-flex justify-space-between mb-2">
                  <span class="text-body-2 font-weight-medium">{{ row.label }}</span>
                  <span class="text-body-2 font-weight-bold text-primary">{{ row.count }}</span>
                </div>
                <v-progress-linear
                  :model-value="row.linkedPct"
                  color="primary"
                  bg-color="error"
                  :bg-opacity="1"
                  height="10"
                  rounded
                />
              </div>
            </div>
            <div class="d-flex ga-4 mt-4">
              <span class="d-inline-flex align-center text-caption">
                <v-icon icon="mdi-circle" size="8" color="primary" class="me-2" />เชื่อมโยงครบ
              </span>
              <span class="d-inline-flex align-center text-caption">
                <v-icon icon="mdi-circle" size="8" color="error" class="me-2" />ยังไม่สมบูรณ์ (Gap)
              </span>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card border rounded="lg" height="100%">
          <v-card-text class="pa-6">
            <div class="text-h6 font-weight-bold mb-4">
              <v-icon icon="mdi-alert-outline" color="warning" class="me-2" />รายการที่ต้องตรวจสอบเร่งด่วน
            </div>
            <div
              v-for="alert in URGENT_ALERTS"
              :key="alert.id"
              class="urgent-row mb-3"
              :class="`urgent-row--${alert.level}`"
            >
              <div class="urgent-row__text">
                <div class="text-body-2 font-weight-bold" :class="`text-${alert.level}`">{{ alert.title }}</div>
                <div class="text-caption text-medium-emphasis">{{ alert.sub }}</div>
              </div>
              <v-btn size="small" variant="flat" color="surface" class="text-none">{{ alert.actionLabel }}</v-btn>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Row 3: recent imports -->
    <v-card border rounded="lg">
      <div class="d-flex align-center pa-6 pb-4">
        <v-icon icon="mdi-history" class="me-2" />
        <span class="text-h6 font-weight-bold flex-grow-1">รายการกฎหมายที่เพิ่งนำเข้าล่าสุด</span>
        <v-btn variant="text" color="admin-primary" append-icon="mdi-arrow-right" class="text-none">ดูทั้งหมด</v-btn>
      </div>
      <v-divider />
      <v-table class="recent-table">
        <thead>
          <tr>
            <th>ชื่อกฎหมาย</th>
            <th>สถานะการ MAPPING</th>
            <th>ความซับซ้อน</th>
            <th>การดำเนินการ</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in RECENT_IMPORTS" :key="row.id">
            <td class="text-body-2 font-weight-medium py-4">{{ row.name }}</td>
            <td>
              <v-chip size="small" :color="MAPPING_META[row.mapping].color" rounded="pill" variant="tonal">
                {{ MAPPING_META[row.mapping].label }}
              </v-chip>
            </td>
            <td class="text-body-2">{{ row.complexity }}</td>
            <td>
              <v-btn variant="text" size="small" :color="row.actionColor" :prepend-icon="row.actionIcon" class="text-none">
                {{ row.actionLabel }}
              </v-btn>
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>
  </AppShell>
</template>

<script setup lang="ts">
import AppShell from '../../components/shared/AppShell.vue';
import DashboardMetricCard from '../../components/admin/DashboardMetricCard.vue';
import {
  METRIC_CARDS,
  COMPLETENESS_ROWS,
  URGENT_ALERTS,
  RECENT_IMPORTS,
  MAPPING_META,
} from '../../data/dashboardData';
</script>

<style scoped>
.urgent-row {
  align-items: center;
  border-radius: 12px;
  display: flex;
  gap: 12px;
  padding: 16px;
}

.urgent-row__text {
  flex: 1 1 auto;
  min-width: 0;
}

.urgent-row--error {
  background: rgba(var(--v-theme-error), 0.08);
  border: 1px solid rgba(var(--v-theme-error), 0.25);
}

.urgent-row--warning {
  background: rgba(var(--v-theme-warning), 0.12);
  border: 1px solid rgba(var(--v-theme-warning), 0.35);
}

.recent-table :deep(thead th) {
  color: rgba(var(--v-theme-secondary), 0.6) !important;
  font-size: 0.75rem !important;
  font-weight: 600 !important;
  letter-spacing: 0.05em;
}
</style>
