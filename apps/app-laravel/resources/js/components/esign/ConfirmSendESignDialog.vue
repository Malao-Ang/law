<template>
  <v-dialog :model-value="modelValue" max-width="520" persistent @update:model-value="emit('update:modelValue', $event)">
    <v-card rounded="xl">
      <div class="d-flex align-center justify-space-between px-5 pt-4 pb-2">
        <div class="d-flex align-center ga-2">
          <v-avatar color="admin-primary" size="36" rounded="lg">
            <v-icon icon="mdi-send-outline" color="white" size="18" />
          </v-avatar>
          <div class="text-subtitle-1 font-weight-bold">ยืนยันการส่งเอกสารลงนาม</div>
        </div>
        <v-btn icon="mdi-close" variant="text" size="small" @click="emit('update:modelValue', false)" />
      </div>

      <v-card-text class="px-5 pt-2">
        <div class="confirm-row">
          <div class="text-caption text-medium-emphasis">ชื่อเอกสาร</div>
          <div class="text-body-2 font-weight-bold">{{ documentTitle }}</div>
        </div>

        <div class="confirm-row">
          <div class="text-caption text-medium-emphasis">ผู้ลงนาม</div>
          <div v-if="signer" class="d-flex align-center ga-2 mt-1">
            <v-avatar color="admin-primary" size="32">
              <span class="text-caption font-weight-bold">{{ initials(signer.name) }}</span>
            </v-avatar>
            <div>
              <div class="text-body-2 font-weight-bold">{{ signer.name }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ signer.position || roleLabel }}
                <span v-if="signer.employeeId"> • {{ signer.employeeId }}</span>
              </div>
            </div>
          </div>
          <div v-else class="text-body-2 text-error">ยังไม่ได้เลือกผู้ลงนาม</div>
        </div>

        <div class="confirm-row">
          <div class="text-caption text-medium-emphasis mb-1">ไฟล์แนบ</div>
          <div class="confirm-file">
            <v-icon icon="mdi-file-pdf-box" color="error" />
            <span class="text-body-2 font-weight-medium">{{ fileName }}</span>
          </div>
        </div>

        <v-alert type="warning" variant="tonal" density="comfortable" class="mt-2">
          เมื่อส่งแล้วจะไม่สามารถแก้ไขเอกสารได้ จนกว่าผู้ลงนามจะดำเนินการเสร็จหรือมีการยกเลิกการส่ง
        </v-alert>
      </v-card-text>

      <v-card-actions class="px-5 pb-5">
        <v-spacer />
        <v-btn variant="outlined" class="text-none" @click="emit('update:modelValue', false)">ยกเลิก</v-btn>
        <v-btn
          color="admin-primary"
          class="text-none"
          :disabled="!signer"
          :loading="loading"
          @click="emit('confirm')"
        >ยืนยันการส่งลงนาม</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { ESignSigner } from '../../types/esign';
import { ROLE_LABELS } from '../../data/esignSession';

const props = defineProps<{
  modelValue: boolean;
  documentTitle: string;
  fileName: string;
  signer: ESignSigner | null;
  loading?: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
  confirm: [];
}>();

const roleLabel = computed(() =>
  props.signer ? (ROLE_LABELS[props.signer.roleType] ?? props.signer.position) : '',
);

function initials(name: string): string {
  const cleaned = name.replace(/^(ศ\.ดร\.|รศ\.ดร\.|ผศ\.ดร\.|ดร\.|นาย|นาง|นางสาว)\s*/u, '').trim();
  const parts = cleaned.split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2);
  return `${parts[0][0] ?? ''}${parts[1][0] ?? ''}`;
}
</script>

<style scoped>
.confirm-row {
  margin-bottom: 14px;
  padding-bottom: 12px;
  border-bottom: 1px solid #f1f5f9;
}

.confirm-row:last-of-type {
  border-bottom: none;
}

.confirm-file {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  background: #f8fafc;
}
</style>
