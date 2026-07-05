<template>
  <div class="d-flex flex-column" style="height:100%; overflow:hidden">
    <v-tabs v-model="activeTab" density="compact" grow>
      <v-tab v-for="tab in tabs" :key="tab.key" :value="tab.key">
        <v-icon :icon="tab.icon" size="16" class="mr-1" />
        {{ tab.label }}
      </v-tab>
    </v-tabs>

    <div class="flex-grow-1 overflow-y-auto pa-4 d-flex flex-column ga-4">
      <template v-if="activeTab === 'info'">
        <div class="d-flex align-center ga-2">
          <v-icon
            :icon="saveIndicatorState === 'saved' ? 'mdi-check-circle' : saveIndicatorState === 'saving' ? 'mdi-loading' : saveIndicatorState === 'error' ? 'mdi-alert-circle' : 'mdi-circle-small'"
            :color="saveIndicatorState === 'saved' ? 'success' : saveIndicatorState === 'saving' ? 'warning' : saveIndicatorState === 'error' ? 'error' : undefined"
            size="14"
          />
          <span class="text-caption text-medium-emphasis">{{ saveMessage }}</span>
        </div>

        <div>
          <div class="text-overline mb-2">หนังสือราชการ</div>
          <div class="d-flex flex-column ga-2">
            <v-text-field label="ส่วนราชการ" :model-value="modelValue.department" @update:model-value="patch('department', $event)" />
            <v-text-field label="เลขที่หนังสือ" :model-value="modelValue.doc_number" @update:model-value="patch('doc_number', $event)" />
            <v-text-field label="วันที่" :model-value="modelValue.date" @update:model-value="patch('date', $event)" />
            <v-textarea label="เรื่อง" rows="2" :model-value="modelValue.subject" @update:model-value="patch('subject', $event)" />
            <v-text-field label="เรียน" :model-value="modelValue.recipient" @update:model-value="patch('recipient', $event)" />
          </div>
        </div>

        <div>
          <div class="text-overline mb-2">อ้างอิง</div>
          <div class="d-flex flex-column ga-2">
            <v-text-field label="อ้างถึง" :model-value="modelValue.reference" @update:model-value="patch('reference', $event)" />
            <v-text-field label="สิ่งที่ส่งมาด้วย" :model-value="modelValue.attachments" @update:model-value="patch('attachments', $event)" />
          </div>
        </div>

        <div>
          <div class="text-overline mb-2">ชั้นความ</div>
          <div class="d-flex flex-column ga-2">
            <v-select
              label="ชั้นความเร็ว"
              :model-value="modelValue.urgency"
              :items="[{ title: 'ปกติ', value: '' }, { title: 'ด่วน', value: 'ด่วน' }, { title: 'ด่วนที่สุด', value: 'ด่วนที่สุด' }, { title: 'ด่วนมาก', value: 'ด่วนมาก' }]"
              @update:model-value="patch('urgency', $event)"
            />
            <v-select
              label="ชั้นความลับ"
              :model-value="modelValue.confidentiality"
              :items="[{ title: 'ไม่ลับ', value: '' }, { title: 'ลับ', value: 'ลับ' }, { title: 'ลับมาก', value: 'ลับมาก' }, { title: 'ลับที่สุด', value: 'ลับที่สุด' }]"
              @update:model-value="patch('confidentiality', $event)"
            />
          </div>
        </div>

        <div>
          <div class="text-overline mb-2">ผู้ลงนาม</div>
          <div class="d-flex flex-column ga-2">
            <v-text-field label="ชื่อ-สกุล" :model-value="modelValue.signatory_name" @update:model-value="patch('signatory_name', $event)" />
            <v-text-field label="ตำแหน่ง" :model-value="modelValue.signatory_position" @update:model-value="patch('signatory_position', $event)" />
          </div>
        </div>
      </template>

      <template v-else-if="activeTab === 'timeline'">
        <template v-if="review">
          <div class="d-flex align-start ga-3">
            <v-icon icon="mdi-check-circle-outline" color="success" />
            <div>
              <div class="text-caption text-medium-emphasis">อัปเดตล่าสุด</div>
              <div class="text-body-2">{{ review.document_review.updated_at ?? '—' }}</div>
            </div>
          </div>
          <div class="d-flex align-start ga-3">
            <v-icon icon="mdi-account-check-outline" color="primary" />
            <div>
              <div class="text-caption text-medium-emphasis">อนุมัติโดย</div>
              <div class="text-body-2">{{ review.document_review.approved_by ?? 'ยังไม่อนุมัติ' }}</div>
            </div>
          </div>
        </template>
        <div v-else class="text-caption text-medium-emphasis">ยังไม่มีข้อมูล Timeline</div>
      </template>

      <template v-else>
        <div class="d-flex flex-column ga-3">
          <div class="text-caption text-medium-emphasis">ดำเนินการกับเอกสารทั้งฉบับ</div>
          <v-btn color="primary" prepend-icon="mdi-download-outline" :disabled="exportDisabled || exportBusy" @click="$emit('export')">
            ส่งออก RAG JSON
          </v-btn>
          <div v-if="exportDisabled" class="text-caption text-error">
            รอ AI correction ให้เสร็จก่อนจึงจะส่งออกได้
          </div>
          <v-btn variant="outlined" prepend-icon="mdi-refresh" @click="$emit('reload')">
            โหลดเอกสารใหม่
          </v-btn>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import type { DocumentMetadata, ReviewDocument } from '../../types/document';

const props = defineProps<{
  modelValue: DocumentMetadata;
  review: ReviewDocument | null;
  documentId: string;
  saveMessage: string;
  correctionStatus?: 'not_required' | 'pending' | 'in_progress' | 'done' | 'failed' | null;
  exportBusy?: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [DocumentMetadata];
  reload: [];
  export: [];
}>();

const activeTab = ref<'info' | 'timeline' | 'actions'>('info');
const tabs = [
  { key: 'info', label: 'ข้อมูล', icon: 'mdi-file-document-outline' },
  { key: 'timeline', label: 'Timeline', icon: 'mdi-timeline-outline' },
  { key: 'actions', label: 'ดำเนินการ', icon: 'mdi-cog-outline' },
] as const;

const saveIndicatorState = computed<'idle' | 'saving' | 'saved' | 'error'>(() => {
  if (props.saveMessage.includes('กำลัง')) return 'saving';
  if (props.saveMessage.includes('ไม่สำเร็จ') || props.saveMessage.includes('error')) return 'error';
  if (props.saveMessage.includes('บันทึกอัตโนมัติแล้ว')) return 'saved';
  return 'idle';
});

const exportDisabled = computed(() =>
  ['pending', 'in_progress', 'failed'].includes(props.correctionStatus ?? ''),
);

function patch(key: keyof DocumentMetadata, value: string): void {
  emit('update:modelValue', { ...props.modelValue, [key]: value });
}
</script>

