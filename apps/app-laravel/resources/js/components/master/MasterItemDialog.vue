<template>
  <v-dialog :model-value="modelValue" :max-width="maxWidth" @update:model-value="$emit('update:modelValue', Boolean($event))">
    <v-card rounded="xl" class="master-dialog">
      <div class="master-dialog__header">
        <div class="min-width-0">
          <h2 class="master-dialog__title">{{ title }}</h2>
          <p v-if="subtitle" class="master-dialog__subtitle">{{ subtitle }}</p>
        </div>
        <v-btn icon="mdi-close" variant="text" size="small" @click="$emit('update:modelValue', false)" />
      </div>

      <v-card-text class="master-dialog__body">
        <section class="master-dialog__section">
          <div class="master-dialog__section-title">
            <v-icon icon="mdi-pencil" size="18" color="admin-primary" />
            <span>{{ basicSectionTitle }}</span>
          </div>
          <v-text-field
            :model-value="item?.code ?? nextCode"
            :label="fieldLabels.code"
            :hint="editing ? undefined : 'ระบบสร้างรหัสอัตโนมัติ'"
            :persistent-hint="!editing"
            readonly
            bg-color="grey-lighten-4"
            variant="outlined"
            density="comfortable"
            :placeholder="codePlaceholder"
          />
          <v-text-field
            v-model="form.name"
            :label="`${fieldLabels.name}*`"
            :placeholder="namePlaceholder"
            :rules="[requiredRule]"
            :error-messages="fieldError('name')"
            variant="outlined"
            density="comfortable"
          />
          <v-textarea
            v-model="form.description"
            label="คำอธิบาย"
            :placeholder="descriptionPlaceholder"
            rows="3"
            :error-messages="fieldError('description')"
            variant="outlined"
            density="comfortable"
          />
          <slot name="attrs" :form="form" :item="item" :errors="errors ?? {}" />
        </section>

        <slot name="after-basic" :form="form" :item="item" :errors="errors ?? {}" />

        <section v-if="editing" class="master-dialog__section">
          <div class="master-dialog__section-title">
            <v-icon icon="mdi-eye-outline" size="18" color="admin-primary" />
            <span>สถานะการใช้งาน</span>
          </div>
          <div class="master-dialog__active-card">
            <div>
              <div class="text-body-2 font-weight-bold">เปิดใช้งาน</div>
              <div class="text-caption text-medium-emphasis">
                เปิดใช้งานเพื่อแสดงเป็นตัวเลือกในระบบ
              </div>
            </div>
            <div class="master-dialog__switch" :class="{ 'master-dialog__switch--locked': lock }">
              <div class="master-dialog__switch-row">
                <v-icon v-if="lock" icon="mdi-lock" size="14" class="master-dialog__lock" />
                <v-switch
                  :model-value="lock ? true : form.is_active"
                  :readonly="!!lock"
                  :aria-disabled="lock ? 'true' : undefined"
                  :aria-label="lock ? lockLabel : undefined"
                  color="success"
                  inset
                  hide-details
                  @update:model-value="onActiveChange"
                />
              </div>
            </div>
          </div>
          <div v-if="lock" class="master-dialog__lock-note" data-test="lock-note">
            <v-icon icon="mdi-lock" size="14" />
            <div>
              <div class="font-weight-bold">{{ lock.reason }}</div>
              <div>{{ lock.hint }}</div>
            </div>
          </div>
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
import { lockAriaLabel, lockReason } from './masterLock';
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
const maxWidth = computed(() => props.maxWidth ?? 640);
const basicSectionTitle = computed(() => props.basicSectionTitle ?? 'ข้อมูลพื้นฐาน');
const lock = computed(() => (props.item && props.kind ? lockReason(props.item, props.kind) : null));
const lockLabel = computed(() => (props.item && props.kind ? lockAriaLabel(props.item, props.kind) : ''));
const requiredRule = (value: string) => !!value?.trim() || 'กรุณากรอกข้อมูล';

watch(() => [props.modelValue, props.item] as const, () => {
  if (!props.modelValue) return;
  form.name = props.item?.name ?? '';
  form.description = props.item?.description ?? '';
  form.is_active = props.item?.is_active ?? false;
  form.attrs = { ...(props.item?.attrs ?? {}) };
}, { immediate: true });

function onActiveChange(value: unknown): void {
  if (lock.value) return;
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

.master-dialog__lock {
  color: #475467;
  cursor: not-allowed;
}

.master-dialog__switch--locked :deep(.v-switch) {
  opacity: 0.55;
}

.master-dialog__switch--locked :deep(.v-selection-control),
.master-dialog__switch--locked :deep(input) {
  cursor: not-allowed;
}

.master-dialog__lock-note {
  align-items: flex-start;
  background: #f2f4f7;
  border-radius: 8px;
  color: #475467;
  display: flex;
  font-size: 13px;
  gap: 8px;
  line-height: 1.5;
  padding: 10px 12px;
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
</style>
