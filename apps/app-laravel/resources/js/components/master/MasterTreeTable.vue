<template>
  <v-card flat border rounded="lg" class="master-tree">
    <v-table>
      <thead>
        <tr>
          <th class="master-tree__code">รหัส</th>
          <th>ชื่อ</th>
          <th class="master-tree__source">ที่มา · หน่วย</th>
          <th class="master-tree__usage text-right">เอกสาร</th>
          <th class="master-tree__status">สถานะ</th>
          <th class="master-tree__actions">จัดการ</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="loading">
          <td colspan="6" class="text-center py-8">
            <v-progress-circular indeterminate color="admin-primary" />
          </td>
        </tr>
        <tr v-else-if="rows.length === 0">
          <td colspan="6" class="text-center text-medium-emphasis py-8">ไม่มีข้อมูล</td>
        </tr>
        <template v-for="row in rows" v-else :key="row.family.code">
          <tr class="master-tree__group">
            <td>
              <button type="button" class="master-tree__toggle" @click="$emit('toggleExpand', row.family.code)">
                <v-icon :icon="expandedCodes.includes(row.family.code) ? 'mdi-chevron-down' : 'mdi-chevron-right'" size="18" />
              </button>
              <span class="master-tree__mono">{{ row.family.code }}</span>
            </td>
            <td>
              <span class="master-tree__dot" :style="{ background: row.color }" />
              <span class="font-weight-bold">{{ row.family.name }}</span>
              <span class="text-medium-emphasis ml-2">({{ row.types.length }} ประเภท)</span>
              <v-chip v-if="row.locked" size="x-small" color="success" variant="tonal" class="ml-2" prepend-icon="mdi-lock">
                ระบบ
              </v-chip>
            </td>
            <td>{{ sourceLabel(row.source) }} · {{ unitLabel(row.source) }}</td>
            <td class="text-right font-weight-bold">{{ row.usage.toLocaleString('th-TH') }}</td>
            <td>
              <StatusChip :active="row.family.is_active" />
            </td>
            <td>
              <div class="master-tree__action-row">
                <v-btn size="x-small" variant="tonal" color="admin-primary" prepend-icon="mdi-plus" class="text-none" @click="$emit('addType', row.family)">
                  ประเภท
                </v-btn>
                <v-tooltip text="แก้ไข" location="top">
                  <template #activator="{ props: tip }">
                    <v-btn v-bind="tip" icon="mdi-pencil-box-outline" variant="text" size="small" color="admin-primary" @click="$emit('editFamily', row.family)" />
                  </template>
                </v-tooltip>
                <v-tooltip :text="row.locked ? 'กลุ่มระบบ' : (row.family.is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน')" location="top">
                  <template #activator="{ props: tip }">
                    <div v-bind="tip">
                      <v-switch
                        :model-value="row.family.is_active"
                        :readonly="row.locked"
                        :disabled="togglingCode === row.family.code"
                        :loading="togglingCode === row.family.code"
                        color="success"
                        density="compact"
                        hide-details
                        inset
                        @update:model-value="$emit('toggleFamily', row.family, Boolean($event))"
                      />
                    </div>
                  </template>
                </v-tooltip>
              </div>
            </td>
          </tr>
          <tr
            v-for="type in row.types"
            v-show="expandedCodes.includes(row.family.code)"
            :key="type.code"
            class="master-tree__child"
          >
            <td><span class="master-tree__mono">{{ type.code }}</span></td>
            <td class="master-tree__child-name">{{ type.name }}</td>
            <td class="text-medium-emphasis">ตามกลุ่ม</td>
            <td class="text-right">
              <v-chip
                size="small"
                variant="tonal"
                color="admin-primary"
                :to="`/admin/laws?type=${encodeURIComponent(type.code)}`"
              >
                {{ (type.usage_count ?? 0).toLocaleString('th-TH') }} รายการ
              </v-chip>
            </td>
            <td>
              <StatusChip :active="type.is_active" />
            </td>
            <td>
              <div class="master-tree__action-row">
                <v-tooltip text="ดูรายการเอกสาร" location="top">
                  <template #activator="{ props: tip }">
                    <v-btn v-bind="tip" icon="mdi-eye-outline" variant="text" size="small" :to="`/admin/laws?type=${encodeURIComponent(type.code)}`" />
                  </template>
                </v-tooltip>
                <v-tooltip text="แก้ไข" location="top">
                  <template #activator="{ props: tip }">
                    <v-btn v-bind="tip" icon="mdi-pencil-box-outline" variant="text" size="small" color="admin-primary" @click="$emit('editType', type)" />
                  </template>
                </v-tooltip>
                <v-tooltip :text="type.is_system ? 'สถานะของระบบ' : (type.is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน')" location="top">
                  <template #activator="{ props: tip }">
                    <div v-bind="tip">
                      <v-switch
                        :model-value="type.is_active"
                        :readonly="type.is_system"
                        :disabled="togglingCode === type.code"
                        :loading="togglingCode === type.code"
                        color="success"
                        density="compact"
                        hide-details
                        inset
                        @update:model-value="$emit('toggleType', type, Boolean($event))"
                      />
                    </div>
                  </template>
                </v-tooltip>
              </div>
            </td>
          </tr>
        </template>
      </tbody>
    </v-table>
  </v-card>
