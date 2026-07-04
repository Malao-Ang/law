<template>
  <v-dialog
    :model-value="true"
    max-width="480"
    @update:model-value="(val) => { if (!val) $emit('close') }"
  >
    <v-card>
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
              :color="mode === 'doc' ? 'primary' : ''"
              :variant="mode === 'doc' ? 'flat' : 'outlined'"
              @click="mode = 'doc'"
            >เลือกจากเอกสาร</v-btn>
            <v-btn
              size="small"
              :color="mode === 'text' ? 'primary' : ''"
              :variant="mode === 'text' ? 'flat' : 'outlined'"
              @click="mode = 'text'"
            >พิมพ์เอง</v-btn>
          </div>
        </div>

        <template v-if="mode === 'doc'">
          <v-select
            v-model="selectedDocId"
            :items="docItems"
            label="เอกสาร"
            item-title="title"
            item-value="value"
            class="mb-3"
            @update:model-value="onDocPicked"
          />
          <v-select
            v-if="targetSections.length"
            v-model="form.target_section"
            :items="sectionItems"
            label="มาตรา (ไม่บังคับ)"
            item-title="title"
            item-value="value"
            class="mb-3"
          />
        </template>

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
import { computed, onMounted, ref, watch } from 'vue';
import { listDocuments, fetchReview } from '../../api/client';
import { buildSections } from '../../composables/useLawSections';
import type { DocumentListItem, LawRelation, RelationScope, RelationType } from '../../types/document';

const props = defineProps<{
  scope: RelationScope;
  blockId?: string | null;
  defaultType?: RelationType;
}>();

const emit = defineEmits<{ close: []; save: [relation: LawRelation] }>();

const mode = ref<'doc' | 'text'>('doc');
const docs = ref<DocumentListItem[]>([]);
const selectedDocId = ref('');
const targetSections = ref<string[]>([]);

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

const canSave = computed(() => form.value.target_title.trim() !== '');

const docItems = computed(() => [
  { title: '— เลือกเอกสาร —', value: '' },
  ...docs.value.map((d) => ({ title: d.title, value: d.document_id })),
]);

const sectionItems = computed(() => [
  { title: '— ทั้งฉบับ —', value: '' },
  ...targetSections.value.map((s) => ({ title: s, value: s })),
]);

onMounted(async () => {
  try {
    docs.value = (await listDocuments()).documents.filter((d) => d.status === 'done');
  } catch {
    docs.value = [];
  }
});

watch(mode, () => {
  form.value.target_title = '';
  form.value.target_document_id = null;
  form.value.target_section = '';
  selectedDocId.value = '';
  targetSections.value = [];
});

async function onDocPicked(): Promise<void> {
  targetSections.value = [];
  form.value.target_section = '';
  const doc = docs.value.find((d) => d.document_id === selectedDocId.value);
  form.value.target_document_id = selectedDocId.value || null;
  form.value.target_title = doc?.title ?? '';
  if (!selectedDocId.value) return;
  try {
    const review = await fetchReview(selectedDocId.value);
    targetSections.value = buildSections(review).map((s) => s.badge);
  } catch {
    targetSections.value = [];
  }
}

function save(): void {
  if (!canSave.value) return;
  emit('save', { ...form.value, target_title: form.value.target_title.trim() });
}
</script>
