<template>
  <v-card hover link @click="$emit('click')">
    <v-card-text class="pb-0">
      <div class="d-flex align-center ga-2">
        <v-chip size="x-small" :color="badgeColor(docType)">{{ typeLabel }}</v-chip>
        <span v-if="docNumber" class="text-caption text-medium-emphasis">{{ docNumber }}</span>
      </div>
    </v-card-text>
    <v-card-title class="text-body-2 text-wrap">{{ title }}</v-card-title>
    <v-card-subtitle>
      <div class="d-flex ga-3 flex-wrap align-center">
        <span v-if="department">{{ department }}</span>
        <span v-if="date">{{ date }}</span>
        <span v-if="status">{{ status }}</span>
      </div>
    </v-card-subtitle>
  </v-card>
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

function badgeColor(t: string): string {
  return ({ rabiap: 'success', prakat: 'warning', 'kho-bangkhab': 'info', 'kotmai-krung': 'purple' } as Record<string, string>)[t] ?? 'grey';
}
</script>
