<template>
  <v-card
    rounded="lg"
    :class="['report-stat-card', filled ? 'report-stat-card--filled' : '']"
    :elevation="filled ? 4 : 1"
  >
    <div class="report-stat-card__title" :class="`tone-${tone}`">{{ title }}</div>
    <div class="report-stat-card__value">{{ value.toLocaleString('th-TH') }}</div>
    <div v-if="footnote" class="report-stat-card__footnote">{{ footnote }}</div>
  </v-card>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    title: string;
    value: number;
    footnote?: string;
    tone?: 'default' | 'danger' | 'primary';
    filled?: boolean;
  }>(),
  { footnote: '', tone: 'default', filled: false },
);
</script>

<style scoped>
.report-stat-card {
  padding: 20px 22px;
  min-height: 132px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.report-stat-card__title {
  font-size: 0.8rem;
  font-weight: 700;
}

.report-stat-card__title.tone-default {
  color: #475569;
}

.report-stat-card__title.tone-danger {
  color: #b91c1c;
}

.report-stat-card__title.tone-primary {
  color: #2563eb;
}

.report-stat-card__value {
  font-size: 2.6rem;
  font-weight: 800;
  line-height: 1.1;
  color: #0f172a;
}

.report-stat-card__footnote {
  font-size: 0.72rem;
  font-style: italic;
  color: #94a3b8;
  margin-top: auto;
}

.report-stat-card--filled {
  background: #1e2a52;
}

.report-stat-card--filled .report-stat-card__title {
  color: #cbd5e1;
}

.report-stat-card--filled .report-stat-card__value {
  color: #fff;
}

.report-stat-card--filled .report-stat-card__footnote {
  color: #94a3b8;
}
</style>
