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
import { computed, watch } from 'vue';
import { useDocumentStore } from '../../stores/documentStore';

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

const open = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
});

const meta = computed(() => documentStore.review?.law_meta);
const status = computed(() => documentStore.status);
const isOldDoc = computed(() => meta.value?.document_type === 'old');

const checklist = computed<ChecklistItem[]>(() => {
  const currentMeta = meta.value;
  const currentStatus = status.value;
  const workflowCompletedStep = currentStatus?.workflow_completed_step ?? null;
  const ragStatus = currentStatus?.status ?? '';

  const items: ChecklistItem[] = [
    {
      key: 'metadata',
      label: 'METADATA ครบ (title + law_type + date)',
      ok: !!(currentMeta?.title && currentMeta.law_type && (currentMeta.promulgation_date || currentMeta.effective_date)),
      status: currentMeta?.title && currentMeta.law_type && (currentMeta.promulgation_date || currentMeta.effective_date)
        ? 'ครบถ้วน'
        : 'ยังขาดข้อมูลจำเป็น',
      level: 'required',
    },
  ];

  if (!isOldDoc.value) {
    const blockCount = documentStore.review?.summary.block_count ?? 0;
    items.push({
      key: 'structure',
      label: 'โครงสร้างเนื้อหา (block_count > 0)',
      ok: blockCount > 0,
      status: blockCount > 0 ? `${blockCount} บล็อก` : 'ยังไม่มีโครงสร้างเนื้อหา',
      level: 'required',
    });

    const ragOk = ragStatus === 'exported' || ragStatus === 'ingested' || (workflowCompletedStep ?? 0) >= 2;
    items.push({
      key: 'rag',
      label: 'จัดลำดับ RAG',
      ok: ragOk,
      status: ragOk ? 'พร้อมใช้งาน' : 'ข้ามขั้นตอน',
      level: 'optional',
    });
  }

  const relationCount = documentStore.review?.relations?.length ?? 0;
  items.push(
    {
      key: 'relations',
      label: 'ความสัมพันธ์กฎหมาย',
      ok: relationCount > 0,
      status: relationCount > 0 ? `${relationCount} รายการ` : 'ยังไม่มี',
      level: 'optional',
    },
    {
      key: 'access_scope',
      label: 'กำหนดสิทธิ์',
      ok: !!currentMeta?.access_scope,
      status: currentMeta?.access_scope ? currentMeta.access_scope : 'ยังไม่ได้กำหนด',
      level: 'optional',
    },
  );

  if (!isOldDoc.value) {
    items.push({
      key: 'esign',
      label: 'ผ่าน e-Sign',
      ok: !!currentStatus?.esign_confirmed_at,
      status: currentStatus?.esign_confirmed_at ? 'ยืนยันแล้ว' : 'ยังไม่ผ่าน e-Sign',
      level: 'optional',
    });
  }

  return items;
});

const hasRequiredFail = computed(() =>
  checklist.value.some((item) => item.level === 'required' && !item.ok),
);

watch(
  () => props.modelValue,
  (value) => {
    if (value && props.publishing) {
      void documentStore.getStatus();
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
