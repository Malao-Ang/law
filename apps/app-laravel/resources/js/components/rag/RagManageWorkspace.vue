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
    <div v-if="composeStore.loading" class="d-flex flex-column align-center justify-center pa-12 ga-3 text-medium-emphasis">
      <v-progress-circular indeterminate color="primary" />
      <span>กำลังโหลดบล็อก...</span>
    </div>

    <!-- Error -->
    <div v-else-if="composeStore.error" class="d-flex flex-column align-center justify-center pa-12 ga-3 text-medium-emphasis">
      <v-icon icon="mdi-alert-circle-outline" size="32" color="error" />
      <span>{{ composeStore.error }}</span>
      <v-btn variant="outlined" size="small" @click="composeStore.setError()">ปิด</v-btn>
    </div>

    <template v-else>
      <div class="rag-content-area">
        <!-- Selection action bar -->
        <div v-if="selectedBlockIds.size > 0" class="d-flex align-center ga-2 px-3 py-2 rounded-lg"
          style="position:sticky;top:0;z-index:5;background:#1a3673;color:#fff">
          <span class="text-body-2 font-weight-bold mr-auto">เลือก {{ selectedBlockIds.size }} บล็อก</span>
          <v-btn size="small" :disabled="selectedBlockIds.size < 2 || blockBusy"
            prepend-icon="mdi-table-merge-cells"
            style="background:rgba(255,255,255,0.14);color:#fff"
            @click="mergeSelected">รวม</v-btn>
          <v-btn size="small" :disabled="blockBusy"
            prepend-icon="mdi-delete-outline"
            style="background:rgba(220,38,38,0.85);color:#fff"
            @click="deleteSelected">ลบ</v-btn>
          <v-btn size="small" :disabled="selectedBlockIds.size !== 1 || blockBusy"
            prepend-icon="mdi-call-split"
            style="background:rgba(5,150,105,0.85);color:#fff"
            @click="openSplitFromSelection">แบ่ง</v-btn>
          <v-btn size="small" :disabled="blockBusy"
            style="background:rgba(255,255,255,0.14);color:#fff"
            @click="clearSelection">ยกเลิกการเลือก</v-btn>
        </div>

        <!-- Save error -->
        <v-alert v-if="documentStore.saveError" type="error" variant="tonal" density="compact" closable
          @click:close="documentStore.setSaveError()">
          {{ documentStore.saveError }}
        </v-alert>

        <!-- Law metadata panel -->
        <v-expansion-panels variant="accordion">
          <v-expansion-panel>
            <v-expansion-panel-title>
              <v-icon icon="mdi-information-outline" size="16" class="mr-2" />
              ข้อมูลกฎหมาย (หัวเอกสาร)
            </v-expansion-panel-title>
            <v-expansion-panel-text>
              <v-row dense>
                <v-col cols="12" sm="6">
                  <v-text-field v-model="lawMetaForm.status" label="สถานะ" placeholder="มีผลใช้บังคับ" />
                </v-col>
                <v-col cols="12" sm="6">
                  <v-text-field v-model="lawMetaForm.law_type" label="ประเภท" placeholder="พระราชบัญญัติ" />
                </v-col>
                <v-col cols="12" sm="6">
                  <v-text-field v-model="lawMetaForm.law_group" label="กลุ่มกฎหมาย" placeholder="ด้านวิชาการ" />
                </v-col>
                <v-col cols="12" sm="6">
                  <v-text-field v-model="lawMetaForm.agency" label="หน่วยงาน" placeholder="มหาวิทยาลัยบูรพา" />
                </v-col>
                <v-col cols="12" sm="6">
                  <v-text-field v-model="lawMetaForm.promulgation_date" label="วันที่ประกาศ" placeholder="9 มกราคม 2551" />
                </v-col>
                <v-col cols="12" sm="6">
                  <v-text-field v-model="lawMetaForm.effective_date" label="วันที่มีผลบังคับ" />
                </v-col>
                <v-col cols="12" sm="6">
                  <v-text-field v-model="lawMetaForm.gazette_reference" label="ราชกิจจานุเบกษา" placeholder="เล่ม 125 ตอนที่ 5 ก" />
                </v-col>
                <v-col cols="12" sm="6">
                  <v-text-field v-model="lawMetaForm.royal_command" label="พระบรมราชโองการ" placeholder="ภูมิพลอดุลยเดช ปร." />
                </v-col>
                <v-col cols="12">
                  <v-textarea v-model="repealedText" label="กฎหมายที่ถูกยกเลิก (บรรทัดละ 1 รายการ)" rows="3" />
                </v-col>
              </v-row>

              <div class="mt-3">
                <div class="d-flex align-center justify-space-between text-body-2 font-weight-bold mb-2"
                  style="color:#1a3673">
                  <span>ความสัมพันธ์ระดับเอกสาร</span>
                  <v-btn size="x-small" variant="outlined" prepend-icon="mdi-plus"
                    @click="openRelationDialog('document', null, 'related')">เพิ่ม</v-btn>
                </div>
                <div class="d-flex flex-wrap ga-2">
                  <v-chip v-for="rel in documentRelations(relations)" :key="rel.id" size="small" closable
                    :color="rel.type === 'repeals' ? 'error' : 'primary'" variant="tonal"
                    :prepend-icon="rel.type === 'repeals' ? 'mdi-cancel' : 'mdi-link-variant'"
                    @click:close="removeRelation(rel.id)">
                    {{ rel.target_title }}
                  </v-chip>
                </div>
              </div>

              <v-btn color="primary" class="mt-3" prepend-icon="mdi-content-save-outline"
                :disabled="documentStore.saving" @click="saveLawMeta">
                บันทึกข้อมูลกฎหมาย
              </v-btn>
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>

        <!-- Section list (e-Law style) -->
        <div class="rag-block-list">
          <div v-for="section in sections" :key="section.id" class="rag-sec">
            <div class="rag-sec__head">
              <v-chip size="x-small" :color="section.isChapter ? 'indigo' : 'success'" variant="tonal">
                {{ section.badge }}
              </v-chip>
              <div class="rag-sec__actions">
                <v-btn size="x-small" variant="outlined" prepend-icon="mdi-link-variant"
                  @click="openRelationDialog('section', section.id, 'related')">เพิ่มความสัมพันธ์</v-btn>
                <v-btn size="x-small" variant="outlined" color="error" prepend-icon="mdi-cancel"
                  @click="openRelationDialog('section', section.id, 'repeals')">ยกเลิกมาตรา</v-btn>
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

            <v-btn size="x-small" variant="outlined" class="mt-2" prepend-icon="mdi-plus"
              :disabled="blockBusy" @click="createBlockAfter(lastBlockId(section))">
              เพิ่มบล็อกใต้หัวข้อนี้
            </v-btn>

            <div v-if="sectionRelations(section.id).length" class="rag-sec__rels">
              <v-chip v-for="rel in sectionRelations(section.id)" :key="rel.id" size="small" closable
                :color="rel.type === 'repeals' ? 'error' : 'primary'" variant="tonal"
                :prepend-icon="rel.type === 'repeals' ? 'mdi-cancel' : 'mdi-link-variant'"
                @click:close="removeRelation(rel.id)">
                {{ rel.target_title }}{{ rel.target_section ? ' · ' + rel.target_section : '' }}
              </v-chip>
            </div>
          </div>

          <div v-if="sections.length === 0" class="d-flex flex-column align-center justify-center pa-12 ga-3 text-medium-emphasis">
            <span>ไม่พบบล็อกเนื้อหา</span>
          </div>
        </div>

        <AddRelationDialog v-if="dialog" :scope="dialog.scope" :block-id="dialog.blockId" :default-type="dialog.type"
          @close="dialog = null" @save="onRelationSaved" />

        <!-- Split modal -->
        <v-dialog v-model="splitDialogOpen" max-width="560">
          <v-card v-if="splitting" class="pa-4">
            <div class="text-body-1 font-weight-bold mb-3" style="color:#1a3673">
              วางเคอร์เซอร์เพื่อแบ่ง หรือเลือกข้อความเพื่อแยกออกเป็นบล็อกใหม่
            </div>
            <textarea class="rag-splitmodal__text" :value="splitting.text" rows="6" @keyup="onSplitCaret"
              @mouseup="onSplitCaret" @click="onSplitCaret" @select="onSplitCaret" />
            <div class="text-caption text-medium-emphasis mt-1">{{ splitSelectionLabel }}</div>
            <div class="d-flex justify-end ga-2 mt-3">
              <v-btn variant="outlined" @click="splitting = null">ยกเลิก</v-btn>
              <v-btn color="primary" :disabled="blockBusy" @click="confirmSplit">
                {{ hasSplitSelection ? 'แยกข้อความที่เลือก' : 'แบ่งบล็อก' }}
              </v-btn>
            </div>
          </v-card>
        </v-dialog>
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
const allBlocks = computed<DocumentBlock[]>(() =>
  sections.value.flatMap(s => [s.headBlock, ...s.children]),
);
const relations = computed<LawRelation[]>(() => documentStore.review?.relations ?? []);
const selectedBlockIds = ref<Set<string>>(new Set());
const blockBusy = ref(false);
const splitting = ref<{ blockId: string; pageNo: number; text: string; selectionStart: number; selectionEnd: number } | null>(null);

