<template>
  <AppShell
    :breadcrumbs="['จัดการข้อมูลระบบ', 'ประเภทเอกสาร']"
    title="ประเภทเอกสาร"
    subtitle="จัดการกลุ่มประเภทและประเภทเอกสาร"
    show-bell
  >
    <template #title-actions>
      <div class="law-type-tree-page__title-actions">
        <v-btn
          variant="outlined"
          color="admin-primary"
          prepend-icon="mdi-swap-vertical"
          class="text-none"
          :disabled="reorderMode"
          @click="startReorder"
        >
          จัดลำดับ
        </v-btn>
        <v-btn color="admin-primary" prepend-icon="mdi-plus" class="text-none" :disabled="reorderMode" @click="openCreateFamily">
          เพิ่มกลุ่มประเภท
        </v-btn>
      </div>
    </template>

    <div class="law-type-tree-page">
      <div class="law-type-tree-page__stats">
        <v-card v-for="stat in statCards" :key="stat.label" flat border rounded="lg" class="pa-4">
          <div class="text-caption text-medium-emphasis">{{ stat.label }}</div>
          <div class="text-h5 font-weight-bold mt-1">{{ stat.value.toLocaleString('th-TH') }}</div>
        </v-card>
      </div>

      <v-card flat border rounded="lg" class="law-type-tree-page__filters">
        <v-text-field
          v-model="query"
          :disabled="reorderMode"
          placeholder="ค้นหาชื่อหรือรหัส"
          prepend-inner-icon="mdi-magnify"
          variant="outlined"
          density="comfortable"
          hide-details
          clearable
        />
        <v-select
          v-model="sourceFilter"
          :disabled="reorderMode"
          :items="sourceOptions"
          variant="outlined"
          density="comfortable"
          hide-details
        />
        <v-select
          v-model="activeFilter"
          :disabled="reorderMode"
          :items="activeOptions"
          variant="outlined"
          density="comfortable"
          hide-details
        />
        <v-btn variant="outlined" prepend-icon="mdi-refresh" class="text-none" :disabled="reorderMode" @click="resetFilters">
          ล้างตัวกรอง
        </v-btn>
      </v-card>

      <v-alert v-if="reorderMode" type="info" variant="tonal" density="compact" icon="mdi-swap-vertical">
        <div class="law-type-tree-page__reorder-bar">
          <span>โหมดจัดลำดับ — ลากหรือกดลูกศรเพื่อเรียงใหม่</span>
          <span class="law-type-tree-page__reorder-actions">
            <v-btn variant="outlined" size="small" class="text-none" :disabled="saving" @click="cancelReorder">ยกเลิก</v-btn>
            <v-btn color="admin-primary" size="small" class="text-none" :loading="saving" @click="saveReorder">บันทึกลำดับ</v-btn>
          </span>
        </div>
      </v-alert>

      <v-alert v-if="familyMaster.error.value || typeMaster.error.value" type="error" variant="tonal" density="compact">
        {{ familyMaster.error.value || typeMaster.error.value }}
      </v-alert>

      <MasterTreeTable
        :rows="tableRows"
        :expanded-codes="[...expandedCodes]"
        :loading="familyMaster.loading.value || typeMaster.loading.value"
        :toggling-code="togglingCode"
        :reorder-mode="reorderMode"
        @toggle-expand="toggleExpand"
        @add-type="openCreateType"
        @edit-family="openEditFamily"
        @edit-type="openEditType"
        @toggle-family="handleToggleFamily"
        @toggle-type="handleToggleType"
        @move-family="moveFamily"
        @move-type="moveType"
        @drag-family="dragFamily"
        @drag-type="dragType"
      />
    </div>

    <v-dialog v-model="familyDialogOpen" max-width="620">
      <v-card rounded="xl">
        <div class="dialog-head">
          <div>
            <h2>{{ editingFamily ? 'แก้ไขกลุ่มประเภท' : 'เพิ่มกลุ่มประเภท' }}</h2>
            <p>กลุ่มใช้กำหนดที่มา สีป้าย และหน่วยนับ</p>
          </div>
          <v-btn icon="mdi-close" variant="text" size="small" @click="familyDialogOpen = false" />
        </div>
        <v-card-text class="dialog-body">
          <v-text-field
            :model-value="editingFamily?.code ?? familyMaster.nextCode.value"
            label="รหัส"
            hint="ระบบสร้างรหัสอัตโนมัติ"
            persistent-hint
            readonly
            bg-color="grey-lighten-4"
            variant="outlined"
          />
          <v-text-field
            v-model="familyForm.name"
            label="ชื่อกลุ่ม*"
            :rules="[requiredRule]"
            :error-messages="fieldErrors.name"
            variant="outlined"
          />
          <v-select
            v-model="familyForm.source"
            :items="familySourceOptions"
            label="ที่มา*"
            variant="outlined"
            :rules="[requiredRule]"
            :error-messages="fieldErrors['attrs.source']"
          />
          <v-alert type="info" variant="tonal" density="compact" icon="mdi-format-list-numbered">
            หน่วยนับตามที่มา: {{ unitLabel(familyForm.source) }}
          </v-alert>
          <v-text-field
            v-model="familyForm.color"
            label="สีป้าย"
            type="color"
            variant="outlined"
            :error-messages="fieldErrors['attrs.color']"
          />
        </v-card-text>
        <v-divider />
        <v-card-actions class="justify-end pa-4">
          <v-btn variant="outlined" class="text-none" @click="familyDialogOpen = false">ยกเลิก</v-btn>
          <v-btn color="admin-primary" prepend-icon="mdi-content-save-outline" class="text-none" :loading="saving" @click="saveFamily">
            บันทึก
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="typeDialogOpen" max-width="620">
      <v-card rounded="xl">
        <div class="dialog-head">
          <div>
            <h2>{{ editingType ? 'แก้ไขประเภทเอกสาร' : 'เพิ่มประเภทเอกสาร' }}</h2>
            <p>ประเภทเอกสารเป็นค่าที่บันทึกในเอกสารจริง</p>
          </div>
          <v-btn icon="mdi-close" variant="text" size="small" @click="typeDialogOpen = false" />
        </div>
        <v-card-text class="dialog-body">
          <v-select
            v-model="typeForm.family_code"
            :items="familyOptions"
            label="กลุ่มประเภท*"
            variant="outlined"
            :rules="[requiredRule]"
            :error-messages="fieldErrors['attrs.family_code']"
          />
          <v-text-field
            :model-value="editingType?.code ?? typeMaster.nextCode.value"
            label="รหัส"
            hint="ระบบสร้างรหัสอัตโนมัติ"
            persistent-hint
            readonly
            bg-color="grey-lighten-4"
            variant="outlined"
          />
          <v-text-field
            v-model="typeForm.name"
            label="ชื่อประเภทเอกสาร*"
            :rules="[requiredRule]"
            :error-messages="fieldErrors.name"
            variant="outlined"
          />
          <v-alert type="info" variant="tonal" density="compact" icon="mdi-source-branch">
            ที่มา/หน่วยนับตามกลุ่ม: {{ selectedTypeFamily ? sourceLabel(familySource(selectedTypeFamily)) : '-' }} · {{ selectedTypeFamily ? unitLabel(familySource(selectedTypeFamily)) : '-' }}
          </v-alert>
        </v-card-text>
        <v-divider />
        <v-card-actions class="justify-end pa-4">
          <v-btn variant="outlined" class="text-none" @click="typeDialogOpen = false">ยกเลิก</v-btn>
          <v-btn color="admin-primary" prepend-icon="mdi-content-save-outline" class="text-none" :loading="saving" @click="saveType">
            บันทึก
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </AppShell>
</template>

