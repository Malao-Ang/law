<template>
  <AppShell :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'จัดการ RAG บล็อก']" title="จัดการเนื้อหา RAG"
    subtitle="จัดการความสัมพันธ์และข้อมูลกฎหมายก่อนเผยแพร่">
    <template #actions>
      <v-btn variant="outlined" @click="router.push(`/documents/${props.documentId}/review`)">
        ยกเลิก
      </v-btn>
      <v-btn color="#1a3673" :loading="composeStore.exporting" @click="handleExport">
        บันทึกและเผยแพร่
      </v-btn>
    </template>

    <!-- Loading -->
    <div v-if="composeStore.loading" class="rag-state">
      <v-progress-circular indeterminate color="primary" />
      <p>กำลังโหลดบล็อก...</p>
    </div>

    <!-- Error -->
    <div v-else-if="composeStore.error" class="rag-state">
      <v-icon icon="mdi-alert-circle-outline" size="32" color="error" />
      <p>{{ composeStore.error }}</p>
      <v-btn variant="outlined" size="small" @click="composeStore.setError()">ปิด</v-btn>
    </div>

    <template v-else>
      <div class="rag-content-area">
        <div v-if="selectedBlockIds.size > 0" class="rag-actionbar">
          <span class="rag-actionbar__count">เลือก {{ selectedBlockIds.size }} บล็อก</span>
          <button class="rag-actionbar__btn" :disabled="selectedBlockIds.size < 2 || blockBusy" @click="mergeSelected">
            <span class="mdi mdi-table-merge-cells" /> รวม
          </button>
          <button class="rag-actionbar__btn rag-actionbar__btn--danger" :disabled="blockBusy" @click="deleteSelected">
            <span class="mdi mdi-delete-outline" /> ลบ
          </button>
          <button class="rag-actionbar__btn" :disabled="blockBusy" @click="clearSelection">
            ยกเลิกการเลือก
          </button>
        </div>
        <div v-if="documentStore.saveError" class="rag-save-error">
          <span class="mdi mdi-alert-circle-outline" /> {{ documentStore.saveError }}
          <button class="rag-save-error__x" @click="documentStore.setSaveError()">✕</button>
        </div>
        <details class="lawmeta-panel">
          <summary class="lawmeta-panel__summary">
            <span class="mdi mdi-information-outline" /> ข้อมูลกฎหมาย (หัวเอกสาร)
          </summary>
          <div class="lawmeta-grid">
            <label>สถานะ<input v-model="lawMetaForm.status" type="text" placeholder="มีผลใช้บังคับ"></label>
            <label>ประเภท<input v-model="lawMetaForm.law_type" type="text" placeholder="พระราชบัญญัติ"></label>
            <label>กลุ่มกฎหมาย<input v-model="lawMetaForm.law_group" type="text" placeholder="ด้านวิชาการ"></label>
            <label>หน่วยงาน<input v-model="lawMetaForm.agency" type="text" placeholder="มหาวิทยาลัยบูรพา"></label>
            <label>วันที่ประกาศ<input v-model="lawMetaForm.promulgation_date" type="text"
                placeholder="9 มกราคม 2551"></label>
            <label>วันที่มีผลบังคับ<input v-model="lawMetaForm.effective_date" type="text"></label>
            <label>ราชกิจจานุเบกษา<input v-model="lawMetaForm.gazette_reference" type="text"
                placeholder="เล่ม 125 ตอนที่ 5 ก"></label>
            <label>พระบรมราชโองการ<input v-model="lawMetaForm.royal_command" type="text"
                placeholder="ภูมิพลอดุลยเดช ปร."></label>
            <label class="lawmeta-grid__full">กฎหมายที่ถูกยกเลิก (บรรทัดละ 1 รายการ)
              <textarea v-model="repealedText" rows="3" />
            </label>
          </div>
          <div class="lawmeta-rels">
            <div class="lawmeta-rels__head">
              <span>ความสัมพันธ์ระดับเอกสาร</span>
              <button class="rag-sec__btn" @click="openRelationDialog('document', null, 'related')">
                <span class="mdi mdi-plus" /> เพิ่ม
              </button>
            </div>
            <span v-for="rel in documentRelations(relations)" :key="rel.id" class="rag-rel-chip"
              :class="{ 'rag-rel-chip--repeal': rel.type === 'repeals' }">
              <span class="mdi" :class="rel.type === 'repeals' ? 'mdi-cancel' : 'mdi-link-variant'" />
              {{ rel.target_title }}
              <button class="rag-rel-chip__x" @click="removeRelation(rel.id)"><span class="mdi mdi-close" /></button>
            </span>
          </div>
          <button class="lawmeta-save" :disabled="documentStore.saving" @click="saveLawMeta">
            <span class="mdi mdi-content-save-outline" /> บันทึกข้อมูลกฎหมาย
          </button>
        </details>

        <!-- Section list (e-Law style) -->
        <div class="rag-block-list">
          <div v-for="section in sections" :key="section.id" class="rag-sec">
            <div class="rag-sec__head">
              <span class="rag-sec__badge" :class="{ 'rag-sec__badge--chapter': section.isChapter }">{{ section.badge
              }}</span>
              <div class="rag-sec__actions">
                <button class="rag-sec__btn" @click="openRelationDialog('section', section.id, 'related')">
                  <span class="mdi mdi-link-variant" /> เพิ่มความสัมพันธ์
                </button>
                <button class="rag-sec__btn rag-sec__btn--danger"
                  @click="openRelationDialog('section', section.id, 'repeals')">
                  <span class="mdi mdi-cancel" /> ยกเลิกมาตรา
                </button>
              </div>
            </div>
            <div class="rag-sec__flow">
              <label class="rag-blockrow" :class="{ 'is-selected': selectedBlockIds.has(section.headBlock.block_id) }">
                <input type="checkbox" class="rag-blockrow__cb-input"
                  :checked="selectedBlockIds.has(section.headBlock.block_id)"
                  @change="toggleBlock(section.headBlock.block_id)">
                <span class="rag-blockrow__cb" aria-hidden="true">
                  <span class="mdi mdi-check"></span>
                </span>
                <BlockFlow :block="section.headBlock" :override-text="section.headBodyText" />
                <button class="rag-blockrow__split" :disabled="blockBusy" title="แบ่งบล็อก"
                  @click.prevent.stop="openSplit(section.headBlock)">
                  <span class="mdi mdi-call-split" />
                </button>
              </label>
              <label v-for="child in section.children" :key="child.block_id" class="rag-blockrow"
                :class="{ 'is-selected': selectedBlockIds.has(child.block_id) }">
                <input type="checkbox" class="rag-blockrow__cb-input" :checked="selectedBlockIds.has(child.block_id)"
                  @change="toggleBlock(child.block_id)">
                <span class="rag-blockrow__cb" aria-hidden="true">
                  <span class="mdi mdi-check"></span>
                </span>
                <BlockFlow :block="child" />
                <button class="rag-blockrow__split" :disabled="blockBusy" title="แบ่งบล็อก"
                  @click.prevent.stop="openSplit(child)">
                  <span class="mdi mdi-call-split" />
                </button>
              </label>
            </div>

            <button class="rag-sec__addblock" :disabled="blockBusy" @click="createBlockAfter(lastBlockId(section))">
              <span class="mdi mdi-plus" /> เพิ่มบล็อกใต้หัวข้อนี้
            </button>

            <div v-if="sectionRelations(section.id).length" class="rag-sec__rels">
              <span v-for="rel in sectionRelations(section.id)" :key="rel.id" class="rag-rel-chip"
                :class="{ 'rag-rel-chip--repeal': rel.type === 'repeals' }">
                <span class="mdi" :class="rel.type === 'repeals' ? 'mdi-cancel' : 'mdi-link-variant'" />
                {{ rel.target_title }}{{ rel.target_section ? ' · ' + rel.target_section : '' }}
                <button class="rag-rel-chip__x" @click="removeRelation(rel.id)"><span class="mdi mdi-close" /></button>
              </span>
            </div>
          </div>

          <div v-if="sections.length === 0" class="rag-state">
            <p>ไม่พบบล็อกเนื้อหา</p>
          </div>
        </div>

        <AddRelationDialog v-if="dialog" :scope="dialog.scope" :block-id="dialog.blockId" :default-type="dialog.type"
          @close="dialog = null" @save="onRelationSaved" />
      <div v-if="splitting" class="rag-splitmodal" @click.self="splitting = null">
        <div class="rag-splitmodal__card">
          <p class="rag-splitmodal__title">วางเคอร์เซอร์เพื่อแบ่ง หรือเลือกข้อความเพื่อแยกออกเป็นบล็อกใหม่</p>
          <textarea class="rag-splitmodal__text" :value="splitting.text" rows="6" @keyup="onSplitCaret"
              @mouseup="onSplitCaret" @click="onSplitCaret" @select="onSplitCaret" />
          <p class="rag-splitmodal__hint">
            {{ splitSelectionLabel }}
          </p>
          <div class="rag-splitmodal__actions">
            <button class="rag-sec__btn" @click="splitting = null">ยกเลิก</button>
            <button class="lawmeta-save" :disabled="blockBusy" @click="confirmSplit">
              {{ hasSplitSelection ? 'แยกข้อความที่เลือก' : 'แบ่งบล็อก' }}
            </button>
          </div>
        </div>
      </div>
      </div>
    </template>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useComposeStore } from '../../stores/composeStore';
