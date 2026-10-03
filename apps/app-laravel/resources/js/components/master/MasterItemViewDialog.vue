<template>
  <v-dialog :model-value="modelValue" max-width="620" @update:model-value="$emit('update:modelValue', Boolean($event))">
    <v-card rounded="xl" class="master-view">
      <div class="master-view__header">
        <div>
          <h2 class="master-view__title">{{ item?.name || title }}</h2>
          <p class="master-view__subtitle">{{ item?.code }}</p>
        </div>
        <v-btn icon="mdi-close" variant="text" size="small" @click="$emit('update:modelValue', false)" />
      </div>

      <v-card-text v-if="item" class="master-view__body">
        <div class="master-view__grid">
          <div class="master-view__field">
            <span>{{ fieldLabels.code }}</span>
            <strong>{{ item.code }}</strong>
          </div>
          <div class="master-view__field">
            <span>{{ fieldLabels.name }}</span>
            <strong>{{ item.name }}</strong>
          </div>
          <div class="master-view__field master-view__field--wide">
            <span>คำอธิบาย</span>
            <strong>{{ item.description || '-' }}</strong>
          </div>
          <div class="master-view__field">
            <span>สถานะ</span>
            <v-chip size="small" :color="item.is_active ? 'success' : 'grey'" variant="tonal">
              {{ item.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
            </v-chip>
          </div>
          <div class="master-view__field">
            <span>จำนวนเอกสารที่ใช้งาน</span>
            <strong>{{ (item.usage_count ?? 0).toLocaleString('th-TH') }}</strong>
          </div>
        </div>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import type { MasterItem } from '../../types/masterData';

defineProps<{
  modelValue: boolean;
  title: string;
  item?: MasterItem | null;
  fieldLabels: { code: string; name: string };
}>();

defineEmits<{
  'update:modelValue': [value: boolean];
}>();
</script>

<style scoped>
.master-view {
  overflow: hidden;
}

.master-view__header {
  align-items: flex-start;
  display: flex;
  justify-content: space-between;
  padding: 20px 22px 12px;
}

.master-view__title {
  color: #1f2933;
  font-size: 18px;
  font-weight: 800;
  line-height: 1.35;
  margin: 0;
}

.master-view__subtitle {
  color: #667085;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  font-size: 13px;
  margin: 4px 0 0;
}

.master-view__body {
  padding: 8px 22px 22px;
}

.master-view__grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.master-view__field {
  background: #f9fafb;
  border: 1px solid #eaecf0;
  border-radius: 10px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  padding: 12px;
}

.master-view__field--wide {
  grid-column: 1 / -1;
}

.master-view__field span {
  color: #667085;
  font-size: 12px;
}

.master-view__field strong {
  color: #1f2933;
  font-size: 14px;
  overflow-wrap: anywhere;
}

@media (max-width: 640px) {
  .master-view__grid {
    grid-template-columns: 1fr;
  }
}
</style>
