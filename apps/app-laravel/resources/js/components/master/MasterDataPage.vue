<template>
  <component :is="embedded ? 'div' : AppShell" v-bind="shellProps">
    <template v-if="!embedded && !readonly && !hideCreateAction" #title-actions>
      <v-btn color="admin-primary" prepend-icon="mdi-plus" class="text-none" @click="openCreate">
        {{ addLabel }}
      </v-btn>
    </template>

    <div v-if="embedded && !readonly && !hideCreateAction" class="master-page__embedded-actions">
      <v-btn color="admin-primary" prepend-icon="mdi-plus" class="text-none" @click="openCreate">
        {{ addLabel }}
      </v-btn>
    </div>

    <div class="master-page">
      <MasterStatCards :stats="master.stats.value" :labels="statLabels" />

      <v-card flat border rounded="lg" class="master-page__filters">
        <v-text-field
          v-model="master.q.value"
          :placeholder="searchPlaceholder"
          prepend-inner-icon="mdi-magnify"
          variant="outlined"
          density="comfortable"
          hide-details
          clearable
        />
        <v-select
          v-model="master.activeFilter.value"
          :items="activeOptions"
          variant="outlined"
          density="comfortable"
          hide-details
          class="master-page__status-filter"
        />
        <v-btn variant="outlined" prepend-icon="mdi-refresh" class="text-none" @click="master.resetFilters">
          ล้างตัวกรอง
        </v-btn>
      </v-card>

      <v-alert v-if="master.error.value" type="error" variant="tonal" density="compact">
        {{ master.error.value }}
      </v-alert>

      <MasterDataTable
        :kind="kind"
        :items="master.items.value"
        :columns="columns"
        :loading="master.loading.value"
        :page="master.page.value"
        :per-page="master.perPage.value"
        :total="master.total.value"
        :page-count="master.pageCount.value"
        :toggling-code="togglingCode"
        :readonly="readonly"
        :code-column-title="codeColumnTitle"
        :switch-epoch="switchEpoch"
        @view="openView"
        @edit="openEdit"
        @delete="handleDelete"
        @toggle="handleToggle"
        @update:page="master.page.value = $event"
      >
        <template v-for="column in columns" :key="column.key" #[`item.${column.key}`]="slotProps">
          <slot :name="`item.${column.key}`" v-bind="slotProps" />
        </template>
      </MasterDataTable>
    </div>

    <MasterItemDialog
      v-model="dialogOpen"
      :title="editingItem ? dialogTitle.edit : dialogTitle.create"
      :subtitle="dialogSubtitle"
      :kind="kind"
      :item="editingItem"
      :next-code="master.nextCode.value"
      :field-labels="fieldLabels"
      :loading="saving"
      :errors="fieldErrors"
      :max-width="dialogMaxWidth"
      :basic-section-title="basicSectionTitle"
      :code-placeholder="codePlaceholder"
      :name-placeholder="namePlaceholder"
      :description-placeholder="descriptionPlaceholder"
      @save="handleSave"
    >
      <template #attrs="{ form, errors }">
        <slot name="attrs" :form="form" :item="editingItem" :errors="errors" />
      </template>
      <template #after-basic="{ form, errors }">
        <slot name="after-basic" :form="form" :item="editingItem" :errors="errors" />
      </template>
    </MasterItemDialog>

    <slot
      v-if="$slots['view-dialog']"
      name="view-dialog"
      :open="viewOpen"
      :item="viewItem"
      :close="closeView"
    />
    <MasterItemViewDialog
      v-else
      v-model="viewOpen"
      :title="title"
      :item="viewItem"
      :field-labels="fieldLabels"
    />
  </component>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import AppShell from '../shared/AppShell.vue';
import MasterDataTable from './MasterDataTable.vue';
import MasterItemDialog from './MasterItemDialog.vue';
import MasterItemViewDialog from './MasterItemViewDialog.vue';
import { confirmDeactivate, confirmDelete } from './masterDialogs';
import MasterStatCards from './MasterStatCards.vue';
import { useMasterData } from '../../composables/useMasterData';
import type { ApiRequestError } from '../../api/client';
import type { MasterItem, MasterKind, UpsertMasterItemPayload } from '../../types/masterData';