</template>

<script setup lang="ts">
import { defineComponent, h } from 'vue';
import type { LawSource } from '../../api/client';
import type { MasterItem } from '../../types/masterData';

interface MasterTreeRow {
  family: MasterItem;
  types: MasterItem[];
  source: LawSource;
  color: string;
  usage: number;
  locked: boolean;
}

defineProps<{
  rows: MasterTreeRow[];
  expandedCodes: string[];
  loading?: boolean;
  togglingCode?: string | null;
}>();

defineEmits<{
  toggleExpand: [code: string];
  addType: [family: MasterItem];
  editFamily: [family: MasterItem];
  editType: [type: MasterItem];
  toggleFamily: [family: MasterItem, next: boolean];
  toggleType: [type: MasterItem, next: boolean];
}>();

const StatusChip = defineComponent({
  props: {
    active: { type: Boolean, required: true },
  },
  setup(props) {
    return () => h(
      'span',
      {
        class: [
          'master-tree__status-chip',
          props.active ? 'master-tree__status-chip--active' : 'master-tree__status-chip--inactive',
        ],
      },
      props.active ? 'ใช้งาน' : 'ปิดใช้งาน',
    );
  },
});

function sourceLabel(source: LawSource): string {
  return source === 'external' ? 'ภายนอก' : 'ภายใน';
}

function unitLabel(source: LawSource): string {
  return source === 'external' ? 'มาตรา' : 'ข้อ';
}
</script>

<style scoped>
.master-tree {
  overflow: hidden;
}

.master-tree th {
  color: #475467;
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
}

.master-tree td {
  vertical-align: middle;
}

.master-tree__code { width: 140px; }
.master-tree__source { width: 150px; }
.master-tree__usage { width: 110px; }
.master-tree__status { width: 120px; }
.master-tree__actions { width: 210px; }

.master-tree__group td {
  background: #f8fafc;
}

.master-tree__toggle {
  align-items: center;
  background: transparent;
  border: 0;
  color: #475467;
  cursor: pointer;
  display: inline-flex;
  height: 28px;
  justify-content: center;
  margin-right: 4px;
  padding: 0;
  width: 28px;
}

.master-tree__mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  font-weight: 700;
}

.master-tree__dot {
  border-radius: 50%;
  display: inline-block;
  height: 10px;
  margin-right: 8px;
  width: 10px;
}

.master-tree__child-name {
  padding-left: 32px !important;
}

.master-tree__action-row {
  align-items: center;
  display: flex;
  gap: 4px;
  min-height: 40px;
}

.master-tree__status-chip {
  border-radius: 999px;
  display: inline-flex;
  font-size: 12px;
  font-weight: 700;
  padding: 3px 10px;
}

.master-tree__status-chip--active {
  background: #dcfce7;
  color: #15803d;
}

.master-tree__status-chip--inactive {
  background: #f1f5f9;
  color: #64748b;
}

@media (max-width: 900px) {
  .master-tree {
    overflow-x: auto;
  }
}
</style>
