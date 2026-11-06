<template>
  <v-dialog :model-value="modelValue" :max-width="maxWidth" @update:model-value="$emit('update:modelValue', Boolean($event))">
    <v-card rounded="xl" class="master-dialog">
      <div class="master-dialog__header">
        <div class="min-width-0">
          <h2 class="master-dialog__title">{{ title }}</h2>
          <p v-if="subtitle" class="master-dialog__subtitle">{{ subtitle }}</p>
        </div>
        <v-btn icon="mdi-close" variant="text" size="32" aria-label="ปิด" @click="$emit('update:modelValue', false)" />
      </div>

      <v-divider />
      <v-card-text class="master-dialog__body">
        <section class="master-dialog__section">
          <div class="master-dialog__section-title">
            <v-icon icon="mdi-pencil" size="18" color="admin-primary" />
            <span>{{ basicSectionTitle }}</span>
          </div>
          <label v-if="kind !== 'change_status'" for="master-code" class="master-dialog__label">{{ fieldLabels.code }}</label>
          <v-text-field
            v-if="kind !== 'change_status'"
            id="master-code"
            :model-value="item?.code ?? nextCode"
            :hint="editing ? undefined : 'ระบบสร้างรหัสอัตโนมัติ'"
            :persistent-hint="!editing"
            readonly
            bg-color="#e5e7eb"
            variant="outlined"
            density="compact"
            :placeholder="codePlaceholder"
          />
          <label for="master-name" class="master-dialog__label">{{ fieldLabels.name }} <span class="text-error">*</span></label>
          <v-text-field
            id="master-name"
            v-model="form.name"
            :placeholder="namePlaceholder"
            :rules="[requiredRule]"
            :error-messages="fieldError('name')"
            variant="outlined"
            density="compact"
          />
          <label for="master-description" class="master-dialog__label">คำอธิบาย</label>
          <v-textarea
            id="master-description"
            v-model="form.description"
            :placeholder="descriptionPlaceholder"
            rows="3"
            :error-messages="fieldError('description')"
            variant="outlined"
            density="compact"
          />
          <slot name="attrs" :form="form" :item="item" :errors="errors ?? {}" />
        </section>

        <slot name="after-basic" :form="form" :item="item" :errors="errors ?? {}" />

        <section v-if="editing" class="master-dialog__section">
          <div class="master-dialog__section-title">
            <v-icon icon="mdi-eye-outline" size="18" color="admin-primary" />
            <span>สถานะการใช้งาน</span>
          </div>
          <v-card variant="outlined" class="master-dialog__active-card">
            <div>
              <div class="text-body-2 font-weight-bold">สถานะการใช้งาน</div>
              <div class="text-caption text-medium-emphasis">
                เปิดใช้งานเพื่อแสดงเป็นตัวเลือกในระบบ
              </div>
            </div>
            <div class="master-dialog__switch">
              <div class="master-dialog__switch-row">
                <v-switch
                  :model-value="form.is_active"
                  color="success"
                  inset
                  hide-details
                  @update:model-value="onActiveChange"
                />
              </div>
            </div>
          </v-card>
        </section>
      </v-card-text>

      <v-divider />
      <v-card-actions class="master-dialog__footer">
        <v-btn variant="outlined" class="text-none" @click="$emit('update:modelValue', false)">ยกเลิก</v-btn>
        <v-btn
          color="admin-primary"
          variant="flat"
          prepend-icon="mdi-content-save-outline"
          class="text-none"
          :loading="loading"
          @click="submit"
        >
          บันทึก
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import type { MasterItem, MasterKind, UpsertMasterItemPayload } from '../../types/masterData';

const props = defineProps<{
  modelValue: boolean;
  title: string;
  subtitle: string;
  kind?: MasterKind;
  item?: MasterItem | null;
  nextCode: string;
  fieldLabels: { code: string; name: string };
  loading?: boolean;
  errors?: Record<string, string[]>;
  maxWidth?: string | number;
  basicSectionTitle?: string;
  codePlaceholder?: string;
  namePlaceholder?: string;
  descriptionPlaceholder?: string;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
  save: [payload: UpsertMasterItemPayload];
}>();

const form = reactive<UpsertMasterItemPayload>({
  name: '',
  description: '',
  is_active: false,
  attrs: {},
});

const editing = computedEditing();
const maxWidth = computed(() => Math.min(Number(props.maxWidth) || 672, 672));
const basicSectionTitle = computed(() => props.basicSectionTitle ?? 'ข้อมูลพื้นฐาน');
const requiredRule = (value: string) => !!value?.trim() || 'กรุณากรอกข้อมูล';

watch(() => [props.modelValue, props.item] as const, () => {
  if (!props.modelValue) return;
  form.name = props.item?.name ?? '';
  form.description = props.item?.description ?? '';
  form.is_active = props.item?.is_active ?? false;
  form.attrs = { ...(props.item?.attrs ?? {}) };
}, { immediate: true });

function onActiveChange(value: unknown): void {
  form.is_active = Boolean(value);
}

function fieldError(field: string): string[] {
  return props.errors?.[field] ?? [];
}

function submit(): void {
  emit('save', {
    name: form.name,
    description: form.description,
    ...(props.item ? { is_active: form.is_active } : {}),
    attrs: form.attrs ?? {},
  });
}

function computedEditing() {
  return computed(() => !!props.item);
}
</script>

<style scoped>
.master-dialog__switch-row {
  align-items: center;
  display: flex;
  gap: 6px;
}

.master-dialog {
  display: flex;
  flex-direction: column;
  max-height: 90dvh;
  overflow: hidden;
}

.master-dialog__header {
  align-items: flex-start;
  display: flex;
  gap: 16px;
  justify-content: space-between;
  padding: 20px 22px 12px;
}

.master-dialog__title {
  color: #1f2933;
  font-size: 18px;
  font-weight: 800;
  line-height: 1.35;
  margin: 0;
}

.master-dialog__subtitle {
  color: #667085;
  font-size: 13px;
  line-height: 1.45;
  margin: 4px 0 0;
}

.master-dialog__body {
  display: flex;
  flex-direction: column;
  gap: 18px;
  overflow-y: auto;
  padding: 8px 22px 22px;
}

.master-dialog__section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.master-dialog__section-title {
  align-items: center;
  color: #1f2933;
  display: flex;
  font-size: 14px;
  font-weight: 800;
  gap: 8px;
}

.master-dialog__active-card {
  align-items: center;
  background: #f9fafb;
  border: 1px solid #eaecf0;
  border-radius: 12px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;
  justify-content: space-between;
  padding: 14px 16px;
}

.master-dialog__footer {
  justify-content: flex-end;
  padding: 14px 22px;
}

.min-width-0 {
  min-width: 0;
}
.master-dialog { border-radius: 16px !important; }
.master-dialog__header { padding: 20px 28px; min-height: 80px; }
.master-dialog__title { color: #1e3a8a; font-size: 20px; font-weight: 700; }
.master-dialog__subtitle { color: #64748b; }
.master-dialog__body { padding: 24px 28px; }
.master-dialog__label { font-size: 14px; font-weight: 600; color: #1e293b; margin-top: 6px; }
.master-dialog__section { gap: 6px; }
.master-dialog__section-title { display: none; }
.master-dialog__body :deep(.v-text-field .v-field) { --v-input-control-height: 42px; }
.master-dialog__active-card { background: #f8fafc; border-color: #e2e8f0; border-radius: 10px; box-shadow: none; }
.master-dialog__footer { background: #f8fafc; padding: 16px 28px; }
.master-dialog__footer :deep(.v-btn) { border-radius: 6px; }
</style>
