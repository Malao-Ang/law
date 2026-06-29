<template>
  <div class="admin-stat-card">
    <div class="admin-stat-card__icon" :style="{ background: iconBg }">
      <span class="mdi" :class="icon" :style="{ color: iconColor }"></span>
    </div>
    <div>
      <div class="admin-stat-card__number">{{ number.toLocaleString('th-TH') }}</div>
      <div class="admin-stat-card__label">{{ label }}</div>
    </div>
  </div>
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
