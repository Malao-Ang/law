<template>
  <v-dialog :model-value="modelValue" max-width="560" persistent @update:model-value="emit('update:modelValue', $event)">
    <v-card rounded="xl">
      <div class="d-flex align-center justify-space-between px-5 pt-4 pb-2">
        <div class="d-flex align-center ga-3">
          <v-avatar color="success" size="40">
            <v-icon icon="mdi-check" color="white" />
          </v-avatar>
          <div>
            <div class="text-subtitle-1 font-weight-bold">เผยแพร่กฎหมาย</div>
            <div class="text-caption text-medium-emphasis">
              เอกสารได้รับการลงนามครบถ้วนและพร้อมสำหรับการเผยแพร่
            </div>
          </div>
        </div>
        <v-btn icon="mdi-close" variant="text" size="small" @click="emit('update:modelValue', false)" />
      </div>

      <v-card-text class="px-5">
        <div class="publish-doc mb-4">
          <div class="text-body-2 font-weight-bold mb-1">{{ documentTitle }}</div>
          <div class="text-caption text-medium-emphasis mb-2">
            {{ trackingId }} • เวอร์ชัน {{ version }}
          </div>
          <div class="d-flex flex-wrap ga-2">
            <v-chip size="small" color="admin-primary" variant="tonal" class="font-weight-bold">
              สถานะปัจจุบัน: ลงนามเสร็จ
            </v-chip>
            <v-chip size="small" color="success" variant="tonal" class="font-weight-bold">
              หลังเผยแพร่: มีผลบังคับใช้
            </v-chip>
          </div>
        </div>

        <div class="publish-meta-grid mb-4">
          <div>
            <div class="text-caption text-medium-emphasis">สถานะการลงนาม</div>
            <div class="text-body-2 font-weight-bold text-success">ลงนามเสร็จสิ้น</div>
            <div class="text-caption text-medium-emphasis">{{ signedAtLabel }}</div>
          </div>
          <div>
            <div class="text-caption text-medium-emphasis">ข้อมูลการเผยแพร่</div>
            <div class="text-body-2 font-weight-bold">พร้อมเผยแพร่</div>
            <div class="text-caption text-medium-emphasis">{{ publishAtLabel }}</div>
          </div>
        </div>

        <div class="d-flex flex-column ga-2 mb-4">
          <div v-for="item in checks" :key="item" class="d-flex align-center ga-2 text-body-2">
            <v-icon icon="mdi-check-circle" color="success" size="18" />
            {{ item }}
          </div>
        </div>

        <v-alert type="success" variant="tonal" density="comfortable">
          เมื่อเผยแพร่แล้ว เอกสารจะมีสถานะ “มีผลบังคับใช้” ถูกบันทึกใน Audit Log และสามารถอ้างอิงได้จากฐานข้อมูลกฎหมาย
        </v-alert>
      </v-card-text>

      <v-card-actions class="px-5 pb-5">
        <v-spacer />
        <v-btn variant="outlined" class="text-none" @click="emit('update:modelValue', false)">ยกเลิก</v-btn>
        <v-btn
          color="success"
          class="text-none"
          :loading="loading"
          @click="emit('confirm')"
        >เผยแพร่กฎหมาย</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
  modelValue: boolean;
  documentTitle: string;
  trackingId: string;
  version?: string;
  signedAt?: string | null;
  loading?: boolean;
}>();

defineEmits<{
  'update:modelValue': [value: boolean];
  confirm: [];
}>();

const version = computed(() => props.version || 'v1.0');

const signedAtLabel = computed(() => formatWhen(props.signedAt));
const publishAtLabel = computed(() => formatWhen(new Date().toISOString()));

const checks = [
  'ลงนามเสร็จสิ้น',
  'ข้อมูล Metadata ครบถ้วน',
  'เอกสารพร้อมเผยแพร่',
  'Digital Signature verified',
  'ความสัมพันธ์กฎหมายครบ',
];

function formatWhen(iso?: string | null): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('th-TH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}
</script>

<style scoped>
.publish-doc {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 14px;
}

.publish-meta-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
</style>
