<template>
  <div class="master-toggle">
    <v-switch
      :key="`${item.code}-${epoch ?? 0}`"
      :model-value="item.is_active"
      :disabled="busy"
      :loading="busy"
      :aria-label="item.is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน'"
      color="success"
      density="compact"
      hide-details
      inset
      @update:model-value="$emit('toggle', Boolean($event))"
    />
  </div>
</template>

<script setup lang="ts">
import type { MasterItem, MasterKind } from '../../types/masterData';

defineProps<{
  item: MasterItem;
  kind: MasterKind;
  busy?: boolean;
  epoch?: number;
}>();

defineEmits<{
  toggle: [next: boolean];
}>();
</script>

<style scoped>
.master-toggle {
  align-items: center;
  display: flex;
  flex: 0 0 54px;
  width: 54px;
  justify-content: flex-end;
}
</style>
