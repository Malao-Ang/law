<template>
  <div v-if="count > 0" class="block-selection-bar">
    <span class="block-selection-bar__label">เลือก {{ count }} บล็อก</span>
    <v-btn
      v-if="count >= 2"
      size="small"
      variant="tonal"
      prepend-icon="mdi-merge"
      :loading="busy"
      @click="$emit('merge')"
    >รวมบล็อก</v-btn>
    <v-btn
      size="small"
      variant="tonal"
      prepend-icon="mdi-plus-circle-outline"
      @click="$emit('create-after')"
    >เพิ่มบล็อกใหม่</v-btn>
    <v-btn
      size="small"
      variant="tonal"
      color="error"
      prepend-icon="mdi-delete-outline"
      :loading="busy"
      @click="$emit('delete')"
    >ลบที่เลือก</v-btn>
    <v-btn size="small" variant="text" @click="$emit('clear')">ยกเลิก</v-btn>
  </div>
</template>

<script setup lang="ts">
defineProps<{ count: number; busy?: boolean }>();
defineEmits<{
  merge: [];
  'create-after': [];
  delete: [];
  clear: [];
}>();
</script>

<style scoped>
.block-selection-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 16px;
  background: rgba(var(--v-theme-primary), 0.08);
  border-bottom: 1px solid rgba(var(--v-theme-primary), 0.2);
  font-size: 13px;
  flex-wrap: wrap;
}
.block-selection-bar__label {
  font-weight: 600;
  color: rgb(var(--v-theme-primary));
  margin-right: 4px;
}
</style>