import { useBlockStore } from '../../stores/blockStore';
import { useDocumentStore } from '../../stores/documentStore';
import type { DocumentBlock, LawMeta, LawRelation, RelationScope, RelationType } from '../../types/document';
import AppShell from '../shared/AppShell.vue';
import { buildSections, relationsForSection, documentRelations, type LawSection } from '../../composables/useLawSections';
import AddRelationDialog from '../shared/AddRelationDialog.vue';
import BlockFlow from '../shared/BlockFlow.vue';

const props = defineProps<{ documentId: string }>();

const router = useRouter();
const composeStore = useComposeStore();
const blockStore = useBlockStore();
const documentStore = useDocumentStore();

const repealedText = ref('');

const EMPTY_LAW_META: LawMeta = {
  status: '',
  law_type: '',
  law_group: '',
  agency: '',
  promulgation_date: '',
  effective_date: '',
  gazette_reference: '',
  royal_command: '',
  repealed_laws: [],
};

const lawMetaForm = ref<LawMeta>({ ...EMPTY_LAW_META });

const sections = computed(() => buildSections(composeStore.review));
const relations = computed<LawRelation[]>(() => documentStore.review?.relations ?? []);
const selectedBlockIds = ref<Set<string>>(new Set());
const blockBusy = ref(false);
const splitting = ref<{ blockId: string; pageNo: number; text: string; selectionStart: number; selectionEnd: number } | null>(null);

