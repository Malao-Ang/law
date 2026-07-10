<template>
  <v-dialog
    :model-value="true"
    max-width="960"
    scrollable
    @update:model-value="(val) => { if (!val) $emit('close') }"
  >
    <v-card class="add-relation-dialog">
      <v-card-title class="d-flex align-center justify-space-between pr-2">
        {{ defaultType === 'repeals' ? 'ยกเลิกมาตรา / กฎหมาย' : 'เพิ่มความสัมพันธ์' }}
        <v-btn icon variant="text" @click="$emit('close')">
          <v-icon icon="mdi-close" />
        </v-btn>
      </v-card-title>

      <v-card-text>
        <div class="mb-4">
          <div class="text-caption font-weight-bold text-medium-emphasis mb-1">ประเภท</div>
          <div class="d-flex gap-2">
            <v-btn
              size="small"
              :color="form.type === 'related' ? 'primary' : ''"
              :variant="form.type === 'related' ? 'flat' : 'outlined'"
              @click="form.type = 'related'"
            >เกี่ยวข้อง</v-btn>
            <v-btn
              size="small"
              :color="form.type === 'repeals' ? 'primary' : ''"
              :variant="form.type === 'repeals' ? 'flat' : 'outlined'"
              @click="form.type = 'repeals'"
            >ยกเลิก</v-btn>
          </div>
        </div>

        <div class="mb-4">
          <div class="text-caption font-weight-bold text-medium-emphasis mb-1">เป้าหมาย</div>
          <div class="d-flex gap-2">
            <v-btn
              size="small"
              :color="mode === 'picker' ? 'primary' : ''"
              :variant="mode === 'picker' ? 'flat' : 'outlined'"
              @click="mode = 'picker'"
            >เลือกจากคลังกฎหมาย</v-btn>
            <v-btn
              size="small"
              :color="mode === 'text' ? 'primary' : ''"
              :variant="mode === 'text' ? 'flat' : 'outlined'"
              @click="mode = 'text'"
            >พิมพ์เอง</v-btn>
          </div>
        </div>

        <LawRelationColumnPicker
          v-if="mode === 'picker'"
          v-model="pickerTarget"
          :exclude-document-id="excludeDocumentId"
        />

        <template v-else>
          <v-text-field
            v-model="form.target_title"
            label="ชื่อกฎหมาย"
            placeholder="พ.ร.บ. ..."
            class="mb-3"
          />
          <v-text-field
            v-model="form.target_section"
            label="มาตรา (ไม่บังคับ)"
            placeholder="มาตรา ๕"
            class="mb-3"
          />
          <v-text-field
            v-model="form.url"
            label="ลิงก์ (ไม่บังคับ)"
            placeholder="https://..."
            class="mb-3"
          />
        </template>

        <v-text-field
          v-model="form.note"
          label="หมายเหตุ"
          placeholder="ไม่บังคับ"
          class="mt-4"
        />
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn @click="$emit('close')">ยกเลิก</v-btn>
        <v-btn color="primary" :disabled="!canSave" @click="save">เพิ่ม</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { LawRelation, LawRelationTarget, RelationScope, RelationType } from '../../types/document';
import LawRelationColumnPicker from './LawRelationColumnPicker.vue';

const props = defineProps<{
  scope: RelationScope;
  blockId?: string | null;
  defaultType?: RelationType;
  excludeDocumentId?: string | null;
}>();

const emit = defineEmits<{ close: []; save: [relation: LawRelation] }>();

const mode = ref<'picker' | 'text'>('picker');
const pickerTarget = ref<LawRelationTarget | null>(null);

const form = ref<LawRelation>({
  id: crypto.randomUUID(),
  scope: props.scope,
  block_id: props.blockId ?? null,
  type: props.defaultType ?? 'related',
  target_document_id: null,
  target_title: '',
  target_section: '',
  note: '',
  url: '',
});

const canSave = computed(() => {
  if (mode.value === 'text') {
    return form.value.target_title.trim() !== '';
  }
  return pickerTarget.value !== null && pickerTarget.value.title.trim() !== '';
});

watch(mode, () => {
  form.value.target_title = '';
  form.value.target_document_id = null;
  form.value.target_section = '';
  form.value.url = '';
  pickerTarget.value = null;
});

watch(pickerTarget, (target) => {
  if (!target) {
    form.value.target_document_id = null;
    form.value.target_title = '';
    form.value.target_section = '';
    return;
  }

  form.value.target_document_id = target.document_id;
  form.value.target_title = target.title;
  form.value.target_section = target.section ?? '';
});

function save(): void {
  if (!canSave.value) return;

  const targetSection = form.value.target_section?.trim() ?? '';
  emit('save', {
    ...form.value,
    target_title: form.value.target_title.trim(),
    target_section: targetSection !== '' ? targetSection : null,
    target_document_id: form.value.target_document_id || null,
    url: form.value.url?.trim() || null,
    note: form.value.note?.trim() || null,
  });
}
</script>

<style scoped>
.add-relation-dialog :deep(.v-card-text) {
  padding-top: 8px;
}
</style>
