<template>
  <v-dialog :model-value="modelValue" max-width="1180" @update:model-value="$emit('update:modelValue', $event)">
    <v-card rounded="xl">
      <v-card-title class="d-flex align-center justify-space-between ga-4 px-6 pt-6">
        <div>
          <div class="text-h5 font-weight-black">เพิ่มกลุ่มสิทธิ์ผู้ใช้ใหม่</div>
          <div class="text-body-2 text-medium-emphasis mt-1">สร้างเสร็จจะถูกเลือกอัตโนมัติโดยไม่ออกจากขั้นตอนนำเข้า</div>
        </div>
        <v-btn icon="mdi-close" variant="text" @click="$emit('update:modelValue', false)" />
      </v-card-title>

      <v-card-text class="px-6 pb-4">
        <v-card flat border rounded="xl" class="pa-5 mb-4">
          <div class="text-subtitle-1 font-weight-bold mb-4">ข้อมูลกลุ่ม</div>
          <v-row dense>
            <v-col cols="12">
              <v-text-field v-model="name" label="ชื่อกลุ่ม" variant="outlined" />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="description" label="คำอธิบาย" variant="outlined" rows="3" auto-grow />
            </v-col>
          </v-row>
        </v-card>

        <v-row dense>
          <v-col cols="12" md="6">
            <v-card flat border rounded="xl" class="pa-4 fill-height">
              <div class="d-flex align-center ga-2 mb-3">
                <v-icon icon="mdi-magnify" />
                <span class="text-subtitle-1 font-weight-bold">เลือกสมาชิก</span>
              </div>

              <v-btn-toggle v-model="tab" divided mandatory class="mb-3 w-100">
                <v-btn value="units" class="flex-1-1-0">หน่วยงาน</v-btn>
                <v-btn value="positions" class="flex-1-1-0">ตำแหน่ง</v-btn>
                <v-btn value="users" class="flex-1-1-0">รายบุคคล</v-btn>
              </v-btn-toggle>

              <v-text-field
                v-model="search"
                :label="searchLabel"
                variant="outlined"
                density="comfortable"
                prepend-inner-icon="mdi-magnify"
                hide-details
                class="mb-4"
              />

              <div v-if="!directory" class="d-flex align-center ga-2 text-body-2 text-medium-emphasis">
                <v-progress-circular indeterminate size="18" />
                กำลังโหลดข้อมูลสมาชิก...
              </div>

              <v-list v-else density="comfortable" class="member-list">
                <v-list-item
                  v-for="item in filteredItems"
                  :key="item.id"
                  :title="item.name"
                  :subtitle="'email' in item ? (item.email || undefined) : undefined"
                >
                  <template #append>
                    <v-btn
                      size="small"
                      variant="text"
                      color="admin-primary"
                      prepend-icon="mdi-plus"
                      @click="addItem(item.id)"
                    >
                      เพิ่ม
                    </v-btn>
                  </template>
                </v-list-item>
                <div v-if="directory && filteredItems.length === 0" class="text-body-2 text-medium-emphasis pa-4">
                  ไม่พบรายการที่ตรงกับคำค้น
                </div>
              </v-list>
            </v-card>
          </v-col>

          <v-col cols="12" md="6">
            <v-card flat border rounded="xl" class="pa-4 fill-height">
              <div class="d-flex align-center justify-space-between mb-4">
                <div>
                  <div class="text-subtitle-1 font-weight-bold">สมาชิกที่เลือก</div>
                  <div class="text-body-2 text-medium-emphasis">{{ totalSelected }} รายการ</div>
                </div>
              </div>

              <div class="d-flex flex-column ga-3">
                <v-card flat border rounded="lg" class="pa-3">
                  <div class="d-flex align-center justify-space-between mb-2">
                    <span class="font-weight-bold">หน่วยงาน</span>
                    <v-chip size="x-small" color="primary" variant="tonal">{{ selectedUnits.length }}</v-chip>
                  </div>
                  <div v-if="selectedUnits.length === 0" class="text-body-2 text-medium-emphasis">ยังไม่มีรายการ</div>
                  <div v-else class="d-flex flex-wrap ga-2">
                    <v-chip
                      v-for="unit in selectedUnits"
                      :key="unit.id"
                      size="small"
                      closable
                      @click:close="removeItem('units', unit.id)"
                    >
                      {{ unit.name }}
                    </v-chip>
                  </div>
                </v-card>

                <v-card flat border rounded="lg" class="pa-3">
                  <div class="d-flex align-center justify-space-between mb-2">
                    <span class="font-weight-bold">ตำแหน่ง</span>
                    <v-chip size="x-small" color="success" variant="tonal">{{ selectedPositions.length }}</v-chip>
                  </div>
                  <div v-if="selectedPositions.length === 0" class="text-body-2 text-medium-emphasis">ยังไม่มีรายการ</div>
                  <div v-else class="d-flex flex-wrap ga-2">
                    <v-chip
                      v-for="position in selectedPositions"
                      :key="position.id"
                      size="small"
                      closable
                      @click:close="removeItem('positions', position.id)"
                    >
                      {{ position.name }}
                    </v-chip>
                  </div>
                </v-card>

                <v-card flat border rounded="lg" class="pa-3">
                  <div class="d-flex align-center justify-space-between mb-2">
                    <span class="font-weight-bold">รายบุคคล</span>
                    <v-chip size="x-small" color="secondary" variant="tonal">{{ selectedUsers.length }}</v-chip>
                  </div>
                  <div v-if="selectedUsers.length === 0" class="text-body-2 text-medium-emphasis">ยังไม่มีรายการ</div>
                  <div v-else class="d-flex flex-wrap ga-2">
                    <v-chip
                      v-for="user in selectedUsers"
                      :key="user.id"
                      size="small"
                      closable
                      @click:close="removeItem('users', user.id)"
                    >
                      {{ user.name }}
                    </v-chip>
                  </div>
                </v-card>
              </div>
            </v-card>
          </v-col>
        </v-row>
      </v-card-text>

      <v-card-actions class="justify-end px-6 pb-6">
        <v-btn variant="text" @click="$emit('update:modelValue', false)">ยกเลิก</v-btn>
        <v-btn color="admin-primary" :loading="saving" :disabled="saveDisabled" @click="emitSave">บันทึกข้อมูล</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type {
  PermissionDirectoryPosition,
  PermissionDirectoryResponse,
  PermissionDirectoryUnit,
  PermissionDirectoryUser,
  UpsertPermissionGroupPayload,
} from '../../types/permission';

