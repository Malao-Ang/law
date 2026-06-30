<template>
  <div class="reldlg-backdrop" @click.self="$emit('close')">
    <div class="reldlg">
      <header class="reldlg__head">
        <h3>{{ defaultType === 'repeals' ? 'ยกเลิกมาตรา / กฎหมาย' : 'เพิ่มความสัมพันธ์' }}</h3>
        <button class="reldlg__x" @click="$emit('close')"><span class="mdi mdi-close" /></button>
      </header>

      <div class="reldlg__body">
        <div class="reldlg__row">
          <label>ประเภท</label>
          <div class="reldlg__seg">
            <button :class="{ on: form.type === 'related' }" @click="form.type = 'related'">เกี่ยวข้อง</button>
            <button :class="{ on: form.type === 'repeals' }" @click="form.type = 'repeals'">ยกเลิก</button>
          </div>
        </div>

        <div class="reldlg__row">
          <label>เป้าหมาย</label>
          <div class="reldlg__seg">
            <button :class="{ on: mode === 'doc' }" @click="mode = 'doc'">เลือกจากเอกสาร</button>
            <button :class="{ on: mode === 'text' }" @click="mode = 'text'">พิมพ์เอง</button>
          </div>
        </div>

        <template v-if="mode === 'doc'">
          <div class="reldlg__row">
            <label>เอกสาร</label>
            <select v-model="selectedDocId" @change="onDocPicked">
              <option value="">— เลือกเอกสาร —</option>
              <option v-for="d in docs" :key="d.document_id" :value="d.document_id">{{ d.title }}</option>
            </select>
          </div>
          <div v-if="targetSections.length" class="reldlg__row">
            <label>มาตรา (ไม่บังคับ)</label>
            <select v-model="form.target_section">
              <option value="">— ทั้งฉบับ —</option>
              <option v-for="s in targetSections" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
        </template>

        <template v-else>
          <div class="reldlg__row">
            <label>ชื่อกฎหมาย</label>
            <input v-model="form.target_title" type="text" placeholder="พ.ร.บ. ..." />
          </div>
          <div class="reldlg__row">
            <label>มาตรา (ไม่บังคับ)</label>
            <input v-model="form.target_section" type="text" placeholder="มาตรา ๕" />
          </div>
          <div class="reldlg__row">
            <label>ลิงก์ (ไม่บังคับ)</label>
            <input v-model="form.url" type="text" placeholder="https://..." />
          </div>
        </template>

        <div class="reldlg__row">
          <label>หมายเหตุ</label>
          <input v-model="form.note" type="text" placeholder="ไม่บังคับ" />
        </div>
      </div>

      <footer class="reldlg__foot">
        <button class="reldlg__btn" @click="$emit('close')">ยกเลิก</button>
        <button class="reldlg__btn reldlg__btn--primary" :disabled="!canSave" @click="save">เพิ่ม</button>
      </footer>
    </div>
  </div>
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

<style scoped>
.reldlg-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,0.5); display: flex; align-items: center; justify-content: center; z-index: 300; }
.reldlg { background: #fff; border-radius: 12px; width: 460px; max-width: calc(100vw - 32px); max-height: calc(100vh - 64px); display: flex; flex-direction: column; font-family: 'Sarabun', sans-serif; }
.reldlg__head { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid #e2e8f0; }
.reldlg__head h3 { margin: 0; font-size: 16px; color: #1e2a4a; }
.reldlg__x { background: none; border: none; cursor: pointer; font-size: 18px; color: #64748b; }
.reldlg__body { padding: 16px 18px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
.reldlg__row { display: flex; flex-direction: column; gap: 6px; }
.reldlg__row > label { font-size: 12px; font-weight: 600; color: #475569; }
.reldlg__row input, .reldlg__row select { border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 10px; font: inherit; }
.reldlg__seg { display: flex; gap: 6px; }
.reldlg__seg button { flex: 1; padding: 8px; border: 1px solid #cbd5e1; background: #fff; border-radius: 8px; cursor: pointer; font: inherit; font-size: 13px; }
.reldlg__seg button.on { background: #1a3673; color: #fff; border-color: #1a3673; }
.reldlg__foot { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 18px; border-top: 1px solid #e2e8f0; }
.reldlg__btn { padding: 8px 16px; border: 1px solid #cbd5e1; background: #fff; border-radius: 8px; cursor: pointer; font: inherit; font-size: 13px; }
.reldlg__btn--primary { background: #1a3673; color: #fff; border-color: #1a3673; }
.reldlg__btn--primary:disabled { opacity: 0.5; cursor: default; }
</style>
