<template>
  <v-dialog :model-value="modelValue" max-width="980" @update:model-value="$emit('update:modelValue', $event)">
    <v-card rounded="xl" class="permission-group-dialog">
      <v-card-title class="d-flex align-center justify-space-between ga-4 px-5 pt-5 pb-2">
        <div>
          <div class="text-h6 font-weight-black">เพิ่มกลุ่มสิทธิ์ผู้ใช้ใหม่</div>
          <div class="text-body-2 text-medium-emphasis mt-1">สร้างเสร็จจะถูกเลือกอัตโนมัติโดยไม่ออกจากขั้นตอนนำเข้า</div>
        </div>
        <v-btn icon="mdi-close" variant="text" @click="$emit('update:modelValue', false)" />
      </v-card-title>

      <v-card-text class="permission-group-dialog__body px-5 pb-3">
        <v-card flat border rounded="xl" class="pa-4 mb-3">
          <div class="text-subtitle-2 font-weight-bold mb-3">ข้อมูลกลุ่ม</div>
          <v-row dense>
            <v-col cols="12">
              <v-text-field v-model="name" label="ชื่อกลุ่ม" variant="outlined" density="compact" hide-details />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="description" label="คำอธิบาย" variant="outlined" rows="2" density="compact" hide-details />
            </v-col>
          </v-row>
        </v-card>

        <v-row dense>
          <v-col cols="12" md="6">
            <v-card flat border rounded="xl" class="pa-3 fill-height">
              <div class="d-flex align-center ga-2 mb-2">
                <v-icon icon="mdi-magnify" />
                <span class="text-subtitle-2 font-weight-bold">เลือกสมาชิก</span>
              </div>

              <v-btn-toggle v-model="tab" divided mandatory density="compact" class="mb-2 w-100">
                <v-btn value="units" class="flex-1-1-0">หน่วยงาน</v-btn>
                <v-btn value="users" class="flex-1-1-0">รายบุคคล</v-btn>
              </v-btn-toggle>

              <v-text-field
                v-model="search"
                :label="searchLabel"
                variant="outlined"
                density="compact"
                prepend-inner-icon="mdi-magnify"
                hide-details
                class="mb-3"
              />

              <div v-if="!directory" class="d-flex align-center ga-2 text-body-2 text-medium-emphasis">
                <v-progress-circular indeterminate size="18" />
                กำลังโหลดข้อมูลสมาชิก...
              </div>

              <template v-else>
                <div v-if="tab === 'units' && filteredItems.length > 0" class="d-flex justify-end mb-1">
                  <v-btn variant="text" size="small" density="compact" color="admin-primary" @click="selectAll">
                    เลือกทั้งหมด ({{ filteredItems.length }})
                  </v-btn>
                </div>

                <v-list density="comfortable" class="member-list">
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
                  <div v-if="filteredItems.length === 0" class="text-body-2 text-medium-emphasis pa-4">
                    ไม่พบรายการที่ตรงกับคำค้น
                  </div>
                </v-list>
              </template>
            </v-card>
          </v-col>

          <v-col cols="12" md="6">
            <v-card flat border rounded="xl" class="pa-3 fill-height">
              <div class="d-flex align-center justify-space-between mb-3">
                <div>
                  <div class="text-subtitle-2 font-weight-bold">สมาชิกที่เลือก</div>
                  <div class="text-body-2 text-medium-emphasis">{{ totalSelected }} รายการ</div>
                </div>
              </div>

              <v-expansion-panels v-model="expandedSections" multiple variant="accordion" rounded="xl">
                <v-expansion-panel value="units">
                  <v-expansion-panel-title>
                    <div class="d-flex align-center ga-2">
                      <span class="font-weight-bold">หน่วยงาน</span>
                      <v-chip size="x-small" color="primary" variant="tonal">{{ selectedUnits.length }} รายการ</v-chip>
                    </div>
                  </v-expansion-panel-title>
                  <v-expansion-panel-text>
                    <div v-if="selectedUnits.length === 0" class="text-body-2 text-medium-emphasis">ยังไม่มีรายการ</div>
                    <div v-else class="d-flex flex-wrap ga-2">
                      <v-chip v-for="unit in selectedUnits" :key="unit.id" size="small" closable @click:close="removeItem('units', unit.id)">{{ unit.name }}</v-chip>
                    </div>
                  </v-expansion-panel-text>
                </v-expansion-panel>

                <v-expansion-panel value="users">
                  <v-expansion-panel-title>
                    <div class="d-flex align-center ga-2">
                      <span class="font-weight-bold">รายบุคคล</span>
                      <v-chip size="x-small" color="secondary" variant="tonal">{{ selectedUsers.length }} รายการ</v-chip>
                    </div>
                  </v-expansion-panel-title>
                  <v-expansion-panel-text>
                    <div v-if="selectedUsers.length === 0" class="text-body-2 text-medium-emphasis">ยังไม่มีรายการ</div>
                    <div v-else class="d-flex flex-wrap ga-2">
                      <v-chip v-for="user in selectedUsers" :key="user.id" size="small" closable @click:close="removeItem('users', user.id)">{{ user.name }}</v-chip>
                    </div>
                  </v-expansion-panel-text>
                </v-expansion-panel>
              </v-expansion-panels>
            </v-card>
          </v-col>
        </v-row>
      </v-card-text>

      <v-card-actions class="justify-end px-5 py-4">
        <v-btn variant="text" @click="$emit('update:modelValue', false)">ยกเลิก</v-btn>
        <v-btn color="admin-primary" :loading="saving" :disabled="saveDisabled" @click="emitSave">บันทึกข้อมูล</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type {
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

const tab = ref<'units' | 'users'>('units');
const search = ref('');
const name = ref('');
const description = ref('');
const unitIds = ref<string[]>([]);
const userIds = ref<string[]>([]);
const expandedSections = ref(['units']);

watch(() => props.modelValue, (open) => {
  if (!open) return;
  tab.value = 'units';
  search.value = '';
  name.value = '';
  description.value = '';
  unitIds.value = [];
  userIds.value = [];
  expandedSections.value = ['units'];
});

const searchLabel = computed(() => {
  if (tab.value === 'units') return 'ค้นหาหน่วยงาน';
  return 'ค้นหารายบุคคล';
});

const selectedUnits = computed<PermissionDirectoryUnit[]>(() =>
  (props.directory?.units ?? []).filter((item) => unitIds.value.includes(item.id)),
);
const selectedUsers = computed<PermissionDirectoryUser[]>(() =>
  (props.directory?.users ?? []).filter((item) => userIds.value.includes(item.id)),
);

const totalSelected = computed(() =>
  unitIds.value.length + userIds.value.length,
);

const filteredItems = computed(() => {
  const needle = search.value.trim().toLowerCase();

  if (tab.value === 'units') {
    return (props.directory?.units ?? [])
      .filter((item) => !unitIds.value.includes(item.id))
      .filter((item) => needle === '' || item.name.toLowerCase().includes(needle));
  }

  return (props.directory?.users ?? [])
    .filter((item) => !userIds.value.includes(item.id))
    .filter((item) => needle === '' || item.name.toLowerCase().includes(needle) || (item.email ?? '').toLowerCase().includes(needle));
});

const saveDisabled = computed(() => name.value.trim() === '' || totalSelected.value === 0);

function addItem(id: string): void {
  if (tab.value === 'units' && !unitIds.value.includes(id)) unitIds.value = [...unitIds.value, id];
  if (tab.value === 'users' && !userIds.value.includes(id)) userIds.value = [...userIds.value, id];
}

function selectAll(): void {
  const ids = filteredItems.value.map((item) => item.id);
  if (tab.value === 'units') unitIds.value = [...new Set([...unitIds.value, ...ids])];
}

function removeItem(category: 'units' | 'users', id: string): void {
  if (category === 'units') unitIds.value = unitIds.value.filter((value) => value !== id);
  if (category === 'users') userIds.value = userIds.value.filter((value) => value !== id);
}

function emitSave(): void {
  emit('save', {
    name: name.value.trim(),
    description: description.value.trim() || null,
    unit_ids: unitIds.value,
    user_ids: userIds.value,
  });
}
</script>

<style scoped>
.permission-group-dialog {
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 48px);
}

.permission-group-dialog__body {
  overflow-y: auto;
}

.member-list {
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  max-height: 240px;
  overflow: auto;
}
</style>
