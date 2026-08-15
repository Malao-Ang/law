<template>
  <v-dialog :model-value="modelValue" max-width="860" @update:model-value="$emit('update:modelValue', $event)">
    <v-card rounded="xl">
      <v-card-title class="d-flex align-center justify-space-between ga-4 px-6 pt-6">
        <div>
          <div class="text-h6 font-weight-black">รายละเอียดกลุ่มสิทธิ์</div>
          <div v-if="group" class="text-body-2 text-medium-emphasis mt-1">{{ group.name }}</div>
        </div>
        <v-btn icon="mdi-close" variant="text" @click="$emit('update:modelValue', false)" />
      </v-card-title>

      <v-card-text class="px-6 pb-6">
        <div v-if="!group" class="text-body-2 text-medium-emphasis">ไม่พบข้อมูลกลุ่มสิทธิ์</div>

        <template v-else>
          <v-alert v-if="group.description" variant="tonal" color="primary" class="mb-4">
            {{ group.description }}
          </v-alert>

          <v-row dense>
            <v-col cols="12" md="6">
              <v-card flat border rounded="lg" class="pa-4 fill-height">
                <div class="d-flex align-center justify-space-between mb-3">
                  <span class="text-subtitle-2 font-weight-bold">หน่วยงาน</span>
                  <v-chip size="x-small" color="primary" variant="tonal">{{ group.counts.units }}</v-chip>
                </div>
                <div v-if="group.units.length === 0" class="text-body-2 text-medium-emphasis">ไม่มีรายการ</div>
                <v-list v-else density="compact">
                  <v-list-item v-for="unit in group.units" :key="unit.id" :title="unit.name" />
                </v-list>
              </v-card>
            </v-col>
            <v-col cols="12" md="6">
              <v-card flat border rounded="lg" class="pa-4 fill-height">
                <div class="d-flex align-center justify-space-between mb-3">
                  <span class="text-subtitle-2 font-weight-bold">รายบุคคล</span>
                  <v-chip size="x-small" color="secondary" variant="tonal">{{ group.counts.users }}</v-chip>
                </div>
                <div v-if="group.users.length === 0" class="text-body-2 text-medium-emphasis">ไม่มีรายการ</div>
                <v-list v-else density="compact">
                  <v-list-item
                    v-for="user in group.users"
                    :key="user.id"
                    :title="user.name"
                    :subtitle="user.email || undefined"
                  />
                </v-list>
              </v-card>
            </v-col>
          </v-row>
        </template>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import type { PermissionGroup } from '../../types/permission';

defineProps<{
  modelValue: boolean;
  group: PermissionGroup | null;
}>();

defineEmits<{
  'update:modelValue': [value: boolean];
}>();
</script>
