<template>
  <v-card class="admin-stat-card">
    <v-card-text class="admin-stat-card__body">
      <v-avatar rounded="lg" :style="{ background: iconBg }" size="48">
        <v-icon :icon="icon" :color="iconColor" size="22" />
      </v-avatar>
      <div class="admin-stat-card__content">
        <div class="text-h5 font-weight-bold admin-stat-card__number">{{ number.toLocaleString('th-TH') }}</div>
        <div class="text-caption text-medium-emphasis admin-stat-card__label">{{ label }}</div>
      </div>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
defineProps<{
  icon: string;
  iconColor: string;
  iconBg: string;
  number: number;
  label: string;
}>();
</script>

<style scoped>
.admin-stat-card {
  width: 100%;
  min-height: 104px;
}

.admin-stat-card__body {
  display: flex;
  align-items: center;
  gap: 16px;
  height: 100%;
  min-height: 104px;
}

.admin-stat-card__content {
  min-width: 0;
}

.admin-stat-card__number,
.admin-stat-card__label {
  overflow: hidden;
  text-overflow: ellipsis;
}

.admin-stat-card__number {
  white-space: nowrap;
}

.admin-stat-card__label {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}
</style>
