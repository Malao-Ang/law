<template>
  <div class="compose-footer-bar">
    <span v-if="saveLabel" class="compose-footer-bar__status">{{ saveLabel }}</span>
    <div class="compose-footer-bar__actions">
      <v-btn
        variant="tonal"
        prepend-icon="mdi-content-save-outline"
        :loading="saving"
        :disabled="!hasUnsaved"
        @click="$emit('save')"
      >บันทึกเอกสาร</v-btn>
      <v-btn
        color="primary"
        prepend-icon="mdi-eye-outline"
        @click="$emit('preview')"
      >ดูตัวอย่างเอกสาร</v-btn>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  saving?: boolean;
  hasUnsaved?: boolean;
  saveLabel?: string;
  previewHref?: string;
}>();
defineEmits<{ save: []; preview: [] }>();
</script>

<style scoped>
.compose-footer-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 20px;
  border-top: 1px solid rgba(0, 0, 0, 0.12);
  background: rgb(var(--v-theme-surface));
  box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.06);
  position: sticky;
  bottom: 0;
  z-index: 10;
}
.compose-footer-bar__status {
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.6);
}
.compose-footer-bar__actions {
  display: flex;
  gap: 10px;
}
</style>
