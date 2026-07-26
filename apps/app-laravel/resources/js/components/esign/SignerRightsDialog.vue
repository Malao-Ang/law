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

        <template v-if="roleType === 'president' || roleType === 'council'">
          <div class="text-caption font-weight-bold text-medium-emphasis mb-2">
            ขั้นตอนที่ 2: ตรวจสอบข้อมูลบุคคล
          </div>
          <div v-if="presetPerson" class="signer-person-card">
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

        <template v-else-if="roleType === 'delegate'">
          <v-alert type="warning" variant="tonal" density="comfortable" class="mb-3">
            ใช้เฉพาะกรณีรักษาการหรือได้รับมอบหมายให้ลงนามแทนเท่านั้น
          </v-alert>

          <div class="text-caption font-weight-bold text-medium-emphasis mb-2">
            ขั้นตอนที่ 2: ค้นหาและเลือกผู้รับมอบอำนาจ
          </div>

          <v-text-field
            v-model="search"
            density="comfortable"
            variant="outlined"
            hide-details
            placeholder="ค้นหาชื่อผู้ลงนาม..."
            prepend-inner-icon="mdi-magnify"
            class="mb-3"
          />

          <div class="signer-search-list mb-3">
            <button
              v-for="person in filteredDelegates"
              :key="person.id"
              type="button"
              class="signer-search-item"
              :class="{ 'is-selected': selectedDelegateId === person.id }"
              @click="selectedDelegateId = person.id"
            >
              <v-avatar color="admin-primary" size="36">
                <span class="text-caption font-weight-bold">{{ initials(person.name) }}</span>
              </v-avatar>
              <div class="min-width-0 flex-grow-1 text-left">
                <div class="text-body-2 font-weight-bold text-truncate">{{ person.name }}</div>
                <div class="text-caption text-medium-emphasis text-truncate">
                  {{ person.position }} • {{ person.department }}
                  <span v-if="person.employeeId">({{ person.employeeId }})</span>
                </div>
              </div>
              <v-icon
                :icon="selectedDelegateId === person.id ? 'mdi-radiobox-marked' : 'mdi-radiobox-blank'"
                :color="selectedDelegateId === person.id ? 'admin-primary' : 'grey'"
              />
            </button>
            <div v-if="filteredDelegates.length === 0" class="text-caption text-medium-emphasis text-center pa-4">
              ไม่พบรายชื่อที่ตรงกับคำค้น
            </div>
          </div>

          <v-textarea
            v-model="delegateNote"
            label="หมายเหตุการลงนามแทน *"
            variant="outlined"
            rows="2"
            auto-grow
            hide-details="auto"
            :rules="[(v) => !!String(v || '').trim() || 'จำเป็นต้องระบุหมายเหตุ']"
          />
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
  DELEGATE_CANDIDATES,
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
  {
    key: 'delegate',
    title: 'ผู้รับมอบอำนาจ',
    subtitle: 'กรณีรักษาการหรือสิทธิ์ลงนามแทน',
    icon: 'mdi-account-check-outline',
    color: '#c2410c',
  },
];

const roleType = ref<ESignSignerRole | null>(null);
const search = ref('');
const selectedDelegateId = ref('');
const delegateNote = ref('');

const presetPerson = computed<ESignPerson | null>(() => {
  if (roleType.value === 'president') return PRESIDENT_PERSON;
  if (roleType.value === 'council') return COUNCIL_CHAIR_PERSON;
  return null;
});

const filteredDelegates = computed(() => {
  const needle = search.value.trim().toLowerCase();
  if (!needle) return DELEGATE_CANDIDATES;
  return DELEGATE_CANDIDATES.filter((person) =>
    [person.name, person.position, person.department, person.employeeId]
      .join(' ')
      .toLowerCase()
      .includes(needle),
  );
});

const selectedDelegate = computed(() =>
  DELEGATE_CANDIDATES.find((person) => person.id === selectedDelegateId.value) ?? null,
);

const canConfirm = computed(() => {
  if (roleType.value === 'president' || roleType.value === 'council') {
    return Boolean(presetPerson.value);
  }
  if (roleType.value === 'delegate') {
    return Boolean(selectedDelegate.value && delegateNote.value.trim());
  }
  return false;
});

function initials(name: string): string {
  const cleaned = name.replace(/^(ศ\.ดร\.|รศ\.ดร\.|ผศ\.ดร\.|ดร\.|นาย|นาง|นางสาว)\s*/u, '').trim();
  const parts = cleaned.split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2);
  return `${parts[0][0] ?? ''}${parts[1][0] ?? ''}`;
}

function selectType(type: ESignSignerRole): void {
  roleType.value = type;
  if (type !== 'delegate') {
    selectedDelegateId.value = '';
    delegateNote.value = '';
    search.value = '';
  }
}

function reset(): void {
  roleType.value = null;
  search.value = '';
  selectedDelegateId.value = '';
  delegateNote.value = '';
}

function emitClose(value: boolean): void {
  emit('update:modelValue', value);
  if (!value) reset();
}

function confirm(): void {
  if (!canConfirm.value || !roleType.value) return;

  if (roleType.value === 'president' || roleType.value === 'council') {
    const person = presetPerson.value!;
    emit('confirm', {
      id: createClientId('signer'),
      roleType: roleType.value,
      name: person.name,
      position: person.position,
      department: person.department,
      employeeId: person.employeeId,
    });
  } else if (selectedDelegate.value) {
    emit('confirm', {
      id: createClientId('signer'),
      roleType: 'delegate',
      name: selectedDelegate.value.name,
      position: selectedDelegate.value.position,
      department: selectedDelegate.value.department,
      employeeId: selectedDelegate.value.employeeId,
      note: delegateNote.value.trim(),
    });
  }

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
  grid-template-columns: repeat(3, minmax(0, 1fr));
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

.signer-person-card,
.signer-search-item {
  display: flex;
  align-items: center;
  gap: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 12px;
  background: #f8fafc;
}

.signer-search-list {
  max-height: 220px;
  overflow: auto;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.signer-search-item {
  width: 100%;
  cursor: pointer;
  font: inherit;
  background: #fff;
}

.signer-search-item.is-selected {
  border-color: #1e3a8a;
  background: #eff6ff;
}

@media (max-width: 600px) {
  .signer-type-grid {
    grid-template-columns: 1fr;
  }
}
</style>
