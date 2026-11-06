<template>
  <v-card flat border rounded="lg" class="master-tree">
    <v-progress-linear v-if="refreshing || (loading && rows.length > 0)" indeterminate color="admin-primary" height="2" />
    <v-table>
      <thead>
        <tr>
          <th class="master-tree__order">ลำดับ</th>
          <th class="master-tree__code">รหัส</th>
          <th>ชื่อ</th>
          <th class="master-tree__source">ที่มา · หน่วย</th>
          <th class="master-tree__usage text-center">เอกสาร</th>
          <th class="master-tree__status">สถานะ</th>
          <th class="master-tree__actions">จัดการ</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="loading && rows.length === 0">
          <td colspan="7" class="text-center py-8">
            <v-progress-circular indeterminate color="admin-primary" />
          </td>
        </tr>
        <tr v-else-if="rows.length === 0">
          <td colspan="7" class="text-center text-medium-emphasis py-8">ไม่มีข้อมูล</td>
        </tr>
        <template v-for="(row, familyIndex) in rows" v-else :key="row.family.code">
          <tr
            class="master-tree__group"
            :draggable="reorderMode"
            @dragstart="handleDragStart($event, 'family', row.family.code)"
            @dragover.prevent
            @drop="handleDrop($event, 'family', row.family.code)"
          >
            <td class="text-center master-tree__order-cell">{{ familyIndex + 1 }}</td>
            <td class="text-center">
              <v-btn v-if="row.family.attrs.has_subtypes !== false" variant="text" icon size="28" class="master-tree__toggle" :disabled="reorderMode" @click="$emit('toggleExpand', row.family.code)">
                <v-icon :icon="expandedCodes.includes(row.family.code) ? 'mdi-chevron-down' : 'mdi-chevron-right'" size="18" />
              </v-btn>
              <span v-else class="master-tree__toggle-spacer" aria-hidden="true" />
              <v-chip size="small" variant="tonal" color="admin-primary" class="master-tree__code-chip">
                {{ tableValue(row.family.code) }}
              </v-chip>
            </td>
            <td :class="isEmptyTableValue(row.family.name) ? 'text-center' : 'text-start'">
              <span class="master-tree__dot" :style="{ background: row.color }" />
              <v-chip size="small" variant="outlined" class="master-tree__family-chip">{{ tableValue(row.family.name) }}</v-chip>
            </td>
            <td class="text-start"><v-chip size="small" variant="outlined" color="primary">{{ sourceLabel(row.source) }} · {{ unitLabel(row.source) }}</v-chip></td>
            <td class="text-center font-weight-bold">
              <v-btn
                size="small" variant="tonal" color="admin-primary" prepend-icon="mdi-file-document-outline"
                :to="usageLink('law_family', row.family.code, row.family.usage_count) ?? undefined"
              >{{ (row.family.usage_count ?? 0).toLocaleString('th-TH') }} รายการ</v-btn>
            </td>
            <td class="text-center">
              <v-chip size="small" :class="row.family.is_active ? 'master-status--active' : 'master-status--inactive'" variant="tonal" class="font-weight-medium">
                {{ row.family.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
              </v-chip>
            </td>
            <td class="text-center">
              <div class="master-tree__action-row justify-center">
                <template v-if="reorderMode">
                  <v-btn
                    icon="mdi-chevron-up"
                    variant="text"
                    size="small"
                    :disabled="familyIndex === 0"
                    @click="$emit('moveFamily', row.family.code, 'up')"
                  />
                  <v-btn
                    icon="mdi-chevron-down"
                    variant="text"
                    size="small"
                    :disabled="familyIndex === rows.length - 1"
                    @click="$emit('moveFamily', row.family.code, 'down')"
                  />
                  <v-icon icon="mdi-drag-vertical" size="20" class="master-tree__drag-handle" />
                </template>
                <template v-else>
                <MasterToggleCell
                  :item="row.family"
                  kind="law_family"
                  :busy="pendingCodes?.has(row.family.code) || togglingCode === row.family.code"
                  :epoch="switchEpoch"
                  @toggle="$emit('toggleFamily', row.family, $event)"
                />
                <v-tooltip :text="`เพิ่มประเภทย่อยในกลุ่ม ${row.family.name}`" location="top" :disabled="row.family.attrs.has_subtypes === false">
                  <template #activator="{ props: tipProps }">
                    <v-btn
                      v-bind="tipProps"
                      icon="mdi-plus" size="small" variant="text" color="admin-primary"
                      :class="{ 'master-tree__add-placeholder': row.family.attrs.has_subtypes === false }"
                      :disabled="row.family.attrs.has_subtypes === false || pendingCodes?.has(row.family.code)"
                      :loading="pendingCodes?.has(row.family.code)"
                      :aria-label="`เพิ่มประเภทย่อยในกลุ่ม ${row.family.name}`"
                      :aria-hidden="row.family.attrs.has_subtypes === false ? 'true' : undefined"
                      :tabindex="row.family.attrs.has_subtypes === false ? -1 : undefined"
                      @click="$emit('addType', row.family)"
                    />
                  </template>
                </v-tooltip>
                <v-tooltip text="แก้ไข" location="top">
                  <template #activator="{ props: tip }">
                    <v-btn v-bind="tip" icon="mdi-pencil-outline" variant="text" size="small" color="admin-primary" :disabled="pendingCodes?.has(row.family.code)" :loading="pendingCodes?.has(row.family.code)" @click="$emit('editFamily', row.family)" />
                  </template>
                </v-tooltip>
                <v-btn v-if="row.family.can_delete" icon="mdi-delete-outline" variant="text" size="small" color="error" aria-label="ลบ" :disabled="pendingCodes?.has(row.family.code)" :loading="pendingCodes?.has(row.family.code)" @click="$emit('deleteFamily', row.family)" />
                </template>
              </div>
            </td>
          </tr>
          <tr
            v-for="(type, typeIndex) in (row.family.attrs.has_subtypes === false ? [] : row.types)"
            v-show="expandedCodes.includes(row.family.code)"
            :key="type.code"
            class="master-tree__child"
            :class="{ 'master-tree__child--last': typeIndex === row.types.length - 1 }"
            :style="{ '--family-color': row.color }"
            :draggable="reorderMode"
            @dragstart="handleDragStart($event, 'type', type.code, row.family.code)"
            @dragover.prevent
            @drop="handleDrop($event, 'type', type.code, row.family.code)"
          >
            <td class="text-center master-tree__order-cell master-tree__child-order">{{ familyIndex + 1 }}.{{ typeIndex + 1 }}</td>
            <td class="text-center master-tree__child-code">
              <v-chip size="small" variant="tonal" color="admin-primary" class="master-tree__code-chip">
                {{ tableValue(type.code) }}
              </v-chip>
            </td>
            <td class="master-tree__child-name" :class="isEmptyTableValue(type.name) ? 'text-center' : 'text-start'">{{ tableValue(type.name) }}</td>
            <td class="text-start text-medium-emphasis">ตามกลุ่ม</td>
            <td class="text-center">
              <v-btn
                size="small"
                variant="tonal"
                color="admin-primary"
                prepend-icon="mdi-file-document-outline"
                :to="usageLink('law_type', type.code, type.usage_count) ?? undefined"
              >
                {{ (type.usage_count ?? 0).toLocaleString('th-TH') }} รายการ
              </v-btn>
            </td>
            <td class="text-center">
              <v-chip size="small" :class="type.is_active ? 'master-status--active' : 'master-status--inactive'" variant="tonal" class="font-weight-medium">
                {{ type.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
              </v-chip>
            </td>
            <td class="text-center">
              <div class="master-tree__action-row justify-center">
                <template v-if="reorderMode">
                  <v-btn
                    icon="mdi-chevron-up"
                    variant="text"
                    size="small"
                    :disabled="typeIndex === 0"
                    @click="$emit('moveType', row.family.code, type.code, 'up')"
                  />
                  <v-btn
                    icon="mdi-chevron-down"
                    variant="text"
                    size="small"
                    :disabled="typeIndex === row.types.length - 1"
                    @click="$emit('moveType', row.family.code, type.code, 'down')"
                  />
                  <v-icon icon="mdi-drag-vertical" size="20" class="master-tree__drag-handle" />
                </template>
                <template v-else>
                <MasterToggleCell
                  :item="type"
                  kind="law_type"
                  :busy="pendingCodes?.has(type.code) || togglingCode === type.code"
                  :epoch="switchEpoch"
                  @toggle="$emit('toggleType', type, $event)"
                />
                <v-tooltip text="ดูรายการเอกสาร" location="top">
                  <template #activator="{ props: tip }">
                    <v-btn v-bind="tip" icon="mdi-eye-outline" variant="text" size="small" :to="`/admin/laws?type=${encodeURIComponent(type.code)}`" />
                  </template>
                </v-tooltip>
                <v-tooltip text="แก้ไข" location="top">
                  <template #activator="{ props: tip }">
                    <v-btn v-bind="tip" icon="mdi-pencil-outline" variant="text" size="small" color="admin-primary" :disabled="pendingCodes?.has(type.code)" :loading="pendingCodes?.has(type.code)" @click="$emit('editType', type)" />
                  </template>
                </v-tooltip>
                <v-btn v-if="type.can_delete" icon="mdi-delete-outline" variant="text" size="small" color="error" aria-label="ลบ" :disabled="pendingCodes?.has(type.code)" :loading="pendingCodes?.has(type.code)" @click="$emit('deleteType', type)" />
                </template>
              </div>
            </td>
          </tr>
        </template>
      </tbody>
    </v-table>
  </v-card>
</template>

<script setup lang="ts">
import { tableValue, isEmptyTableValue } from '../../utils/tableValue';
import type { LawSource } from '../../api/client';
import MasterToggleCell from './MasterToggleCell.vue';
import { usageLink } from './masterLock';
import type { MasterItem } from '../../types/masterData';

interface MasterTreeRow {
  family: MasterItem;
  types: MasterItem[];
  primary?: MasterItem;
  source: LawSource;
  color: string;
  usage: number;
  locked: boolean;
}

defineProps<{
  rows: MasterTreeRow[];
  switchEpoch?: number;
  expandedCodes: string[];
  loading?: boolean;
  togglingCode?: string | null;
  refreshing?: boolean;
  pendingCodes?: Set<string>;
  reorderMode?: boolean;
}>();

const emit = defineEmits<{
  toggleExpand: [code: string];
  addType: [family: MasterItem];
  editFamily: [family: MasterItem];
  editType: [type: MasterItem];
  deleteFamily: [family: MasterItem];
  deleteType: [type: MasterItem];
  toggleFamily: [family: MasterItem, next: boolean];
  toggleType: [type: MasterItem, next: boolean];
  moveFamily: [code: string, dir: 'up' | 'down'];
  moveType: [familyCode: string, code: string, dir: 'up' | 'down'];
  dragFamily: [fromCode: string, toCode: string];
  dragType: [familyCode: string, fromCode: string, toCode: string];
}>();

type DragPayload = {
  kind: 'family' | 'type';
  code: string;
  familyCode?: string;
};


function sourceLabel(source: LawSource): string {
  return source === 'external' ? 'ภายนอก' : 'ภายใน';
}

function unitLabel(source: LawSource): string {
  return source === 'external' ? 'มาตรา' : 'ข้อ';
}

function handleDragStart(event: DragEvent, kind: DragPayload['kind'], code: string, familyCode?: string): void {
  event.dataTransfer?.setData('application/json', JSON.stringify({ kind, code, familyCode }));
  event.dataTransfer?.setData('text/plain', code);
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}

function handleDrop(event: DragEvent, kind: DragPayload['kind'], code: string, familyCode?: string): void {
  const raw = event.dataTransfer?.getData('application/json');
  if (!raw) return;

  try {
    const payload = JSON.parse(raw) as DragPayload;
    if (payload.kind !== kind) return;
    if (kind === 'family') emit('dragFamily', payload.code, code);
    if (kind === 'type' && payload.familyCode === familyCode && familyCode) {
      emit('dragType', familyCode, payload.code, code);
    }
  } catch {
    // Ignore malformed drag data from outside this table.
  }
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
  overflow-wrap: anywhere;
}

.master-tree__order { width: 74px; }
.master-tree__code { width: 140px; }
.master-tree__source { width: 150px; }
.master-tree__usage { width: 110px; }
.master-tree__status { width: 120px; }
.master-tree__actions { width: 240px; min-width: 240px; }

.master-tree__group td {
  font-weight: 600;
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

.master-tree__add-placeholder {
  visibility: hidden;
}

.master-tree__toggle-spacer {
  display: inline-block;
  margin-right: 4px;
  vertical-align: middle;
  overflow-wrap: anywhere;
  width: 28px;
}

.master-tree__toggle:disabled {
  cursor: default;
  opacity: 0.6;
}

.master-tree__order-cell {
  white-space: nowrap;
}

.master-tree__code-chip {
  font-family: inherit;
}
.master-tree__family-chip { border-color: #dbeafe; background: #eff6ff; color: #1e3a8a; font-weight: 600; border-radius: 9999px; max-width: 100%; }

.master-tree__dot {
  border-radius: 50%;
  display: inline-block;
  height: 10px;
  margin-right: 8px;
  width: 10px;
}

.master-tree__child-order {
  padding-left: 34px !important;
  position: relative;
}

.master-tree__child-order::before {
  border-left: 2px solid var(--family-color);
  bottom: 0;
  content: "";
  left: 14px;
  position: absolute;
  top: 0;
}

.master-tree__child--last .master-tree__child-order::before {
  bottom: 50%;
}

.master-tree__child-order::after {
  border-top: 2px solid var(--family-color);
  content: "";
  left: 14px;
  position: absolute;
  top: 50%;
  width: 12px;
}

.master-tree__child-code,
.master-tree__child-name {
  padding-left: 34px !important;
}

.master-tree__child-name {
  font-weight: 400;
}

.master-tree__action-row {
  align-items: center;
  display: flex;
  gap: 2px;
  flex-wrap: nowrap;
  min-height: 40px;
}

.master-tree__drag-handle {
  color: #667085;
  cursor: grab;
}


@media (max-width: 900px) {
  .master-tree {
    overflow-x: auto;
  }

  .master-tree__child-order,
  .master-tree__child-code,
  .master-tree__child-name {
    padding-left: 24px !important;
  }

  .master-tree__child-order::before,
  .master-tree__child-order::after {
    left: 10px;
  }
}
/* Shared master table geometry, without changing cell alignment. */
.master-tree :deep(.v-table) { font-size: 14px; color: #1e293b; }
.master-tree :deep(th) { background: rgba(239,246,255,.3); color: #6b7280; font-size: 13px; font-weight: 600; height: 44px !important; }
.master-tree :deep(td) { height: 72px !important; border-bottom-color: #e5e7eb !important; padding-inline: 20px; }
.master-tree__code-chip { background: #eff6ff !important; color: #1e3a8a !important; border: 1px solid #dbeafe; border-radius: 4px; font-size: 12.5px; font-weight: 700; }
.master-tree__code-chip :deep(.v-chip__underlay) { opacity: 0; }
.master-tree__action-row :deep(.v-btn--icon) { width: 32px; height: 32px; color: #64748b; }
.master-tree__action-row :deep(.v-icon) { font-size: 18px; }
</style>
