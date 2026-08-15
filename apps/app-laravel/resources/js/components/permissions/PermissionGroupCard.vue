<template>
  <v-card flat border rounded="lg" class="group-card">
    <div class="group-card__icon">
      <v-icon icon="mdi-account-key-outline" color="white" size="18" />
    </div>
    <div class="group-card__body">
      <div class="d-flex align-center justify-space-between ga-3">
        <div class="min-w-0">
          <div class="text-subtitle-2 font-weight-bold text-truncate">{{ group.name }}</div>
          <div v-if="group.description" class="text-caption text-medium-emphasis text-truncate">{{ group.description }}</div>
        </div>
        <div class="d-flex align-center ga-1">
          <v-btn icon="mdi-eye-outline" variant="text" size="small" @click="$emit('detail', group.id)" />
          <v-btn
            v-if="removable"
            icon="mdi-close"
            variant="text"
            size="small"
            color="error"
            @click="$emit('remove', group.id)"
          />
        </div>
      </div>

      <div class="d-flex flex-wrap ga-2 mt-3">
        <v-chip size="x-small" color="primary" variant="tonal">{{ group.counts.units }} หน่วยงาน</v-chip>
        <v-chip size="x-small" color="secondary" variant="tonal">{{ group.counts.users }} บุคคล</v-chip>
      </div>
    </div>
  </v-card>
</template>

<script setup lang="ts">
import type { PermissionGroup } from '../../types/permission';

defineProps<{
  group: PermissionGroup;
  removable?: boolean;
}>();

defineEmits<{
  detail: [groupId: string];
  remove: [groupId: string];
}>();
</script>

<style scoped>
.group-card {
  align-items: flex-start;
  display: flex;
  gap: 14px;
  padding: 14px;
}

.group-card__icon {
  align-items: center;
  background: linear-gradient(180deg, #274690 0%, #1b2f63 100%);
  border-radius: 12px;
  display: flex;
  flex: 0 0 38px;
  height: 38px;
  justify-content: center;
}

.group-card__body {
  flex: 1 1 auto;
  min-width: 0;
}
</style>