const dialog = ref<{ scope: RelationScope; blockId: string | null; type: RelationType } | null>(null);

const hasSplitSelection = computed(() => {
  if (!splitting.value) return false;
  return splitting.value.selectionEnd > splitting.value.selectionStart;
});

const splitSelectionLabel = computed(() => {
  if (!splitting.value) return '';
  if (hasSplitSelection.value) {
    const length = splitting.value.selectionEnd - splitting.value.selectionStart;
    return `เลือกข้อความ ${length} ตัวอักษร: ระบบจะแยกข้อความนี้ออกเป็นบล็อกใหม่`;
  }

  return 'ยังไม่ได้เลือกข้อความ: ระบบจะแบ่งบล็อกตามตำแหน่งเคอร์เซอร์';
});

const blockPage = computed<Map<string, number>>(() => {
  const map = new Map<string, number>();
  composeStore.review?.pages.forEach((page) => {
    page.blocks.forEach((block) => map.set(block.block_id, page.page_no));
  });
  return map;
});

function openRelationDialog(scope: RelationScope, blockId: string | null, type: RelationType): void {
  dialog.value = { scope, blockId, type };
}

async function onRelationSaved(relation: LawRelation): Promise<void> {
  dialog.value = null;
  await documentStore.saveRelations([...relations.value, relation]);
}

async function removeRelation(id: string): Promise<void> {
  await documentStore.saveRelations(relations.value.filter((r) => r.id !== id));
}

function sectionRelations(sectionId: string): LawRelation[] {
  return relationsForSection(relations.value, sectionId);
}

function toggleBlock(blockId: string): void {
  const next = new Set(selectedBlockIds.value);
  if (next.has(blockId)) next.delete(blockId);
  else next.add(blockId);
  selectedBlockIds.value = next;
}

function clearSelection(): void {
  selectedBlockIds.value = new Set();
}

async function reloadBlocks(): Promise<void> {
  await Promise.all([
    composeStore.fetch(props.documentId),
    documentStore.fetch(props.documentId),
  ]);
  clearSelection();
}

