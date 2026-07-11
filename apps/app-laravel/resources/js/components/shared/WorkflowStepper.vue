<template>
  <v-stepper :model-value="step" alt-labels flat bg-color="white" class="wf-stepper">
    <v-stepper-header>
      <template v-for="(label, i) in WORKFLOW_STEPS" :key="i">
        <v-stepper-item
          :value="i + 1"
          :title="label"
          :complete="i + 1 < step"
          color="admin-primary"
          complete-icon="mdi-check"
        />
        <v-divider v-if="i < WORKFLOW_STEPS.length - 1" />
      </template>
    </v-stepper-header>
  </v-stepper>
</template>

<script setup lang="ts">
import { WORKFLOW_STEPS } from '../../constants/workflowSteps';

defineProps<{ step: number }>();
</script>

<style scoped>
.wf-stepper {
  border-bottom: 1px solid #e2e8f0;
  margin-bottom: 16px;
}

/* strip default v-stepper-header box-shadow */
.wf-stepper :deep(.v-stepper-header) {
  box-shadow: none;
}
</style>