<script setup lang="ts">
import Swal from 'sweetalert2';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import AppShell from '../../../components/shared/AppShell.vue';
import MasterTreeTable from '../../../components/master/MasterTreeTable.vue';
import { useMasterData } from '../../../composables/useMasterData';
import { useSnackbarStore } from '../../../stores/snackbarStore';
import { flattenTypeOrder, moveByDrag, moveWithin } from '../../../utils/treeOrder';
import type { ApiRequestError, LawSource } from '../../../api/client';
import type { MasterActiveFilter, MasterItem, UpsertMasterItemPayload } from '../../../types/masterData';

interface MasterTreeRow {
  family: MasterItem;
  types: MasterItem[];
  source: LawSource;
  color: string;
  usage: number;
  locked: boolean;
}

const familyMaster = useMasterData('law_family');
const typeMaster = useMasterData('law_type');
const snackbar = useSnackbarStore();

const query = ref('');
const sourceFilter = ref<'all' | LawSource>('all');
const activeFilter = ref<MasterActiveFilter>('all');
const expandedCodes = ref<Set<string>>(new Set());
const togglingCode = ref<string | null>(null);
const saving = ref(false);
const fieldErrors = ref<Record<string, string[]>>({});
const reorderMode = ref(false);
const familyOrder = ref<string[]>([]);
const typeOrderByFamily = ref<Record<string, string[]>>({});
const reorderSnapshot = ref('');