async function mergeSelected(): Promise<void> {
  const ids = [...selectedBlockIds.value];
  if (ids.length < 2 || blockBusy.value) return;
  blockBusy.value = true;
  try {
    await blockStore.merge(props.documentId, ids);
    await reloadBlocks();
  } catch (e) {
    documentStore.setSaveError(e instanceof Error ? e.message : 'รวมบล็อกไม่สำเร็จ');
  } finally {
    blockBusy.value = false;
  }
}

async function deleteSelected(): Promise<void> {
  const ids = [...selectedBlockIds.value];
  if (ids.length === 0 || blockBusy.value) return;
  blockBusy.value = true;
  try {
    for (const id of ids) {
      const pageNo = blockPage.value.get(id) ?? 1;
      await blockStore.remove(props.documentId, id, pageNo);
    }
    await reloadBlocks();
  } catch (e) {
    documentStore.setSaveError(e instanceof Error ? e.message : 'ลบบล็อกไม่สำเร็จ');
  } finally {
    blockBusy.value = false;
  }
}

async function createBlockAfter(afterBlockId: string): Promise<void> {
  if (blockBusy.value) return;
  blockBusy.value = true;
  try {
    const pageNo = blockPage.value.get(afterBlockId) ?? 1;
    await blockStore.create(props.documentId, {
      page_no: pageNo,
      after_block_id: afterBlockId,
      type: 'paragraph',
      approved_text: '',
    });
    await reloadBlocks();
  } catch (e) {
    documentStore.setSaveError(e instanceof Error ? e.message : 'สร้างบล็อกไม่สำเร็จ');
  } finally {
    blockBusy.value = false;
  }
}

function lastBlockId(section: LawSection): string {
  const lastChild = section.children.at(-1);
  return lastChild ? lastChild.block_id : section.headBlock.block_id;
}

function openSplit(block: DocumentBlock): void {
  splitting.value = {
    blockId: block.block_id,
    pageNo: blockPage.value.get(block.block_id) ?? 1,
    text: block.approved_text || block.normalized_text || block.raw_text || '',
    selectionStart: 0,
    selectionEnd: 0,
  };
}

function onSplitCaret(event: Event): void {
  const el = event.target as HTMLTextAreaElement;
  if (!splitting.value) return;

  splitting.value = {
    ...splitting.value,
    selectionStart: el.selectionStart ?? 0,
    selectionEnd: el.selectionEnd ?? el.selectionStart ?? 0,
  };
}

function escapeForHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

async function confirmSplit(): Promise<void> {
  if (!splitting.value || blockBusy.value) return;
  const { blockId, pageNo, text } = splitting.value;
  const start = Math.min(Math.max(splitting.value.selectionStart, 0), text.length);
  const end = Math.min(Math.max(splitting.value.selectionEnd, start), text.length);
  const before = text.slice(0, start);
  const selected = text.slice(start, end);
  const after = text.slice(end);

  if (start === end) {
    if (before.trim() === '' || after.trim() === '') {
      documentStore.setSaveError('วางเคอร์เซอร์ตรงกลางข้อความเพื่อแบ่งบล็อก');
      return;
    }

    await splitBlockInto(blockId, pageNo, before, after);
    return;
  }

  if (selected.trim() === '') {
    documentStore.setSaveError('เลือกข้อความที่ต้องการแยกออกเป็นบล็อกใหม่');
    return;
  }

  if (before.trim() === '' && after.trim() === '') {
    documentStore.setSaveError('เลือกเฉพาะบางส่วนของบล็อกเพื่อแยกออก');
    return;
  }

  await splitSelectedTextOut(blockId, pageNo, before, selected, after);
}

async function splitBlockInto(blockId: string, pageNo: number, before: string, after: string): Promise<void> {
  blockBusy.value = true;
  try {
    await blockStore.split(props.documentId, blockId, {
      page_no: pageNo,
      before_text: before,
      before_html: `<p>${escapeForHtml(before)}</p>`,
      after_text: after,
      after_html: `<p>${escapeForHtml(after)}</p>`,
    });
    splitting.value = null;
    await reloadBlocks();
  } catch (e) {
    documentStore.setSaveError(e instanceof Error ? e.message : 'แบ่งบล็อกไม่สำเร็จ');
  } finally {
    blockBusy.value = false;
  }
}

