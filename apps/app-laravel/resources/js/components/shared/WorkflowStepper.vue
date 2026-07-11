<template>
  <div class="wf-stepper">
    <div class="wf-stepper__track">
      <template v-for="(label, i) in WORKFLOW_STEPS" :key="i">
        <div
          v-if="i > 0"
          class="wf-stepper__conn"
          :class="{ 'wf-stepper__conn--done': i + 1 <= step }"
        />
        <div class="wf-stepper__item">
          <div
            class="wf-stepper__dot"
            :class="{
              'wf-stepper__dot--done': i + 1 < step,
              'wf-stepper__dot--current': i + 1 === step,
            }"
          >
            <v-icon v-if="i + 1 < step" icon="mdi-check" size="12" />
            <span v-else>{{ i + 1 }}</span>
          </div>
          <div
            class="wf-stepper__label"
            :class="{
              'wf-stepper__label--done': i + 1 < step,
              'wf-stepper__label--current': i + 1 === step,
            }"
          >{{ label }}</div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { WORKFLOW_STEPS } from '../../constants/workflowSteps';

defineProps<{ step: number }>();
</script>

<style scoped>
.wf-stepper {
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  height: 56px;
  display: flex;
  align-items: flex-start;
  padding: 8px 24px 0;
  margin-bottom: 16px;
}

.wf-stepper__track {
  display: flex;
  align-items: flex-start;
  width: 100%;
  max-width: 640px;
  margin: 0 auto;
}

.wf-stepper__item {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex-shrink: 0;
}

.wf-stepper__conn {
  flex: 1;
  height: 2px;
  background: #e2e8f0;
  margin-top: 11px;
  transition: background 0.2s;
}
.wf-stepper__conn--done { background: #1a3673; }

.wf-stepper__dot {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 2px solid #e2e8f0;
  background: #f8fafc;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  font-weight: 700;
  color: #cbd5e1;
  flex-shrink: 0;
  transition: all 0.2s;
}
.wf-stepper__dot--done {
  background: #1a3673;
  border-color: #1a3673;
  color: #fff;
}
.wf-stepper__dot--current {
  background: #fff;
  border-color: #b45309;
  border-width: 2.5px;
  color: #b45309;
  box-shadow: 0 0 0 4px rgba(180, 83, 9, 0.12);
}

.wf-stepper__label {
  font-size: 9px;
  color: #94a3b8;
  text-align: center;
  margin-top: 4px;
  white-space: nowrap;
  line-height: 1.2;
}
.wf-stepper__label--done { color: #1a3673; font-weight: 600; }
.wf-stepper__label--current { color: #b45309; font-weight: 700; }
</style>
