<template>
  <v-tooltip v-model="tipOpen" location="top">
    <template #activator="{ props: tip }">
      <div
        v-bind="tip"
        class="master-toggle"
        :class="{ 'master-toggle--locked': locked }"
        @click.capture="onClick"
        @focusin="onFocusIn"
        @focusout="onFocusOut"
      >
        <span class="master-toggle__lock-slot">
          <v-icon v-if="locked" icon="mdi-lock" size="14" class="master-toggle__lock" />
        </span>
        <v-switch
          :key="`${item.code}-${epoch ?? 0}`"
          :model-value="locked ? true : item.is_active"
          :readonly="locked"
          :disabled="busy"
          :loading="busy"
          :aria-disabled="locked ? 'true' : undefined"
          :aria-label="ariaLabel"
          color="success"
          density="compact"
          hide-details
          inset
          @update:model-value="onUpdate"
        />
      </div>
    </template>
    <div class="font-weight-bold">{{ text.reason }}</div>
    <div v-if="text.hint">{{ text.hint }}</div>
  </v-tooltip>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { isLocked, lockAriaLabel, toggleText } from './masterLock';
import type { MasterItem, MasterKind } from '../../types/masterData';

const props = defineProps<{
  item: MasterItem;
  kind: MasterKind;
  system?: boolean;
  busy?: boolean;
  epoch?: number;
}>();

const emit = defineEmits<{
  toggle: [next: boolean];
}>();

const TAP_TOOLTIP_MS = 3000;

const tipOpen = ref(false);
let tapTimer: ReturnType<typeof setTimeout> | null = null;

const lockItem = computed(() => ({
  is_system: props.item.is_system || props.system === true,
  is_active: props.item.is_active,
  usage_count: props.item.usage_count,
}));
const locked = computed(() => isLocked(lockItem.value, props.kind));
const text = computed(() => toggleText(lockItem.value, props.kind));
const ariaLabel = computed(() => (locked.value ? lockAriaLabel(lockItem.value, props.kind) : undefined));

function clearTapTimer(): void {
  if (tapTimer) clearTimeout(tapTimer);
  tapTimer = null;
}

function onClick(event: MouseEvent): void {
  if (!locked.value) return;
  event.preventDefault();
  event.stopPropagation();
  tipOpen.value = true;
  clearTapTimer();
  tapTimer = setTimeout(() => {
    tipOpen.value = false;
    tapTimer = null;
  }, TAP_TOOLTIP_MS);
}

function onFocusIn(): void {
  if (locked.value) tipOpen.value = true;
}

function onFocusOut(): void {
  if (!locked.value) return;
  clearTapTimer();
  tipOpen.value = false;
}

function onUpdate(value: unknown): void {
  if (locked.value) return;
  emit('toggle', Boolean(value));
}

onBeforeUnmount(clearTapTimer);
</script>

<style scoped>
.master-toggle {
  align-items: center;
  display: flex;
  gap: 4px;
}

.master-toggle__lock-slot {
  align-items: center;
  display: inline-flex;
  flex: 0 0 18px;
  justify-content: center;
  width: 18px;
}

.master-toggle__lock {
  color: #475467;
  cursor: not-allowed;
}

.master-toggle--locked :deep(.v-switch) {
  opacity: 0.55;
}

.master-toggle--locked :deep(.v-selection-control),
.master-toggle--locked :deep(input) {
  cursor: not-allowed;
}
</style>