const props = defineProps<{
  modelValue: boolean;
  directory: PermissionDirectoryResponse | null;
  saving?: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
  save: [payload: UpsertPermissionGroupPayload];
}>();

const tab = ref<'units' | 'positions' | 'users'>('units');
const search = ref('');
const name = ref('');
const description = ref('');
const unitIds = ref<string[]>([]);
const positionIds = ref<string[]>([]);
const userIds = ref<string[]>([]);

watch(() => props.modelValue, (open) => {
  if (!open) return;
  tab.value = 'units';
  search.value = '';
  name.value = '';
  description.value = '';
  unitIds.value = [];
  positionIds.value = [];
  userIds.value = [];
});

const searchLabel = computed(() => {
  if (tab.value === 'units') return 'ค้นหาหน่วยงาน';
  if (tab.value === 'positions') return 'ค้นหาตำแหน่ง';
  return 'ค้นหารายบุคคล';
});

const selectedUnits = computed<PermissionDirectoryUnit[]>(() =>
  (props.directory?.units ?? []).filter((item) => unitIds.value.includes(item.id)),
);
const selectedPositions = computed<PermissionDirectoryPosition[]>(() =>
  (props.directory?.positions ?? []).filter((item) => positionIds.value.includes(item.id)),
);
const selectedUsers = computed<PermissionDirectoryUser[]>(() =>
  (props.directory?.users ?? []).filter((item) => userIds.value.includes(item.id)),
);

const totalSelected = computed(() =>
  unitIds.value.length + positionIds.value.length + userIds.value.length,
);

const filteredItems = computed(() => {
  const needle = search.value.trim().toLowerCase();

  if (tab.value === 'units') {
    return (props.directory?.units ?? [])
      .filter((item) => !unitIds.value.includes(item.id))
      .filter((item) => needle === '' || item.name.toLowerCase().includes(needle));
  }

  if (tab.value === 'positions') {
    return (props.directory?.positions ?? [])
      .filter((item) => !positionIds.value.includes(item.id))
      .filter((item) => needle === '' || item.name.toLowerCase().includes(needle));
  }

  return (props.directory?.users ?? [])
    .filter((item) => !userIds.value.includes(item.id))
    .filter((item) => needle === '' || item.name.toLowerCase().includes(needle) || (item.email ?? '').toLowerCase().includes(needle));
});

const saveDisabled = computed(() => name.value.trim() === '' || totalSelected.value === 0);

function addItem(id: string): void {
  if (tab.value === 'units' && !unitIds.value.includes(id)) unitIds.value = [...unitIds.value, id];
  if (tab.value === 'positions' && !positionIds.value.includes(id)) positionIds.value = [...positionIds.value, id];
  if (tab.value === 'users' && !userIds.value.includes(id)) userIds.value = [...userIds.value, id];
}

function removeItem(category: 'units' | 'positions' | 'users', id: string): void {
  if (category === 'units') unitIds.value = unitIds.value.filter((value) => value !== id);
  if (category === 'positions') positionIds.value = positionIds.value.filter((value) => value !== id);
  if (category === 'users') userIds.value = userIds.value.filter((value) => value !== id);
}

function emitSave(): void {
  emit('save', {
    name: name.value.trim(),
    description: description.value.trim() || null,
    unit_ids: unitIds.value,
    position_ids: positionIds.value,
    user_ids: userIds.value,
  });
}
</script>

<style scoped>
.member-list {
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  max-height: 360px;
  overflow: auto;
}
</style>
