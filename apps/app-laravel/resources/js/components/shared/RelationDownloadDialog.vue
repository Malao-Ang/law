<template>
  <v-dialog :model-value="modelValue" max-width="600" scrollable @update:model-value="emit('update:modelValue', $event)">
    <v-card>
      <v-card-title class="d-flex align-center ga-2">
        <v-icon icon="mdi-download-multiple" color="primary" />
        เลือกเอกสารที่ต้องการดาวน์โหลด
      </v-card-title>
      <v-divider />
      <v-card-text style="max-height: 420px; overflow-y: auto" class="pa-0">
        <v-list density="compact" select-strategy="multiple">
          <v-list-item class="border-b">
            <template #prepend>
              <v-checkbox-btn
                :model-value="selectAll"
                :indeterminate="props.selectedIds.length > 0 && props.selectedIds.length < props.items.length"
                color="primary"
                @update:model-value="onSelectAll"
              />
            </template>
            <v-list-item-title class="font-weight-bold text-body-2">
              เลือกทั้งหมด ({{ props.items.length }} เอกสาร)
            </v-list-item-title>
          </v-list-item>
          <v-list-item
            v-for="(item, idx) in props.items"
            :key="item.id"
            :class="idx === 0 ? 'bg-primary-lighten-5' : ''"
          >
            <template #prepend>
              <v-checkbox-btn
                :model-value="props.selectedIds.includes(item.id)"
                color="primary"
                @update:model-value="(val: boolean) => onToggle(item.id, val)"
              />
            </template>
            <v-list-item-title class="text-body-2">
              <span v-if="idx === 0" class="text-primary font-weight-bold">[กฎหมายหลัก] </span>
              {{ item.title }}
              <span v-if="item.version" class="text-caption text-medium-emphasis ml-1">ฉบับ {{ item.version }}</span>
            </v-list-item-title>
          </v-list-item>
        </v-list>
      </v-card-text>
      <v-divider />
      <v-card-actions class="pa-4">
        <span class="text-caption text-medium-emphasis">เลือกแล้ว {{ props.selectedIds.length }} เอกสาร</span>
        <v-spacer />
        <v-btn class="text-none" @click="emit('update:modelValue', false)">ยกเลิก</v-btn>
        <v-btn
          color="primary"
          class="text-none"
          prepend-icon="mdi-download"
          :loading="props.loading"
          :disabled="props.selectedIds.length === 0"
          @click="emit('confirm')"
        >
          ดาวน์โหลด ({{ props.selectedIds.length }})
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
  modelValue: boolean;
  items: Array<{ id: string; title: string; version?: string }>;
  selectedIds: string[];
  loading: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [boolean];
  'update:selectedIds': [string[]];
  confirm: [];
}>();

const selectAll = computed(
  () => props.items.length > 0 && props.selectedIds.length === props.items.length,
);

function onSelectAll(val: boolean): void {
  emit('update:selectedIds', val ? props.items.map((i) => i.id) : []);
}

function onToggle(id: string, val: boolean): void {
  if (val) {
    emit('update:selectedIds', [...props.selectedIds, id]);
  } else {
    emit('update:selectedIds', props.selectedIds.filter((i) => i !== id));
  }
}
</script>
