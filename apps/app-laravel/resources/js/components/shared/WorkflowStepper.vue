<template>
  <div class="wf-stepper-wrap">
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
    <p v-if="description" class="wf-stepper__desc">{{ description }}</p>
  </div>
</template>

<script setup lang="ts">
import { WORKFLOW_STEPS } from '../../constants/workflowSteps';

defineProps<{ step: number; description?: string }>();
</script>

<style scoped>
.wf-stepper-wrap {
  border-bottom: 1px solid #e2e8f0;
  margin-bottom: 16px;
}

.wf-stepper {
  background: transparent;
}

/* strip default v-stepper-header box-shadow */
.wf-stepper :deep(.v-stepper-header) {
  box-shadow: none;
}

.wf-stepper__desc {
  margin: -4px 0 12px;
  padding: 0 16px;
  text-align: center;
  font-size: 13px;
  color: #64748b;
}
</style>
