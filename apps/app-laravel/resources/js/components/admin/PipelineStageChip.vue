<!-- apps/app-laravel/resources/js/components/admin/PipelineStageChip.vue -->
<template>
  <v-chip :color="def.color" size="small" variant="tonal" rounded="pill">
    <v-icon start :icon="def.icon" size="14" />
    {{ def.label }}
  </v-chip>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { STAGE_MAP, type StageKey } from '../../data/documentPipeline';

const props = defineProps<{ stage: StageKey }>();
const def = computed(() => STAGE_MAP[props.stage]);
</script>
