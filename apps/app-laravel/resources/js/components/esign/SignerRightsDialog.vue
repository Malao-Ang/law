<template>
  <v-dialog :model-value="modelValue" max-width="560" persistent @update:model-value="emitClose">
    <v-card rounded="xl" class="signer-dialog">
      <div class="signer-dialog__header">
        <div class="text-subtitle-1 font-weight-bold">กำหนดสิทธิ์ผู้ลงนามเอกสาร</div>
        <v-btn icon="mdi-close" variant="text" size="small" @click="emitClose(false)" />
      </div>

      <v-card-text class="pt-2 pb-0">
        <div class="text-caption font-weight-bold text-medium-emphasis mb-2">
          ขั้นตอนที่ 1: เลือกประเภทผู้ลงนาม
        </div>

        <div class="signer-type-grid mb-4">
          <button
            v-for="option in TYPE_OPTIONS"
            :key="option.key"
            type="button"
            class="signer-type-card"
            :class="{ 'is-selected': roleType === option.key }"
            @click="selectType(option.key)"
          >
            <v-avatar :color="option.color" size="40" class="mb-2">
              <v-icon :icon="option.icon" color="white" size="20" />
            </v-avatar>
            <div class="text-body-2 font-weight-bold">{{ option.title }}</div>
            <div class="text-caption text-medium-emphasis">{{ option.subtitle }}</div>
          </button>
        </div>

        <template v-if="presetPerson">
          <div class="text-caption font-weight-bold text-medium-emphasis mb-2">
            ขั้นตอนที่ 2: ตรวจสอบข้อมูลบุคคล
          </div>
          <div class="signer-person-card">
            <v-avatar color="admin-primary" size="44">
              <span class="text-caption font-weight-bold">{{ initials(presetPerson.name) }}</span>
            </v-avatar>
            <div class="min-width-0">
              <div class="text-body-2 font-weight-bold">
                {{ presetPerson.name }}
                <span class="text-medium-emphasis font-weight-regular">• {{ presetPerson.position }}</span>
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ presetPerson.department }}
                <span v-if="presetPerson.employeeId">(รหัสพนักงาน: {{ presetPerson.employeeId }})</span>
              </div>
            </div>
          </div>
        </template>
      </v-card-text>

      <v-card-actions class="px-6 py-4">
        <v-spacer />
        <v-btn variant="text" class="text-none" @click="emitClose(false)">ยกเลิก</v-btn>
        <v-btn
          color="admin-primary"
          class="text-none"
          :disabled="!canConfirm"
          @click="confirm"
        >เลือกผู้ลงนาม</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { ESignPerson, ESignSigner, ESignSignerRole } from '../../types/esign';
import { createClientId } from '../../utils/createClientId';
import {
  COUNCIL_CHAIR_PERSON,
  PRESIDENT_PERSON,
} from '../../data/esignSigners';

const props = defineProps<{
  modelValue: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
  confirm: [signer: ESignSigner];
}>();

const TYPE_OPTIONS: Array<{
  key: ESignSignerRole;
  title: string;
  subtitle: string;
  icon: string;
  color: string;
}> = [
  {
    key: 'president',
    title: 'อธิการบดี',
    subtitle: 'ผู้ลงนามหลักของมหาวิทยาลัย',
    icon: 'mdi-school-outline',
    color: 'admin-primary',
  },
  {
    key: 'council',
    title: 'นายกสภาฯ',
    subtitle: 'ผู้ลงนามในฐานะนายกสภาฯ มหาวิทยาลัย',
    icon: 'mdi-domain',
    color: '#0f766e',
  },
];

const roleType = ref<ESignSignerRole | null>(null);

const presetPerson = computed<ESignPerson | null>(() => {
  if (roleType.value === 'president') return PRESIDENT_PERSON;
  if (roleType.value === 'council') return COUNCIL_CHAIR_PERSON;
  return null;
});

const canConfirm = computed(() => Boolean(presetPerson.value));

function initials(name: string): string {
  const cleaned = name.replace(/^(ศ\.ดร\.|รศ\.ดร\.|ผศ\.ดร\.|ดร\.|นาย|นาง|นางสาว)\s*/u, '').trim();
  const parts = cleaned.split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2);
  return `${parts[0][0] ?? ''}${parts[1][0] ?? ''}`;
}

function selectType(type: ESignSignerRole): void {
  roleType.value = type;
}

function reset(): void {
  roleType.value = null;
}

function emitClose(value: boolean): void {
  emit('update:modelValue', value);
  if (!value) reset();
}

function confirm(): void {
  if (!canConfirm.value || !roleType.value || !presetPerson.value) return;

  const person = presetPerson.value;
  emit('confirm', {
    id: createClientId('signer'),
    roleType: roleType.value,
    name: person.name,
    position: person.position,
    department: person.department,
    employeeId: person.employeeId,
  });

  emitClose(false);
}

watch(() => props.modelValue, (open) => {
  if (open) reset();
});
</script>

<style scoped>
.signer-dialog__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px 8px;
}

.signer-type-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.signer-type-card {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  text-align: left;
  gap: 2px;
  border: 1.5px solid #e2e8f0;
  border-radius: 14px;
  background: #fff;
  padding: 12px;
  cursor: pointer;
  font: inherit;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}

.signer-type-card:hover {
  border-color: #93c5fd;
  background: #f8fbff;
}

.signer-type-card.is-selected {
  border-color: #1e3a8a;
  background: #eff6ff;
  box-shadow: 0 0 0 1px #1e3a8a inset;
}

.signer-person-card {
  display: flex;
  align-items: center;
  gap: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 12px;
  background: #f8fafc;
}

@media (max-width: 600px) {
  .signer-type-grid {
    grid-template-columns: 1fr;
  }
}
</style>
