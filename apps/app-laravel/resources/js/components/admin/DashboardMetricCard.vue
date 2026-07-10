<!-- apps/app-laravel/resources/js/components/admin/DashboardMetricCard.vue -->
<template>
  <v-card
    class="metric-card"
    :class="{ 'metric-card--filled': filled }"
    :color="filled ? 'elaw-navy' : undefined"
    :border="!filled"
    rounded="lg"
    height="132"
  >
    <v-card-text class="metric-card__body">
      <span class="metric-card__label text-caption font-weight-bold" :style="labelStyle">
        {{ label }}
      </span>
      <span class="metric-card__value" :style="valueStyle">{{ value }}</span>
      <span class="metric-card__footnote text-caption" :class="filled ? 'text-white' : 'text-medium-emphasis'">
        {{ footnote }}
      </span>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
  label: string;
  value: string;
  footnote: string;
  accent: string;
  filled?: boolean;
}>();

const labelStyle = computed(() =>
  props.filled ? { color: '#fff' } : { color: `rgb(var(--v-theme-${props.accent}))` },
);
const valueStyle = computed(() =>
  props.filled ? { color: '#fff' } : { color: 'rgb(var(--v-theme-secondary))' },
);
</script>

<style scoped>
.metric-card__body {
  display: flex;
  flex-direction: column;
  gap: 8px;
  height: 100%;
  justify-content: center;
  padding: 25px;
}

.metric-card__label {
  letter-spacing: 0.02em;
}

.metric-card__value {
  font-size: 2.25rem;
  font-weight: 800;
  line-height: 1.1;
}

.metric-card--filled {
  box-shadow: 0 18px 40px rgba(26, 46, 82, 0.32) !important;
}
</style>
