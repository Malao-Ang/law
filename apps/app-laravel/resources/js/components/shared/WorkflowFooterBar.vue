<template>
  <div class="wf-footer">
    <v-btn
      variant="outlined"
      prepend-icon="mdi-arrow-left"
      class="wf-footer__back"
      :disabled="backDisabled"
      @click="emit('back')"
    >{{ backLabel }}</v-btn>

    <span class="wf-footer__counter">
      <strong>{{ steps[step - 1] }}</strong>&nbsp;·&nbsp;{{ step }} จาก {{ steps.length }}
    </span>

    <v-btn
      v-if="!nextHidden"
      color="admin-primary"
      append-icon="mdi-arrow-right"
      :disabled="nextDisabled"
      :loading="nextLoading"
      @click="emit('next')"
    >{{ nextLabel }}</v-btn>
    <!-- placeholder keeps space-between layout when next is hidden -->
    <div v-else style="width:120px" />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { WORKFLOW_STEPS } from '../../constants/workflowSteps';

const HISTORICAL_STEPS = [
  'ตัวอย่าง',
  'ข้อมูล',
  'เอกสารที่เกี่ยวข้อง',
  'กำหนดสิทธิ์',
  'เผยแพร่',
] as const;

const props = withDefaults(defineProps<{
  step: number;
  variant?: 'default' | 'historical';
  backLabel?: string;
  nextLabel?: string;
  backDisabled?: boolean;
  nextDisabled?: boolean;
  nextLoading?: boolean;
  nextHidden?: boolean;
}>(), {
  variant: 'default',
  backLabel: 'ย้อนกลับ',
  nextLabel: 'ถัดไป',
  backDisabled: false,
  nextDisabled: false,
  nextLoading: false,
  nextHidden: false,
});

const steps = computed(() => props.variant === 'historical' ? HISTORICAL_STEPS : WORKFLOW_STEPS);

const emit = defineEmits<{ back: []; next: [] }>();
</script>

<style scoped>
.wf-footer {
  position: fixed;
  bottom: 0;
  left: var(--v-layout-left, 0px);
  right: var(--v-layout-right, 0px);
  z-index: 50;
  background: #fff;
  border-top: 1.5px solid #e2e8f0;
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  box-shadow: 0 -2px 12px rgba(26, 54, 115, 0.07);
}

.wf-footer__counter {
  font-size: 12px;
  color: #94a3b8;
  text-align: center;
}
.wf-footer__counter strong {
  color: #475569;
  font-weight: 600;
}
</style>