const familyDialogOpen = ref(false);
const typeDialogOpen = ref(false);
const editingFamily = ref<MasterItem | null>(null);
const editingType = ref<MasterItem | null>(null);

const familyForm = reactive({
  name: '',
  source: 'internal' as LawSource,
  color: '#6B7280',
});

const typeForm = reactive({
  family_code: '',
  name: '',
});

const requiredRule = (value: unknown) => typeof value === 'string' ? !!value.trim() || 'กรุณากรอกข้อมูล' : !!value || 'กรุณากรอกข้อมูล';
const sourceOptions = [
  { title: 'ที่มา: ทั้งหมด', value: 'all' },
  { title: 'ที่มา: ภายใน', value: 'internal' },
  { title: 'ที่มา: ภายนอก', value: 'external' },
];
const familySourceOptions = [
  { title: 'ภายใน · นับเป็น "ข้อ"', value: 'internal' },
  { title: 'ภายนอก · นับเป็น "มาตรา"', value: 'external' },
];
const activeOptions = [
  { title: 'สถานะ: ทั้งหมด', value: 'all' },
  { title: 'สถานะ: ใช้งาน', value: '1' },
  { title: 'สถานะ: ปิดใช้งาน', value: '0' },
];

const rows = computed<MasterTreeRow[]>(() =>
  [...familyMaster.items.value]
    .sort(bySort)
    .map((family) => {
      const types = typeMaster.items.value
        .filter((type) => type.attrs.family_code === family.code)
        .sort(bySort);
      return {
        family,
        types,
        source: familySource(family),
        color: String(family.attrs.color || '#6B7280'),
        usage: types.reduce((sum, type) => sum + (type.usage_count ?? 0), 0),
        locked: isSystemFamily(family),
      };
    }),
);

const filteredRows = computed(() => {
  const needle = query.value.trim().toLowerCase();
  const result = rows.value
    .map((row) => {
      if (sourceFilter.value !== 'all' && row.source !== sourceFilter.value) return null;
      if (!matchesActive(row.family) && !row.types.some(matchesActive)) return null;
      const matchingTypes = row.types.filter((type) => matchesActive(type) && matchesQuery(type, needle));
      const groupMatches = matchesActive(row.family) && matchesQuery(row.family, needle);
      if (needle && !groupMatches && matchingTypes.length === 0) return null;
      const types = needle && !groupMatches ? matchingTypes : row.types.filter(matchesActive);
      return { ...row, types };
    })
    .filter((row): row is MasterTreeRow => row !== null);

  return result;
});

