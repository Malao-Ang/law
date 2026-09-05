<template>
  <v-dialog v-model="open" max-width="560">
    <v-card>
      <v-card-title class="publish-confirm__title">
        <v-icon
          :icon="publishing ? 'mdi-alert-circle-outline' : 'mdi-eye-off-outline'"
          :color="publishing ? 'warning' : 'admin-primary'"
          size="24"
        />
        {{ publishing ? 'ยืนยันการเผยแพร่เอกสาร' : 'ยืนยันยกเลิกการเผยแพร่' }}
      </v-card-title>

      <v-card-text>
        <template v-if="publishing">
          <p class="text-body-2 text-medium-emphasis mb-3">เงื่อนไขการเผยแพร่:</p>

          <v-list density="compact" class="publish-confirm__list">
            <v-list-item
              v-for="item in checklist"
              :key="item.key"
              :title="item.label"
              :subtitle="item.status"
            >
              <template #prepend>
                <v-icon
                  :icon="item.ok ? 'mdi-check-circle' : item.level === 'required' ? 'mdi-close-circle' : 'mdi-alert-circle'"
                  :color="item.ok ? 'success' : item.level === 'required' ? 'error' : 'warning'"
                />
              </template>
            </v-list-item>
          </v-list>

          <v-alert
            v-if="hasRequiredFail"
            type="error"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            กรุณากรอกข้อมูลที่จำเป็นให้ครบก่อนเผยแพร่
          </v-alert>
          <v-alert
            v-else-if="checklist.some(i => i.level === 'optional' && !i.ok)"
            type="warning"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            ท่านข้ามขั้นตอนบางรายการ ต้องการเผยแพร่เอกสารนี้หรือไม่?
          </v-alert>
        </template>

        <p v-else class="text-body-1 mb-0">
          เอกสารนี้จะไม่แสดงในหน้าสาธารณะอีกต่อไป
        </p>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" :disabled="loading" @click="open = false">ยกเลิก</v-btn>
        <v-btn
          color="admin-primary"
          variant="flat"
          :loading="loading"
          :disabled="publishing && hasRequiredFail"
          @click="emit('confirm')"
        >
          {{ publishing ? 'ยืนยันเผยแพร่' : 'ยืนยัน' }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useDocumentStore } from '../../stores/documentStore';
import { fetchStatus } from '../../api/client';
import type { DocumentStatus } from '../../types/document';

type ChecklistLevel = 'required' | 'optional';

interface ChecklistItem {
  key: string;
  label: string;
  ok: boolean;
  status: string;
  level: ChecklistLevel;
}

const props = defineProps<{
  modelValue: boolean;
  publishing: boolean;
  loading: boolean;
}>();

const emit = defineEmits<{
  confirm: [];
  'update:modelValue': [value: boolean];
}>();

const documentStore = useDocumentStore();
const docStatus = ref<DocumentStatus | null>(null);

const open = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
});

const meta = computed(() => documentStore.review?.law_meta);
const isOldDoc = computed(() => meta.value?.document_type === 'old');

const checklist = computed<ChecklistItem[]>(() => {
  const currentMeta = meta.value;
  const currentStatus = docStatus.value;
  const workflowCompletedStep = currentStatus?.workflow_completed_step ?? null;
  const ragStatus = currentStatus?.status ?? '';

  const items: ChecklistItem[] = [];

  // === เอกสารภายใน: 5 gates ตามลำดับ ===
  if (!isOldDoc.value) {
    // 1. e-Sign ต้องลงนามสำเร็จ (required)
    items.push({
      key: 'esign',
      label: 'ผ่านการลงนาม e-Sign',
      ok: !!currentStatus?.esign_confirmed_at,
      status: currentStatus?.esign_confirmed_at ? 'ยืนยันแล้ว' : 'ยังไม่ผ่าน e-Sign',
      level: 'required',
    });

    // 2. RAG ต้องจัดลำดับ (required)
    const ragOk = (ragStatus === 'exported' || ragStatus === 'ingested' || (workflowCompletedStep ?? 0) >= 3) && !currentStatus?.rag_skipped;
    items.push({
      key: 'rag',
      label: 'จัดลำดับ RAG',
      ok: ragOk,
      status: ragOk ? 'พร้อมใช้งาน' : (currentStatus?.rag_skipped ? 'ข้ามขั้นตอน — ต้องกลับไปทำ' : 'ยังไม่ได้จัดลำดับ'),
      level: 'required',
    });
  }

  // 3. กำหนดสิทธิ์ (required)
  items.push({
    key: 'access_scope',
    label: 'กำหนดสิทธิ์การเข้าถึง',
    ok: !!currentMeta?.access_scope,
    status: currentMeta?.access_scope ? currentMeta.access_scope : 'ยังไม่ได้กำหนด',
    level: 'required',
  });

  // 4. ความสัมพันธ์กฎหมาย (optional)
  const relationCount = documentStore.review?.relations?.length ?? 0;
  items.push({
    key: 'relations',
    label: 'ความสัมพันธ์กฎหมาย',
    ok: relationCount > 0,
    status: relationCount > 0 ? `${relationCount} รายการ` : 'ยังไม่มี',
    level: 'optional',
  });

  // 5. สถานะบังคับใช้ (required — ต้องไม่เป็น "ร่าง")
  const statusOk = !!currentMeta?.status && currentMeta.status !== 'ร่าง';
  items.push({
    key: 'status',
    label: 'สถานะการบังคับใช้',
    ok: statusOk,
    status: statusOk ? currentMeta!.status : 'ยังเป็นร่าง',
    level: 'required',
  });

  // Metadata ครบ (required ทั้ง old + new)
  items.push({
    key: 'metadata',
    label: 'ข้อมูลกฎหมายครบ (ชื่อ + ประเภท + วันที่)',
    ok: !!(currentMeta?.title && currentMeta.law_type && (currentMeta.promulgation_date || currentMeta.effective_date)),
    status: currentMeta?.title && currentMeta.law_type && (currentMeta.promulgation_date || currentMeta.effective_date)
      ? 'ครบถ้วน'
      : 'ยังขาดข้อมูลจำเป็น',
    level: 'required',
  });

  return items;
});

const hasRequiredFail = computed(() =>
  checklist.value.some((item) => item.level === 'required' && !item.ok),
);

watch(
  () => props.modelValue,
  async (value) => {
    if (value && props.publishing) {
      try {
        docStatus.value = await fetchStatus(documentStore.documentId);
      } catch {
        docStatus.value = null;
      }
    }
  },
);
</script>

<style scoped>
.publish-confirm__title {
  align-items: center;
  display: flex;
  gap: 10px;
  line-height: 1.35;
}

.publish-confirm__list {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
}
</style>