async function splitSelectedTextOut(
  blockId: string,
  pageNo: number,
  before: string,
  selected: string,
  after: string,
): Promise<void> {
  blockBusy.value = true;
  try {
    if (before.trim() === '') {
      await callSplit(blockId, pageNo, selected, after);
    } else if (after.trim() === '') {
      await callSplit(blockId, pageNo, before, selected);
    } else {
      await callSplit(blockId, pageNo, `${before}${selected}`, after);
      await callSplit(blockId, pageNo, before, selected);
    }

    splitting.value = null;
    await reloadBlocks();
  } catch (e) {
    documentStore.setSaveError(e instanceof Error ? e.message : 'แยกข้อความที่เลือกไม่สำเร็จ');
  } finally {
    blockBusy.value = false;
  }
}

async function callSplit(blockId: string, pageNo: number, before: string, after: string): Promise<void> {
  await blockStore.split(props.documentId, blockId, {
    page_no: pageNo,
    before_text: before,
    before_html: `<p>${escapeForHtml(before)}</p>`,
    after_text: after,
    after_html: `<p>${escapeForHtml(after)}</p>`,
  });
}

watch(() => documentStore.review?.law_meta, (meta) => {
  const nextMeta = {
    ...EMPTY_LAW_META,
    ...(meta ?? {}),
    repealed_laws: [...(meta?.repealed_laws ?? [])],
  };

  lawMetaForm.value = nextMeta;
  repealedText.value = nextMeta.repealed_laws.join('\n');
}, { immediate: true });

async function handleExport(): Promise<void> {
  await composeStore.triggerExport(props.documentId);
  if (!composeStore.error) {
    router.push(`/law/${props.documentId}`);
  }
}

async function saveLawMeta(): Promise<void> {
  const repealedLaws = repealedText.value
    .split('\n')
    .map((entry) => entry.trim())
    .filter(Boolean);

  const payload: LawMeta = {
    ...lawMetaForm.value,
    repealed_laws: repealedLaws,
  };

  const saved = await documentStore.saveLawMeta(payload);
  if (saved) {
    lawMetaForm.value = payload;
  }
}

onMounted(async () => {
  await Promise.all([
    composeStore.fetch(props.documentId),
    documentStore.fetch(props.documentId),
  ]);
});

onBeforeUnmount(() => {
  composeStore.reset();
  documentStore.reset();
});
</script>

<style scoped>
.rag-content-area {
  display: flex;
  flex-direction: column;
  height: calc(100vh - 244px);
  max-height: calc(100vh - 244px);
  min-height: 0;
  gap: 12px;
  overflow: hidden;
}

.rag-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px;
  gap: 12px;
  color: #64748b;
}

.rag-save-error {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background: #fef2f2;
  color: #b91c1c;
  border: 1px solid #fecaca;
  border-radius: 8px;
  font-size: 13px;
}

.rag-save-error__x {
  margin-left: auto;
  background: none;
  border: none;
  cursor: pointer;
  color: inherit;
  font-size: 13px;
}

.rag-actionbar {
  position: sticky;
  top: 0;
  z-index: 5;
  display: flex;
  align-items: center;
  gap: 8px;
  background: #1a3673;
  color: #fff;
  padding: 8px 12px;
  border-radius: 8px;
}

.rag-actionbar__count {
  font-size: 13px;
  font-weight: 600;
  margin-right: auto;
}

.rag-actionbar__btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  border: none;
  border-radius: 7px;
  padding: 6px 12px;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
}

.rag-actionbar__btn:disabled {
  opacity: 0.5;
  cursor: default;
}

.rag-actionbar__btn--danger {
  background: rgba(220, 38, 38, 0.85);
}

.lawmeta-panel {
  background: #fff;
  border: 1px solid #dbe6f4;
  border-radius: 10px;
  padding: 10px 14px 14px;
}

.lawmeta-panel__summary {
  cursor: pointer;
  list-style: none;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 700;
  color: #1a3673;
}

.lawmeta-panel__summary::-webkit-details-marker {
  display: none;
}

.lawmeta-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 14px;
  margin-top: 14px;
}

.lawmeta-grid label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #475569;
}

.lawmeta-grid input,
.lawmeta-grid textarea {
  width: 100%;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 8px 10px;
  font: inherit;
  background: #fff;
  color: #1e293b;
}

.lawmeta-grid__full {
  grid-column: 1 / -1;
}

.lawmeta-save {
  margin-top: 12px;
  border: none;
  border-radius: 8px;
  background: #1a3673;
  color: #fff;
  padding: 9px 14px;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.lawmeta-save:disabled {
  opacity: 0.6;
  cursor: default;
}

.rag-block-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  overflow-y: auto;
  overflow-x: hidden;
  flex: 1;
  min-height: 0;
  padding-right: 4px;
  overscroll-behavior: contain;
}