const reorderRows = computed<MasterTreeRow[]>(() => {
  const familyByCode = new Map(familyMaster.items.value.map((family) => [family.code, family]));
  const typeByCode = new Map(typeMaster.items.value.map((type) => [type.code, type]));
  return familyOrder.value
    .map((familyCode) => {
      const family = familyByCode.get(familyCode);
      if (!family) return null;
      const types = (typeOrderByFamily.value[familyCode] ?? [])
        .map((typeCode) => typeByCode.get(typeCode))
        .filter((type): type is MasterItem => !!type);
      return {
        family,
        types,
        source: familySource(family),
        color: String(family.attrs.color || '#6B7280'),
        usage: types.reduce((sum, type) => sum + (type.usage_count ?? 0), 0),
        locked: isSystemFamily(family),
      };
    })
    .filter((row): row is MasterTreeRow => row !== null);
});

const tableRows = computed(() => reorderMode.value ? reorderRows.value : filteredRows.value);

const reorderState = computed(() => JSON.stringify({
  families: familyOrder.value,
  types: typeOrderByFamily.value,
}));

const reorderDirty = computed(() => reorderMode.value && reorderState.value !== reorderSnapshot.value);

// Auto-expand groups that match the search (kept out of the computed to avoid side effects).
watch(filteredRows, (result) => {
  if (!query.value.trim()) return;
  expandedCodes.value = new Set([...expandedCodes.value, ...result.map((row) => row.family.code)]);
});

const statCards = computed(() => {
  const allItems = [...familyMaster.items.value, ...typeMaster.items.value];
  return [
    { label: 'กลุ่ม', value: familyMaster.items.value.length },
    { label: 'ประเภท', value: typeMaster.items.value.length },
    { label: 'ใช้งาน', value: allItems.filter((item) => item.is_active).length },
    { label: 'ปิดใช้งาน', value: allItems.filter((item) => !item.is_active).length },
  ];
});

const familyOptions = computed(() =>
  familyMaster.items.value
    .filter((family) => family.is_active || family.code === typeForm.family_code)
    .sort(bySort)
    .map((family) => ({ title: family.name, value: family.code })),
);
const selectedTypeFamily = computed(() =>
  familyMaster.items.value.find((family) => family.code === typeForm.family_code) ?? null,
);

onMounted(async () => {
  familyMaster.perPage.value = 200;
  typeMaster.perPage.value = 200;
  await Promise.all([familyMaster.fetch(), typeMaster.fetch()]);
  expandedCodes.value = new Set(familyMaster.items.value.map((family) => family.code));
});

watch(typeDialogOpen, (open) => {
  if (!open) fieldErrors.value = {};
});

watch(familyDialogOpen, (open) => {
  if (!open) fieldErrors.value = {};
});

function bySort(a: MasterItem, b: MasterItem): number {
  const sort = (a.sort_order ?? 9999) - (b.sort_order ?? 9999);
  if (sort !== 0) return sort;
  return a.code.localeCompare(b.code, 'th');
}

function isSystemFamily(family: MasterItem): boolean {
  return family.is_system || /^LFM0[1-4]$/u.test(family.code);
}

function familySource(family: MasterItem): LawSource {
  return family.attrs.source === 'external' ? 'external' : 'internal';
}

function sourceLabel(source: LawSource): string {
  return source === 'external' ? 'ภายนอก' : 'ภายใน';
}

function unitLabel(source: LawSource): string {
  return source === 'external' ? 'มาตรา' : 'ข้อ';
}

function matchesActive(item: MasterItem): boolean {
  if (activeFilter.value === '1') return item.is_active;
  if (activeFilter.value === '0') return !item.is_active;
  return true;
}

function matchesQuery(item: MasterItem, needle: string): boolean {
  if (!needle) return true;
  return item.code.toLowerCase().includes(needle) || item.name.toLowerCase().includes(needle);
}

function resetFilters(): void {
  query.value = '';
  sourceFilter.value = 'all';
  activeFilter.value = 'all';
}

function toggleExpand(code: string): void {
  const next = new Set(expandedCodes.value);
  if (next.has(code)) next.delete(code);
  else next.add(code);
  expandedCodes.value = next;
}

function openCreateFamily(): void {
  editingFamily.value = null;
  fieldErrors.value = {};
  familyForm.name = '';
  familyForm.source = 'internal';
  familyForm.color = '#6B7280';
  familyDialogOpen.value = true;
}

