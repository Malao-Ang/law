<template>
  <div class="wf-stepper-wrap">
    <template v-if="isExpanded">
      <div class="wf-stepper__header-row">
        <v-stepper :model-value="step" alt-labels flat bg-color="white" class="wf-stepper">
          <v-stepper-header>
            <template v-for="(label, i) in steps" :key="i">
              <v-stepper-item
                :value="i + 1"
                :title="label"
                :complete="i + 1 < step"
                color="admin-primary"
                complete-icon="mdi-check"
              />
              <v-divider v-if="i < steps.length - 1" />
            </template>
          </v-stepper-header>
        </v-stepper>
        <button type="button" class="wf-collapse-toggle wf-collapse-toggle--expanded" @click="isExpanded = false">▲</button>
      </div>
      <p v-if="description" class="wf-stepper__desc">{{ description }}</p>
    </template>
    <div
      v-else
      class="wf-stepper-collapsed"
      role="button"
      tabindex="0"
      @click="isExpanded = true"
      @keydown.enter.prevent="isExpanded = true"
      @keydown.space.prevent="isExpanded = true"
    >
      <div class="wf-progress-bar">
        <div class="wf-progress-fill" :style="{ width: `${progressPercent}%` }"></div>
      </div>
      <button type="button" class="wf-collapse-toggle">แสดงขั้นตอน ▼</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { WORKFLOW_STEPS } from '../../constants/workflowSteps';

const HISTORICAL_STEPS = [
  'ตัวอย่าง',
  'ข้อมูล',
  'เอกสารที่เกี่ยวข้อง',
  'กำหนดสิทธิ์',
  'เผยแพร่',
] as const;

const props = defineProps<{ step: number; description?: string; variant?: 'default' | 'historical' }>();

const steps = computed(() => props.variant === 'historical' ? HISTORICAL_STEPS : WORKFLOW_STEPS);

const isExpanded = ref(true);
const progressPercent = computed(() => {
  if (steps.value.length <= 1) {
    return 100;
  }

  return Math.max(0, Math.min(100, ((props.step - 1) / (steps.value.length - 1)) * 100));
});
</script>

<style scoped>
.wf-stepper-wrap {
  border-bottom: 1px solid #e2e8f0;
  margin-bottom: 16px;
}

.wf-stepper {
  background: transparent;
  flex: 1;
}

/* strip default v-stepper-header box-shadow */
.wf-stepper :deep(.v-stepper-header) {
  box-shadow: none;
}

.wf-stepper__header-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.wf-stepper__desc {
  margin: -4px 0 12px;
  padding: 0 16px;
  text-align: center;
  font-size: 13px;
  color: #64748b;
}

.wf-stepper-collapsed {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 16px;
  cursor: pointer;
  border-bottom: 1px solid #e2e8f0;
}

.wf-progress-bar {
  flex: 1;
  height: 4px;
  background: #e2e8f0;
  border-radius: 2px;
  overflow: hidden;
}

.wf-progress-fill {
  height: 100%;
  background: #1a56db;
  border-radius: 2px;
  transition: width 0.2s ease;
}

.wf-collapse-toggle {
  font-size: 12px;
  color: #64748b;
  white-space: nowrap;
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;
}

.wf-collapse-toggle--expanded {
  margin: 16px 16px 0 0;
}
</style>