.rag-sec {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 14px 16px;
  min-width: 0;
}

.rag-sec__head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}

.rag-sec__badge {
  background: #ecfdf5;
  color: #047857;
  font-size: 12px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 8px;
}

.rag-sec__badge--chapter {
  background: #eef2ff;
  color: #4338ca;
}

.rag-sec__actions {
  margin-left: auto;
  display: flex;
  gap: 6px;
}

.rag-sec__btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px solid #cbd5e1;
  background: #fff;
  border-radius: 7px;
  padding: 5px 10px;
  font: inherit;
  font-size: 12px;
  cursor: pointer;
  color: #334155;
}

.rag-sec__btn--danger {
  color: #dc2626;
  border-color: #fecaca;
}

.rag-sec__flow {
  display: flex;
  flex-direction: column;
  gap: 4px;
  align-items: stretch;
}

.rag-sec__addblock {
  margin-top: 8px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px dashed #cbd5e1;
  background: #fff;
  border-radius: 7px;
  padding: 5px 10px;
  font: inherit;
  font-size: 12px;
  cursor: pointer;
  color: #475569;
}

.rag-sec__addblock:disabled {
  opacity: 0.5;
  cursor: default;
}

.rag-sec__rels {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
}

.rag-rel-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 12px;
  padding: 3px 8px;
  border-radius: 999px;
}

.rag-rel-chip--repeal {
  background: #fef2f2;
  color: #dc2626;
}

.rag-rel-chip__x {
  background: none;
  border: none;
  cursor: pointer;
  color: inherit;
  font-size: 13px;
  line-height: 1;
  padding: 0;
}

.rag-blockrow {
  display: grid;
  grid-template-columns: minmax(28px, 10%) minmax(0, 1fr) 32px;
  align-items: start;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 10px;
  cursor: pointer;
  border: 1px solid transparent;
  transition: background 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.rag-blockrow:hover {
  background: #f8fafc;
  border-color: #e2e8f0;
}

.rag-blockrow.is-selected {
  background: #eef2ff;
  border-color: #c7d2fe;
  box-shadow: inset 0 0 0 1px rgba(99, 102, 241, 0.08);
}

.rag-blockrow__cb-input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.rag-blockrow__cb {
  width: 22px;
  height: 22px;
  margin-top: 2px;
  flex-shrink: 0;
  align-self: start;
  justify-self: start;
  border-radius: 7px;
  border: 1.5px solid #94a3b8;
  background: #fff;
  color: transparent;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all 0.18s ease;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
}

.rag-blockrow__cb .mdi {
  font-size: 15px;
}

.rag-blockrow.is-selected .rag-blockrow__cb {
  background: linear-gradient(135deg, #1d4ed8 0%, #4338ca 100%);
  border-color: #1d4ed8;
  color: #fff;
  box-shadow: 0 8px 18px rgba(37, 99, 235, 0.2);
}

.rag-blockrow .block-flow {
  min-width: 0;
  align-self: start;
  max-width: 100%;
  overflow-wrap: anywhere;
  margin-left: 0 !important;
  text-indent: 0 !important;
}

.rag-blockrow__split {
  flex-shrink: 0;
  align-self: start;
  justify-self: end;
  background: none;
  border: none;
  color: #94a3b8;
  cursor: pointer;
  padding: 4px;
  margin-top: 0;
}

.rag-blockrow__split:hover {
  color: #1a3673;
}

.rag-splitmodal {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 50;
}

.rag-splitmodal__card {
  background: #fff;
  border-radius: 12px;
  padding: 18px;
  width: min(560px, 92vw);
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.rag-splitmodal__title {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #1a3673;
}

.rag-splitmodal__text {
  width: 100%;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 10px;
  font: inherit;
  line-height: 1.7;
  resize: vertical;
}

.rag-splitmodal__hint {
  margin: -4px 0 0;
  color: #64748b;
  font-size: 12px;
}

.rag-splitmodal__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.lawmeta-rels {
  margin-top: 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.lawmeta-rels__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  font-weight: 700;
  color: #1a3673;
}

@media (max-width: 800px) {
  .lawmeta-grid {
    grid-template-columns: 1fr;
  }
}
</style>