function openEditFamily(family: MasterItem): void {
  editingFamily.value = family;
  fieldErrors.value = {};
  familyForm.name = family.name;
  familyForm.source = familySource(family);
  familyForm.color = String(family.attrs.color || '#6B7280');
  familyDialogOpen.value = true;
}

function openCreateType(family?: MasterItem): void {
  editingType.value = null;
  fieldErrors.value = {};
  typeForm.family_code = family?.code ?? familyMaster.items.value[0]?.code ?? '';
  typeForm.name = '';
  typeDialogOpen.value = true;
}

function openEditType(type: MasterItem): void {
  editingType.value = type;
  fieldErrors.value = {};
  typeForm.family_code = String(type.attrs.family_code || '');
  typeForm.name = type.name;
  typeDialogOpen.value = true;
}

function familyPayload(): UpsertMasterItemPayload {
  return {
    name: familyForm.name,
    attrs: {
      source: familyForm.source,
      color: familyForm.color,
    },
  };
}

function typePayload(): UpsertMasterItemPayload {
  return {
    name: typeForm.name,
    attrs: {
      family_code: typeForm.family_code,
    },
  };
}

async function saveFamily(): Promise<void> {
  saving.value = true;
  fieldErrors.value = {};
  try {
    await familyMaster.save(familyPayload(), editingFamily.value?.code);
    familyDialogOpen.value = false;
    await typeMaster.fetch();
  } catch (err) {
    fieldErrors.value = (err as ApiRequestError).errors ?? {};
  } finally {
    saving.value = false;
  }
}

async function saveType(): Promise<void> {
  saving.value = true;
  fieldErrors.value = {};
  const previousFamilyCode = editingType.value ? String(editingType.value.attrs.family_code || '') : '';
  try {
    const saved = await typeMaster.save(typePayload(), editingType.value?.code);
    if (editingType.value && previousFamilyCode !== typeForm.family_code) {
      await moveSavedTypeToFamilyEnd(saved.code, typeForm.family_code);
    }
    typeDialogOpen.value = false;
    expandedCodes.value = new Set([...expandedCodes.value, typeForm.family_code]);
  } catch (err) {
    fieldErrors.value = (err as ApiRequestError).errors ?? {};
  } finally {
    saving.value = false;
  }
}

function currentOrder(): { families: string[]; types: Record<string, string[]> } {
  const orderedRows = rows.value;
  return {
    families: orderedRows.map((row) => row.family.code),
    types: Object.fromEntries(orderedRows.map((row) => [row.family.code, row.types.map((type) => type.code)])),
  };
}

function setDraftOrder(order: { families: string[]; types: Record<string, string[]> }): void {
  familyOrder.value = [...order.families];
  typeOrderByFamily.value = Object.fromEntries(
    order.families.map((familyCode) => [familyCode, [...(order.types[familyCode] ?? [])]]),
  );
}

function startReorder(): void {
  resetFilters();
  const order = currentOrder();
  setDraftOrder(order);
  reorderSnapshot.value = JSON.stringify(order);
  reorderMode.value = true;
  expandedCodes.value = new Set(order.families);
}

function cancelReorder(): void {
  if (reorderSnapshot.value) {
    setDraftOrder(JSON.parse(reorderSnapshot.value) as { families: string[]; types: Record<string, string[]> });
  }
  reorderMode.value = false;
}

async function saveReorder(): Promise<void> {
  saving.value = true;
  try {
    await familyMaster.reorder(familyOrder.value);
    await typeMaster.reorder(flattenTypeOrder(familyOrder.value, typeOrderByFamily.value));
    snackbar.success('บันทึกลำดับแล้ว');
    await Promise.all([familyMaster.fetch(), typeMaster.fetch()]);
    reorderMode.value = false;
  } catch (err) {
    snackbar.error(err instanceof Error ? err.message : 'บันทึกลำดับไม่สำเร็จ');
  } finally {
    saving.value = false;
  }
}

