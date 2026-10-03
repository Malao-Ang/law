<template>
  <v-card flat border rounded="lg" class="master-table">
    <v-table>
      <thead>
        <tr>
          <th class="master-table__index">ลำดับ</th>
          <th class="master-table__code">รหัส</th>
          <th
            v-for="column in columns"
            :key="column.key"
            :style="{ width: column.width }"
            :class="column.align === 'end' ? 'text-right' : undefined"
          >
            {{ column.title }}
          </th>
          <th class="master-table__status">สถานะ</th>
          <th class="master-table__actions">จัดการ</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="loading">
          <td :colspan="columns.length + 4" class="text-center py-8">
            <v-progress-circular indeterminate color="admin-primary" />
          </td>
        </tr>
        <tr v-else-if="items.length === 0">
          <td :colspan="columns.length + 4" class="text-center text-medium-emphasis py-8">
            ไม่มีข้อมูล
          </td>
        </tr>
        <tr v-for="(item, index) in items" v-else :key="item.code">
          <td class="master-table__index">{{ offset + index + 1 }}</td>
          <td>
            <v-chip size="small" variant="tonal" color="admin-primary" class="master-table__code-chip">
              {{ item.code }}
            </v-chip>
          </td>
          <td
            v-for="column in columns"
            :key="column.key"
            :class="column.align === 'end' ? 'text-right' : undefined"
          >
            <slot :name="`item.${column.key}`" :item="item" :value="itemValue(item, column.key)">
              {{ itemValue(item, column.key) || '-' }}
            </slot>
          </td>
          <td>
            <v-chip
              size="small"
              :color="item.is_active ? 'success' : 'grey'"
              variant="tonal"
              class="font-weight-medium"
            >
              {{ item.is_active ? '• ใช้งาน' : '• ปิดใช้งาน' }}
            </v-chip>
          </td>
          <td>
            <div class="master-table__action-row">
              <v-tooltip text="ดูรายละเอียด" location="top">
                <template #activator="{ props: tip }">
                  <v-btn
                    v-bind="tip"
                    icon="mdi-eye-outline"
                    variant="text"
                    size="small"
                    @click="$emit('view', item)"
                  />
                </template>
              </v-tooltip>
              <v-tooltip text="แก้ไข" location="top">
                <template #activator="{ props: tip }">
                  <v-btn
                    v-bind="tip"
                    icon="mdi-pencil-box-outline"
                    variant="text"
                    size="small"
                    color="admin-primary"
                    @click="$emit('edit', item)"
                  />
                </template>
              </v-tooltip>
              <v-tooltip :text="item.is_system ? 'สถานะของระบบ' : (item.is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน')" location="top">
                <template #activator="{ props: tip }">
                  <div v-bind="tip">
                    <v-switch
                      :model-value="item.is_active"
                      :disabled="item.is_system || togglingCode === item.code"
                      :loading="togglingCode === item.code"
                      color="success"
                      density="compact"
                      hide-details
                      inset
                      @update:model-value="$emit('toggle', item, Boolean($event))"
                    />
                  </div>
                </template>
              </v-tooltip>
            </div>
          </td>
        </tr>
      </tbody>
    </v-table>

    <v-divider />
    <div class="master-table__footer">
      <span class="text-body-2 text-medium-emphasis">
        แสดง {{ from }} ถึง {{ to }} จากทั้งหมด {{ total.toLocaleString('th-TH') }} รายการ
      </span>
      <v-pagination
        :model-value="page"
        :length="pageCount"
        :total-visible="5"
        density="compact"
        rounded="circle"
        @update:model-value="$emit('update:page', Number($event))"
      />
    </div>
  </v-card>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { MasterItem } from '../../types/masterData';

const props = defineProps<{
  items: MasterItem[];
  columns: { key: string; title: string; width?: string; align?: 'start' | 'end' | 'center' }[];
  loading: boolean;
  page: number;
  perPage: number;
  total: number;
  pageCount: number;
  togglingCode?: string | null;
}>();

defineEmits<{
  view: [item: MasterItem];
  edit: [item: MasterItem];
  toggle: [item: MasterItem, next: boolean];
  'update:page': [page: number];
}>();

const offset = computed(() => (props.page - 1) * props.perPage);
const from = computed(() => (props.total === 0 ? 0 : offset.value + 1));
const to = computed(() => Math.min(offset.value + props.items.length, props.total));

function itemValue(item: MasterItem, key: string): unknown {
  return item[key as keyof MasterItem];
}
</script>

<style scoped>
.master-table {
  overflow: hidden;
}

.master-table th {
  color: #475467;
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
}

.master-table td {
  vertical-align: middle;
}

.master-table__index {
  width: 72px;
}

.master-table__code {
  width: 112px;
}

.master-table__status {
  width: 132px;
}

.master-table__actions {
  width: 172px;
}

.master-table__code-chip {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
}

.master-table__action-row {
  align-items: center;
  display: flex;
  gap: 2px;
  min-height: 40px;
}

.master-table__footer {
  align-items: center;
  display: flex;
  gap: 12px;
  justify-content: space-between;
  min-height: 58px;
  padding: 10px 16px;
}

@media (max-width: 760px) {
  .master-table {
    overflow-x: auto;
  }

  .master-table__footer {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
