<template>
  <div class="master-stat-cards">
    <AdminStatCard
      icon="mdi-link-variant"
      icon-color="#1e3a8a"
      icon-bg="#eff6ff"
      :number="stats.total"
      :label="labels.total"
    />
    <AdminStatCard
      icon="mdi-check-circle-outline"
      icon-color="#10b981"
      icon-bg="#ecfdf5"
      :number="stats.active"
      :label="labels.active"
    />
    <AdminStatCard
      icon="mdi-power"
      icon-color="#64748b"
      icon-bg="#f1f5f9"
      :number="stats.inactive"
      :label="labels.inactive"
    />
  </div>
</template>

<script setup lang="ts">
import AdminStatCard from '../admin/AdminStatCard.vue';
import type { MasterStats } from '../../types/masterData';

defineProps<{
  stats: MasterStats;
  labels: { total: string; active: string; inactive: string };
}>();
</script>

<style scoped>
.master-stat-cards {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

@media (max-width: 900px) {
  .master-stat-cards {
    grid-template-columns: 1fr;
  }
}
.master-stat-cards :deep(.admin-stat-card),
.master-stat-cards :deep(.admin-stat-card__body) { min-height: 86px; }
.master-stat-cards :deep(.admin-stat-card__body) { padding: 18px 22px; }
.master-stat-cards :deep(.admin-stat-card__content) { display: flex; flex-direction: column; }
.master-stat-cards :deep(.admin-stat-card__label) { order: -1; color: #64748b !important; opacity: 1; font-size: 13px !important; }
.master-stat-cards :deep(.admin-stat-card__number) { color: #1e293b; font-size: 24px !important; line-height: 1.4; }
</style>