function moveFamily(code: string, dir: 'up' | 'down'): void {
  familyOrder.value = moveWithin(familyOrder.value.map((item) => ({ code: item })), code, dir).map((item) => item.code);
}

function moveType(familyCode: string, code: string, dir: 'up' | 'down'): void {
  typeOrderByFamily.value = {
    ...typeOrderByFamily.value,
    [familyCode]: moveWithin((typeOrderByFamily.value[familyCode] ?? []).map((item) => ({ code: item })), code, dir).map((item) => item.code),
  };
}

function dragFamily(fromCode: string, toCode: string): void {
  familyOrder.value = moveByDrag(familyOrder.value.map((item) => ({ code: item })), fromCode, toCode).map((item) => item.code);
}

function dragType(familyCode: string, fromCode: string, toCode: string): void {
  typeOrderByFamily.value = {
    ...typeOrderByFamily.value,
    [familyCode]: moveByDrag((typeOrderByFamily.value[familyCode] ?? []).map((item) => ({ code: item })), fromCode, toCode).map((item) => item.code),
  };
}

async function moveSavedTypeToFamilyEnd(typeCode: string, familyCode: string): Promise<void> {
  const order = currentOrder();
  const nextTypes = Object.fromEntries(
    order.families.map((code) => [code, (order.types[code] ?? []).filter((item) => item !== typeCode)]),
  );
  nextTypes[familyCode] = [...(nextTypes[familyCode] ?? []), typeCode];
  await typeMaster.reorder(flattenTypeOrder(order.families, nextTypes));
}

onBeforeRouteLeave(() => {
  if (!reorderDirty.value) return true;
  return window.confirm('ยังไม่ได้บันทึกลำดับ ต้องการออกจากหน้านี้หรือไม่');
});

async function confirmDeactivate(item: MasterItem): Promise<boolean> {
  const result = await Swal.fire({
    icon: 'warning',
    title: `ปิดใช้งาน "${item.name}"?`,
    html: `รายการนี้จะไม่แสดงเป็นตัวเลือกในระบบ<br>เอกสารที่ใช้อยู่ ${(item.usage_count ?? 0).toLocaleString('th-TH')} รายการจะยังคงแสดงค่าเดิม`,
    showCancelButton: true,
    confirmButtonText: 'ปิดใช้งาน',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#b42318',
    cancelButtonColor: '#64748b',
  });
  return result.isConfirmed;
}

async function handleToggleFamily(family: MasterItem, next: boolean): Promise<void> {
  if (isSystemFamily(family)) return;
  if (!next && !(await confirmDeactivate(family))) return;
  togglingCode.value = family.code;
  try {
    await familyMaster.toggleActive(family, next);
    await typeMaster.fetch();
  } finally {
    togglingCode.value = null;
  }
}

async function handleToggleType(type: MasterItem, next: boolean): Promise<void> {
  if (type.is_system) return;
  if (!next && !(await confirmDeactivate(type))) return;
  togglingCode.value = type.code;
  try {
    await typeMaster.toggleActive(type, next);
  } finally {
    togglingCode.value = null;
  }
}
</script>

<style scoped>
.law-type-tree-page__title-actions {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.law-type-tree-page {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.law-type-tree-page__stats {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.law-type-tree-page__filters {
  align-items: center;
  display: grid;
  gap: 12px;
  grid-template-columns: minmax(220px, 1fr) 180px 180px auto;
  padding: 14px;
}

.dialog-head {
  align-items: flex-start;
  display: flex;
  justify-content: space-between;
  padding: 20px 22px 8px;
}

.dialog-head h2 {
  font-size: 18px;
  font-weight: 800;
  margin: 0;
}

.dialog-head p {
  color: #667085;
  font-size: 13px;
  margin: 4px 0 0;
}

.dialog-body {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px 22px 22px;
}

@media (max-width: 900px) {
  .law-type-tree-page__stats,
  .law-type-tree-page__filters {
    grid-template-columns: 1fr;
  }
}
</style>
