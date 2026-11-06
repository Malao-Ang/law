<template>
  <v-card flat border rounded="lg" class="master-table">
    <slot name="toolbar" />
    <v-progress-linear v-if="refreshing || (loading && items.length > 0)" indeterminate color="admin-primary" height="2" />
    <v-table>
      <thead>
        <tr>
          <th class="master-table__index">ลำดับ</th>
          <th class="master-table__code">{{ codeColumnTitle || 'รหัส' }}</th>
          <th
            v-for="column in columns"
            :key="column.key"
            :style="{ width: column.width }"
            class="text-center"
          >
            {{ column.title }}
          </th>
          <th class="master-table__status">สถานะ</th>
          <th class="master-table__actions">จัดการ</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="loading && items.length === 0">
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
          <td class="text-center master-table__index">{{ offset + index + 1 }}</td>
          <td class="text-center">
            <v-chip size="small" variant="tonal" color="admin-primary" class="master-table__code-chip">
              {{ tableValue(item.code) }}
            </v-chip>
          </td>
          <td
            v-for="column in columns"
            :key="column.key"
            :class="isEmptyTableValue(itemValue(item, column.key)) || column.align !== 'start' && !['name', 'description'].includes(column.key) ? 'text-center' : 'text-start'"
          >
            <span v-if="['name', 'description'].includes(column.key) && isEmptyTableValue(itemValue(item, column.key))">-</span>
            <slot v-else :name="`item.${column.key}`" :item="item" :value="itemValue(item, column.key)">
              {{ tableValue(itemValue(item, column.key)) }}
            </slot>
          </td>
          <td class="text-center">
            <v-chip
              size="small"
              :class="item.is_active ? 'master-status--active' : 'master-status--inactive'"
              variant="tonal"
              class="font-weight-medium"
            >
              {{ item.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
            </v-chip>
          </td>
          <td class="master-table__actions-cell text-center">
            <div class="master-table__action-row justify-center">
              <MasterToggleCell
                v-if="!readonly"
                :item="item"
                :kind="kind"
                :busy="pendingCodes?.has(item.code) || togglingCode === item.code"
                :epoch="switchEpoch"
                @toggle="$emit('toggle', item, $event)"
              />
              <v-tooltip text="ดูรายละเอียด" location="top">
                <template #activator="{ props: tip }">
                  <v-btn
                    v-bind="tip"
                    icon="mdi-eye-outline"
                    variant="text"
                    size="32"
                    :disabled="pendingCodes?.has(item.code)"
                    :loading="pendingCodes?.has(item.code)"
                    @click="$emit('view', item)"
                  />
                </template>
              </v-tooltip>
              <v-tooltip v-if="!readonly && kind !== 'change_status'" text="แก้ไข" location="top">
                <template #activator="{ props: tip }">
                  <v-btn
                    v-bind="tip"
                    icon="mdi-pencil-outline"
                    variant="text"
                    size="small"
                    color="admin-primary"
                    :disabled="pendingCodes?.has(item.code)"
                    :loading="pendingCodes?.has(item.code)"
                    @click="$emit('edit', item)"
                  />
                </template>
              </v-tooltip>
              <v-btn
                v-if="!readonly && item.can_delete"
                icon="mdi-delete-outline"
                variant="text"
                size="small"
                color="error"
                aria-label="ลบ"
                :disabled="pendingCodes?.has(item.code)"
                :loading="pendingCodes?.has(item.code)"
                @click="$emit('delete', item)"
              />
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
        rounded="sm"
        active-color="primary"
        @update:model-value="$emit('update:page', Number($event))"
      />
    </div>
  </v-card>
</template>

<script setup lang="ts">
import { tableValue, isEmptyTableValue } from '../../utils/tableValue';
import { computed } from 'vue';
import MasterToggleCell from './MasterToggleCell.vue';
import type { MasterItem, MasterKind } from '../../types/masterData';

const props = defineProps<{
  kind: MasterKind;
  items: MasterItem[];
  columns: { key: string; title: string; width?: string; align?: 'start' | 'end' | 'center' }[];
  loading: boolean;
  page: number;
  perPage: number;
  total: number;
  pageCount: number;
  togglingCode?: string | null;
  refreshing?: boolean;
  pendingCodes?: Set<string>;
  readonly?: boolean;
  codeColumnTitle?: string;
  switchEpoch?: number;
}>();

defineEmits<{
  view: [item: MasterItem];
  edit: [item: MasterItem];
  delete: [item: MasterItem];
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
  padding-top: 10px;
  padding-bottom: 10px;
  vertical-align: middle;
  overflow-wrap: anywhere;
}

.master-table__index {
  width: 72px;
}

.master-table__code {
  width: 112px;
}

.master-table__status {
  width: 112px;
}

/* View, edit, delete and the fixed switch fit in the existing actions width. */
.master-table__actions {
  min-width: 196px;
  white-space: nowrap;
  width: 196px;
}

.master-table td.master-table__actions-cell {
  padding-inline-end: 12px;
  white-space: nowrap;
}

.master-table__code-chip {
  font-family: inherit;
}

.master-table__action-row {
  align-items: center;
  display: flex;
  flex-wrap: nowrap;
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
/* Shared master table geometry, without changing cell alignment. */
.master-table :deep(.v-table) { font-size: 14px; color: #1e293b; }
.master-table :deep(th) { background: rgba(239,246,255,.3); color: #6b7280; font-size: 13px; font-weight: 600; height: 44px !important; }
.master-table :deep(td) { height: 72px !important; border-bottom-color: #e5e7eb !important; padding-inline: 20px; }
.master-table__code-chip { background: #eff6ff !important; color: #1e3a8a !important; border: 1px solid #dbeafe; border-radius: 4px; font-size: 12.5px; font-weight: 700; }
.master-table__code-chip :deep(.v-chip__underlay) { opacity: 0; }
.master-table__action-row :deep(.v-btn--icon) { width: 32px; height: 32px; color: #64748b; }
.master-table__action-row :deep(.v-icon) { font-size: 18px; }
.master-table__footer :deep(.v-pagination__item--is-active .v-btn) { background: #1e3a8a; }
.master-table__footer :deep(.v-pagination__item--is-active .v-btn__content) { color: #fff; }
</style>