const props = defineProps<{
  kind: MasterKind;
  title: string;
  subtitle: string;
  breadcrumbs?: string[];
  addLabel: string;
  statLabels: { total: string; active: string; inactive: string };
  columns: { key: string; title: string; width?: string; align?: 'start' | 'end' | 'center' }[];
  searchPlaceholder: string;
  dialogTitle: { create: string; edit: string };
  dialogSubtitle: string;
  fieldLabels: { code: string; name: string };
  embedded?: boolean;
  hideCreateAction?: boolean;
  dialogMaxWidth?: string | number;
  basicSectionTitle?: string;
  codePlaceholder?: string;
  namePlaceholder?: string;
  descriptionPlaceholder?: string;
  readonly?: boolean;
  codeColumnTitle?: string;
}>();

const master = useMasterData(props.kind);
const dialogOpen = ref(false);
const viewOpen = ref(false);
const editingItem = ref<MasterItem | null>(null);
const viewItem = ref<MasterItem | null>(null);
const saving = ref(false);
const togglingCode = ref<string | null>(null);
const fieldErrors = ref<Record<string, string[]>>({});
const switchEpoch = ref(0);

const resolvedBreadcrumbs = computed(() => props.breadcrumbs ?? ['จัดการข้อมูลระบบ', props.title]);
const shellProps = computed(() => props.embedded ? {} : {
  breadcrumbs: resolvedBreadcrumbs.value,
  title: props.title,
  subtitle: props.subtitle,
  showBell: true,
});

const activeOptions = [
  { title: 'สถานะ: ทั้งหมด', value: 'all' },
  { title: 'สถานะ: ใช้งาน', value: '1' },
  { title: 'สถานะ: ปิดใช้งาน', value: '0' },
];

onMounted(() => {
  void master.fetch();
});

function openCreate(): void {
  editingItem.value = null;
  fieldErrors.value = {};
  dialogOpen.value = true;
}

function openEdit(item: MasterItem): void {
  editingItem.value = item;
  fieldErrors.value = {};
  dialogOpen.value = true;
}

function openView(item: MasterItem): void {
  viewItem.value = item;
  viewOpen.value = true;
}

function closeView(): void {
  viewOpen.value = false;
}

async function handleToggle(item: MasterItem, next: boolean): Promise<void> {
  if (!next && !(await confirmDeactivate(item))) {
    switchEpoch.value++;
    return;
  }

  togglingCode.value = item.code;
  try {
    await master.toggleActive(item, next);
  } catch {
    // useMasterData already reported the API error (including 409); just resync the switch.
    switchEpoch.value++;
  } finally {
    togglingCode.value = null;
  }
}

async function handleDelete(item: MasterItem): Promise<void> {
  if (!item.can_delete || !(await confirmDelete(item))) return;
  try {
    await master.remove(item);
  } catch {
    // The composable reports errors through masterDialogs.
  }
}

defineExpose({ openCreate });

async function handleSave(payload: UpsertMasterItemPayload): Promise<void> {
  if (editingItem.value?.is_active && payload.is_active === false) {
    const confirmed = await confirmDeactivate(editingItem.value);
    if (!confirmed) return;
  }

  saving.value = true;
  fieldErrors.value = {};
  try {
    await master.save(payload, editingItem.value?.code);
    dialogOpen.value = false;
  } catch (err) {
    const apiError = err as ApiRequestError;
    fieldErrors.value = apiError.errors ?? {};
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.master-page {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.master-page__embedded-actions {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 12px;
}

.master-page__filters {
  align-items: center;
  display: grid;
  gap: 12px;
  grid-template-columns: minmax(220px, 1fr) 220px auto;
  padding: 14px;
}

.master-page__status-filter {
  min-width: 0;
}

@media (max-width: 820px) {
  .master-page__filters {
    grid-template-columns: 1fr;
  }
}
</style>
