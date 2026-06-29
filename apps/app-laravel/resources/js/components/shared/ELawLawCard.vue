<template>
  <article class="elaw-law-card" role="button" tabindex="0" @click="$emit('click')">
    <div class="elaw-law-card__top">
      <span class="elaw-badge" :class="badgeClass">{{ typeLabel }}</span>
      <span class="elaw-law-card__number">{{ docNumber }}</span>
    </div>
    <h3 class="elaw-law-card__title">{{ title }}</h3>
    <div class="elaw-law-card__meta">
      <span v-if="department">{{ department }}</span>
      <span v-if="date">{{ date }}</span>
      <span v-if="status" class="elaw-law-card__status" :class="statusClass">{{ status }}</span>
    </div>
  </article>
</template>

<script setup lang="ts">
import { computed } from 'vue';

type DocType = 'rabiap' | 'kho-bangkhab' | 'prakat' | 'kotmai-krung' | 'other';

const props = defineProps<{
  title: string;
  docType: DocType;
  docNumber?: string;
  department?: string;
  date?: string;
  status?: string;
}>();

defineEmits<{ click: [] }>();

const typeLabels: Record<DocType, string> = {
  rabiap: 'ระเบียบ',
  'kho-bangkhab': 'ข้อบังคับ',
  prakat: 'ประกาศ',
  'kotmai-krung': 'กฎหมายหลัก',
  other: 'อื่น ๆ',
};

const typeLabel = computed(() => typeLabels[props.docType] ?? 'เอกสาร');
const badgeClass = computed(() => `elaw-badge--${props.docType}`);
const statusClass = computed(() => {
  if (props.status === 'มีผลบังคับใช้') return 'elaw-law-card__status--active';
  if (props.status === 'ยกเลิก') return 'elaw-law-card__status--cancelled';
  return '';
});
</script>

<style scoped>
.elaw-law-card__top {
  display: flex;
  align-items: center;
  gap: 8px;
}

.elaw-law-card__number {
  font-size: 11px;
  color: var(--elaw-muted);
}

.elaw-law-card__status {
  font-size: 11px;
  font-weight: 600;
  padding: 1px 7px;
  border-radius: 10px;
}

.elaw-law-card__status--active {
  color: #15803d;
  background: #dcfce7;
}

.elaw-law-card__status--cancelled {
  color: #b91c1c;
  background: #fee2e2;
}
</style>
