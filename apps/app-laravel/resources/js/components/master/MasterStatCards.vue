<template>
  <div class="master-stat-cards">
    <AdminStatCard
      icon="mdi-link-variant"
      icon-color="#1d4ed8"
      icon-bg="#dbeafe"
      :number="stats.total"
      :label="labels.total"
    />
    <AdminStatCard
      icon="mdi-check-circle-outline"
      icon-color="#047857"
      icon-bg="#d1fae5"
      :number="stats.active"
      :label="labels.active"
    />
    <AdminStatCard
      icon="mdi-power"
      icon-color="#4b5563"
      icon-bg="#e5e7eb"
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
</style>