const dialog = ref<{ scope: RelationScope; blockId: string | null; type: RelationType } | null>(null);

const splitDialogOpen = computed({
  get: () => splitting.value !== null,
  set: (v) => { if (!v) splitting.value = null; },
});

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

function openSplitFromSelection(): void {
  const [blockId] = [...selectedBlockIds.value];
  if (!blockId) return;
  const block = allBlocks.value.find(b => b.block_id === blockId);
  if (block) openSplit(block);
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
      const firstResult = await callSplit(blockId, pageNo, `${before}${selected}`, after);
      await callSplit(firstResult.first.block_id, pageNo, before, selected);
    }

    splitting.value = null;
    await reloadBlocks();
  } catch (e) {
    documentStore.setSaveError(e instanceof Error ? e.message : 'แยกข้อความที่เลือกไม่สำเร็จ');
  } finally {
    blockBusy.value = false;
  }
}

async function callSplit(
  blockId: string,
  pageNo: number,
  before: string,
  after: string,
): Promise<{ status: string; first: DocumentBlock; second: DocumentBlock }> {
  return blockStore.split(props.documentId, blockId, {
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
/* ponytail: rag-content-area height calc + rag-block-list scroll container — no Vuetify equivalent */
.rag-content-area {
  display: flex;
  flex-direction: column;
  height: calc(100vh - 244px);
  max-height: calc(100vh - 244px);
  min-height: 0;
  gap: 12px;
  overflow: hidden;
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

.rag-sec__actions {
  margin-left: auto;
  display: flex;
  gap: 6px;
}

.rag-sec__flow {
  display: flex;
  flex-direction: column;
  gap: 4px;
  align-items: stretch;
}

.rag-sec__rels {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
}

/* ponytail: custom checkbox grid layout — no Vuetify equivalent */
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

/* ponytail: native textarea inside v-dialog — kept for @select event support */
.rag-splitmodal__text {
  width: 100%;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 10px;
  font: inherit;
  line-height: 1.7;
  resize: vertical;
}
</style>
