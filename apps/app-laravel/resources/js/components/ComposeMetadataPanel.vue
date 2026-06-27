<template>
  <section class="compose-metadata-panel">
    <div class="compose-drawer-header">
      <div>
        <h2>ข้อมูลหนังสือ</h2>
        <p>จัดเก็บข้อมูลส่วนหัวและผู้ลงนาม</p>
      </div>
      <v-chip size="small" variant="tonal" color="primary">Auto save</v-chip>
    </div>

    <div class="compose-metadata-form">
      <v-text-field v-model="form.department" label="ส่วนราชการ" density="compact" variant="outlined" hide-details />
      <v-text-field v-model="form.doc_number" label="เลขที่หนังสือ" density="compact" variant="outlined" hide-details />
      <v-text-field v-model="form.date" label="วันที่" density="compact" variant="outlined" hide-details />
      <v-text-field v-model="form.subject" label="เรื่อง" density="compact" variant="outlined" hide-details />
      <v-text-field v-model="form.recipient" label="เรียน" density="compact" variant="outlined" hide-details />
      <v-text-field v-model="form.reference" label="อ้างถึง" density="compact" variant="outlined" hide-details />
      <v-text-field v-model="form.attachments" label="สิ่งที่ส่งมาด้วย" density="compact" variant="outlined" hide-details />
      <v-select
        v-model="form.urgency"
        label="ชั้นความเร็ว"
        density="compact"
        hide-details
        :items="urgencyOptions"
        variant="outlined"
      />
      <v-select
        v-model="form.confidentiality"
        label="ชั้นความลับ"
        density="compact"
        hide-details
        :items="confidentialityOptions"
        variant="outlined"
      />
      <v-text-field v-model="form.signatory_name" label="ชื่อผู้ลงนาม" density="compact" variant="outlined" hide-details />
      <v-text-field v-model="form.signatory_position" label="ตำแหน่งผู้ลงนาม" density="compact" variant="outlined" hide-details />
    </div>

    <v-divider />

    <v-list class="compose-metadata-summary" density="compact">
      <v-list-subheader>สถานะ</v-list-subheader>
      <v-list-item title="สถานะบันทึก" :subtitle="saveMessage" />
      <v-list-item title="ไฟล์ต้นฉบับ" :subtitle="review?.source_file ?? '-'" />
      <v-list-item title="ประเภทเอกสาร" :subtitle="review?.source_type ?? '-'" />
      <v-list-item title="จำนวนบล็อก" :subtitle="String(review?.summary.block_count ?? 0)" />
      <v-list-item title="ต้องทบทวน" :subtitle="String(review?.summary.review_required_count ?? 0)" />
    </v-list>
  </section>
</template>

<script setup lang="ts">
import { reactive, watch } from 'vue';
import type { DocumentMetadata, ReviewDocument } from '../types/document';

const props = defineProps<{
  modelValue: DocumentMetadata;
  review: ReviewDocument | null;
  documentId: string;
  saveMessage: string;
}>();

const emit = defineEmits<{
  'update:modelValue': [DocumentMetadata];
}>();

const urgencyOptions = ['', 'ปกติ', 'ด่วน', 'ด่วนมาก', 'ด่วนที่สุด'];
const confidentialityOptions = ['', 'ปกติ', 'ลับ', 'ลับมาก', 'ลับที่สุด'];
const form = reactive<DocumentMetadata>({ ...props.modelValue });

watch(
  () => props.modelValue,
  (next) => {
    Object.assign(form, next);
  },
  { deep: true },
);

watch(
  form,
  (next) => {
    emit('update:modelValue', { ...next });
  },
  { deep: true },
);
</script>
