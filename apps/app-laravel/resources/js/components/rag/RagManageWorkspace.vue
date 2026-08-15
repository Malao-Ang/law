<template>
  <AppShell :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'จัดลำดับเนื้อหา']" title="" full-height>
    <WorkflowFooterBar
      :step="3"
      :next-disabled="blockBusy"
      :next-loading="documentStore.saving"
      @back="router.push(`/documents/${props.documentId}/review`)"
      @next="goToLawInfo"
    />

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
      <WorkflowStepper :step="3" description="จัดการความสัมพันธ์และบล็อกก่อนเผยแพร่" />
      <div class="rag-content-area">
        <!-- Selection action bar -->
        <div
          class="rag-selection-bar d-flex align-center ga-2 px-3 py-2 rounded-lg"
          :class="{ 'is-visible': selectedBlockIds.size > 0 }"
        >
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
            @click="splitFromSelection">แบ่ง</v-btn>
          <v-btn size="small" :disabled="blockBusy"
            style="background:rgba(255,255,255,0.14);color:#fff"
            @click="clearSelection">ยกเลิกการเลือก</v-btn>
        </div>

        <!-- Save error -->
        <v-alert v-if="documentStore.saveError" type="error" variant="tonal" density="compact" closable
          @click:close="documentStore.setSaveError()">
          {{ documentStore.saveError }}
        </v-alert>

        <!-- Document content card -->
        <section class="rag-doc-card">
          <header class="rag-doc-card__head">
            <span class="rag-doc-card__icon"><v-icon icon="mdi-file-document-edit-outline" size="18" /></span>
            <span class="rag-doc-card__title">เนื้อหาเอกสารกฎหมาย</span>
          </header>
          <div ref="blockListEl" class="rag-block-list">
          <div v-for="section in sections" :key="section.id" :data-section-id="section.id" class="rag-sec">
            <div class="rag-sec__head">
              <v-menu location="bottom start" :close-on-content-click="true">
                <template #activator="{ props: menuProps }">
                  <v-chip v-bind="menuProps" size="small"
                    :color="containerTypeColor(section)"
                    :variant="section.headBlock.meta.chunk_type ? 'tonal' : 'outlined'"
                    class="rag-sec__typechip">
                    {{ containerTypeLabel(section) }}
                    <v-icon icon="mdi-chevron-down" size="12" class="ml-1" />
                  </v-chip>
                </template>
                <v-list density="compact" :min-width="180">
                  <v-list-item
                    v-for="ct in HEAD_CHUNK_TYPES"
                    :key="ct"
                    :title="CHUNK_TYPE_LABELS[ct]"
                    :active="normalizeChunkType(section.headBlock.meta.chunk_type) === ct"
                    @click="setChunkType(section.headBlock, ct)"
                  />
                </v-list>
              </v-menu>
            </div>
            <div class="rag-sec__flow">
              <div
                class="rag-blockrow"
                :class="{ 'is-selected': selectedBlockIds.has(section.headBlock.block_id) }"
                role="checkbox"
                tabindex="0"
                :aria-checked="selectedBlockIds.has(section.headBlock.block_id)"
                @click="toggleBlock(section.headBlock.block_id)"
                @keydown.enter.prevent="toggleBlock(section.headBlock.block_id)"
                @keydown.space.prevent="toggleBlock(section.headBlock.block_id)"
              >
                <span class="rag-blockrow__cb" aria-hidden="true">
                  <span class="mdi mdi-check"></span>
                </span>
                <BlockFlow
                  :block="section.headBlock"
                  :override-text="section.headBlock.meta?.reviewed_html ? null : (section.headBodyText || null)"
                />
                <button class="rag-blockrow__split" :disabled="blockBusy" title="แยกบรรทัด"
                  @click.prevent.stop="openLineSplit(section.headBlock)">
                  <span class="mdi mdi-call-split" />
                </button>
              </div>
              <div
                v-for="child in section.children"
                :key="child.block_id"
                class="rag-blockrow"
                :class="{ 'is-selected': selectedBlockIds.has(child.block_id) }"
                role="checkbox"
                tabindex="0"
                :aria-checked="selectedBlockIds.has(child.block_id)"
                @click="toggleBlock(child.block_id)"
                @keydown.enter.prevent="toggleBlock(child.block_id)"
                @keydown.space.prevent="toggleBlock(child.block_id)"
              >
                <span class="rag-blockrow__cb" aria-hidden="true">
                  <span class="mdi mdi-check"></span>
                </span>
                <BlockFlow :block="child" />
                <button class="rag-blockrow__split" :disabled="blockBusy" title="แยกบรรทัด"
                  @click.prevent.stop="openLineSplit(child)">
                  <span class="mdi mdi-call-split" />
                </button>
              </div>
            </div>
          </div>

          <div v-if="sections.length === 0" class="d-flex flex-column align-center justify-center pa-12 ga-3 text-medium-emphasis">
            <span>ไม่พบบล็อกเนื้อหา</span>
          </div>
          </div>
        </section>

      </div>

      <!-- Floating history button -->
      <v-tooltip text="ประวัติชั่วคราว" location="left">
        <template #activator="{ props: tipProps }">
          <v-badge
            :content="history.length"
            :model-value="history.length > 0"
            color="error"
            offset-x="10"
            offset-y="10"
            class="rag-history-fab"
          >
            <v-btn
              v-bind="tipProps"
              icon="mdi-history"
              color="admin-primary"
              size="large"
              elevation="6"
              :disabled="history.length === 0 || blockBusy"
              @click="historyDrawer = !historyDrawer"
            />
          </v-badge>
        </template>
      </v-tooltip>
    </template>
    <v-navigation-drawer
      v-model="historyDrawer"
      location="right"
      temporary
      width="360"
    >
      <div class="rag-history-drawer">
        <div class="rag-history-drawer__head">
          <div>
            <div class="text-subtitle-1 font-weight-bold">ประวัติชั่วคราว</div>
            <div class="text-caption text-medium-emphasis">ย้อนกลับได้เฉพาะการรวมและแยกบล็อกในรอบนี้</div>
          </div>
          <v-btn icon="mdi-close" variant="text" @click="historyDrawer = false" />
        </div>

        <div v-if="history.length === 0" class="rag-history-drawer__empty">
          <v-icon icon="mdi-history" size="24" color="medium-emphasis" />
          <div class="text-body-2 text-medium-emphasis">ยังไม่มีประวัติให้ย้อนกลับ</div>
        </div>

        <div v-else class="rag-history-drawer__list">
          <div v-for="entry in history" :key="entry.id" class="rag-history-drawer__item">
            <div class="rag-history-drawer__meta">
              <div class="text-body-2 font-weight-medium">{{ entry.label }}</div>
            </div>
            <v-btn size="small" variant="outlined" color="primary" :disabled="blockBusy" @click="restoreHistory(entry)">
              ย้อน
            </v-btn>
          </div>
        </div>
      </div>
    </v-navigation-drawer>
    <SplitBlockDialog
      v-model="splitDialog.open"
      :block="splitDialog.block"
      @confirm="onLineSplitConfirm"
    />
  </AppShell>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useComposeStore } from '../../stores/composeStore';
import { useBlockStore } from '../../stores/blockStore';
import { useDocumentStore } from '../../stores/documentStore';
import { useSnackbarStore } from '../../stores/snackbarStore';
import type { DocumentBlock } from '../../types/document';
import AppShell from '../shared/AppShell.vue';
import WorkflowStepper from '../shared/WorkflowStepper.vue';
import WorkflowFooterBar from '../shared/WorkflowFooterBar.vue';
import { buildSections, suggestChunkType, type LawSection } from '../../composables/useLawSections';
import BlockFlow from '../shared/BlockFlow.vue';
import SplitBlockDialog from './SplitBlockDialog.vue';
import { HEAD_CHUNK_TYPES, CHUNK_TYPE_LABELS, CHUNK_TYPE_COLORS, normalizeChunkType } from '../../types/chunkType';
import type { ChunkType } from '../../types/chunkType';
import Swal from 'sweetalert2';

const props = defineProps<{ documentId: string }>();

const router = useRouter();
const composeStore = useComposeStore();
const blockStore = useBlockStore();
const documentStore = useDocumentStore();
const snackbar = useSnackbarStore();

type PageBlocks = { page_no: number; blocks: DocumentBlock[] };
type HistoryEntry = { id: string; label: string; snapshot: PageBlocks[] };

const sections = computed(() => buildSections(composeStore.review));

function containerType(section: LawSection): ChunkType | null {
  const stored = normalizeChunkType(section.headBlock.meta.chunk_type);
  if (stored) return stored;
  if (section.isHeader) return 'TITLE';
  return suggestChunkType(section.headBlock);
}

function containerTypeLabel(section: LawSection): string {
  const ct = containerType(section);
  if (!ct) return 'เลือกประเภท...';
  const label = CHUNK_TYPE_LABELS[ct];
  return section.headBlock.meta.chunk_type ? label : `${label} (แนะนำ)`;
}

function containerTypeColor(section: LawSection): string | undefined {
  const ct = containerType(section);
  if (!ct) return undefined;
  return section.headBlock.meta.chunk_type ? CHUNK_TYPE_COLORS[ct] : 'grey';
}

const allBlocks = computed<DocumentBlock[]>(() =>
  sections.value.flatMap(s => [s.headBlock, ...s.children]),
);
const selectedBlockIds = ref<Set<string>>(new Set());
const blockBusy = ref(false);
const splitDialog = ref<{ open: boolean; block: DocumentBlock | null }>({ open: false, block: null });
const blockListEl = ref<HTMLElement | null>(null);
const history = ref<HistoryEntry[]>([]);
const historyDrawer = ref(false);

const blockPage = computed<Map<string, number>>(() => {
  const map = new Map<string, number>();
  composeStore.review?.pages.forEach((page) => {
    page.blocks.forEach((block) => map.set(block.block_id, page.page_no));
  });
  return map;
});

async function goToLawInfo(): Promise<void> {
  if (blockBusy.value) return;

  // Auto-persist suggestions for untyped head blocks.
  const toPersist = sections.value.filter(
    (s) => !s.headBlock.meta.chunk_type && containerType(s),
  );
  if (toPersist.length > 0) {
    blockBusy.value = true;
    try {
      await Promise.all(
        toPersist.map((s) => {
          const suggested = containerType(s)!;
          s.headBlock.meta.chunk_type = suggested;
          const pageNo = blockPage.value.get(s.headBlock.block_id) ?? 1;
          return blockStore.patchChunkType(props.documentId, s.headBlock, pageNo, suggested);
        }),
      );
    } catch (e) {
      documentStore.setSaveError(e instanceof Error ? e.message : 'บันทึกประเภทไม่สำเร็จ');
      return;
    } finally {
      blockBusy.value = false;
    }
  }

  // Block if any section still has no type.
  const missing = sections.value.filter((s) => !s.headBlock.meta.chunk_type);
  if (missing.length > 0) {
    await Swal.fire({
      icon: 'warning',
      title: 'ยังกำหนดประเภทไม่ครบ',
      html:
        'กรุณาเลือกประเภทให้ส่วนต่อไปนี้ก่อนดำเนินการต่อ:<br><br>' +
        missing.map((s) => `• ${escapeForHtml(s.badge)}`).join('<br>'),
      confirmButtonText: 'ตกลง',
      confirmButtonColor: '#1a3673',
    });
    // Scroll to and highlight the first untyped section so the user can fix it.
    const firstMissingId = missing[0].id;
    blockListEl.value
      ?.querySelector<HTMLElement>(`[data-section-id="${firstMissingId}"]`)
      ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  const progressed = await documentStore.completeWorkflowStep(3);
  if (!progressed) return;
  history.value = [];
  router.push(`/documents/${props.documentId}/law-info`);
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

function blockText(block: DocumentBlock): string {
  return block.approved_text || block.normalized_text || block.raw_text || '';
}

function splitSeparator(block: DocumentBlock): '\n' | ' ' {
  return blockText(block).includes('\n') ? '\n' : ' ';
}

function cloneHistorySnapshot(): PageBlocks[] {
  return (composeStore.review?.pages ?? []).map((page) => ({
    page_no: page.page_no,
    blocks: JSON.parse(JSON.stringify(page.blocks)) as DocumentBlock[],
  }));
}

function createHistoryId(): string {
  return `history-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
}

function pushHistory(label: string): string | null {
  if (!composeStore.review) return null;

  const id = createHistoryId();
  history.value.unshift({
    id,
    label,
    snapshot: cloneHistorySnapshot(),
  });

  return id;
}

function removeHistoryEntry(entryId: string | null): void {
  if (!entryId) return;
  history.value = history.value.filter((entry) => entry.id !== entryId);
}

async function restoreHistory(entry: HistoryEntry): Promise<void> {
  if (blockBusy.value) return;

  const entryIndex = history.value.findIndex((item) => item.id === entry.id);
  if (entryIndex < 0) return;

  blockBusy.value = true;
  try {
    await blockStore.restore(props.documentId, entry.snapshot);
    await reloadBlocks();
    history.value = history.value.slice(entryIndex + 1);
    if (history.value.length === 0) historyDrawer.value = false;
    snackbar.success('ย้อนประวัติการแก้ไขแล้ว');
  } catch (e) {
    snackbar.error(e instanceof Error ? e.message : 'ย้อนประวัติไม่สำเร็จ');
  } finally {
    blockBusy.value = false;
  }
}

async function reloadBlocks(): Promise<void> {
  // ponytail: Vuetify overlay focus-restore scrolls the list after DOM replace; pin scrollTop
  const scrollTop = blockListEl.value?.scrollTop ?? 0;
  await Promise.all([
    composeStore.fetch(props.documentId),
    documentStore.fetch(props.documentId),
  ]);
  clearSelection();
  await nextTick();
  if (blockListEl.value) blockListEl.value.scrollTop = scrollTop;
}

// A head chunk-type to assign when a block should start a section.
function headTypeFor(block: DocumentBlock): ChunkType {
  return suggestChunkType(block) ?? 'CLAUSE';
}

async function persistChunkType(block: DocumentBlock, chunkType: ChunkType | null): Promise<void> {
  const pageNo = blockPage.value.get(block.block_id) ?? 1;
  await blockStore.patchChunkType(props.documentId, block, pageNo, chunkType);
}

async function mergeSelected(): Promise<void> {
  const selected = allBlocks.value.filter((b) => selectedBlockIds.value.has(b.block_id));
  if (selected.length < 2 || blockBusy.value) return;

  const historyId = pushHistory(`รวม ${selected.length} บล็อก`);
  blockBusy.value = true;
  try {
    await blockStore.merge(props.documentId, selected.map((block) => block.block_id));
    await reloadBlocks();
    snackbar.success('รวมบล็อกแล้ว');
  } catch (e) {
    removeHistoryEntry(historyId);
    snackbar.error(e instanceof Error ? e.message : 'รวมบล็อกไม่สำเร็จ');
    await reloadBlocks();
  } finally {
    blockBusy.value = false;
  }
}

function openLineSplit(block: DocumentBlock): void {
  if (blockBusy.value) return;
  splitDialog.value = { open: true, block };
}

async function onLineSplitConfirm(pieces: string[]): Promise<void> {
  const block = splitDialog.value.block;
  splitDialog.value.open = false;
  if (!block || pieces.length < 2) return;

  const historyId = pushHistory('แยกบล็อก');
  blockBusy.value = true;
  try {
    const pageNo = blockPage.value.get(block.block_id) ?? 1;
    const separator = splitSeparator(block);
    let tailId = block.block_id;

    for (let index = 0; index < pieces.length - 1; index += 1) {
      const res = await blockStore.split(props.documentId, tailId, {
        page_no: pageNo,
        before_text: pieces[index],
        before_html: '',
        after_text: pieces.slice(index + 1).join(separator),
        after_html: '',
      });
      tailId = res.second.block_id;
    }

    await reloadBlocks();
    snackbar.success('แยกบรรทัดออกเป็นบล็อกใหม่แล้ว');
  } catch (e) {
    removeHistoryEntry(historyId);
    snackbar.error(e instanceof Error ? e.message : 'แยกบรรทัดไม่สำเร็จ');
    await reloadBlocks();
  } finally {
    blockBusy.value = false;
  }
}

async function splitBlock(block: DocumentBlock): Promise<void> {
  if (blockBusy.value) return;
  const isHead = !!block.meta.chunk_type && (HEAD_CHUNK_TYPES as readonly string[]).includes(normalizeChunkType(block.meta.chunk_type) ?? '');

  if (isHead) {
    // Splitting a header detaches its body: the header stays its own section and
    // the blocks under it start a NEW section (promote the first child to a head).
    // Never merges into the previous section. Guarded by a confirmation dialog.
    const section = sections.value.find((s) => s.headBlock.block_id === block.block_id);
    const firstChild = section?.children[0];
    if (!firstChild) {
      snackbar.success('ไม่มีเนื้อหาใต้หัวข้อให้แยกออก');
      return;
    }
    const confirmed = await Swal.fire({
      title: 'คุณมั่นใจไหมที่ต้องการแยกหัวข้อหลักออก',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'แยกออก',
      cancelButtonText: 'ยกเลิก',
      confirmButtonColor: '#1a3673',
      cancelButtonColor: '#94a3b8',
    });
    if (!confirmed.isConfirmed) return;

    blockBusy.value = true;
    try {
      const childHeadType = headTypeFor(firstChild);
      firstChild.meta.chunk_type = childHeadType;    // optimistic → body becomes a new section
      await persistChunkType(firstChild, childHeadType);
      clearSelection();
      snackbar.success('แยกหัวข้อหลักออกแล้ว');
    } catch (e) {
      snackbar.error(e instanceof Error ? e.message : 'แบ่ง section ไม่สำเร็จ');
      await reloadBlocks();
    } finally {
      blockBusy.value = false;
    }
    return;
  }

  // Non-head block → promote it to a section head, splitting the section here.
  blockBusy.value = true;
  try {
    const headType = headTypeFor(block);
    block.meta.chunk_type = headType;                // optimistic → starts a new section
    await persistChunkType(block, headType);
    clearSelection();
    snackbar.success('เริ่ม section ใหม่แล้ว');
  } catch (e) {
    snackbar.error(e instanceof Error ? e.message : 'แบ่ง section ไม่สำเร็จ');
    await reloadBlocks();
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


function splitFromSelection(): void {
  const [blockId] = [...selectedBlockIds.value];
  if (!blockId) return;
  const block = allBlocks.value.find((b) => b.block_id === blockId);
  if (block) void splitBlock(block);
}

async function setChunkType(block: DocumentBlock, chunkType: string | null): Promise<void> {
  const previous = block.meta.chunk_type ?? null;
  if (previous === chunkType) return;

  // Optimistic: update the chip instantly, then persist in the background.
  block.meta.chunk_type = chunkType;
  const pageNo = blockPage.value.get(block.block_id) ?? 1;
  try {
    await blockStore.patchChunkType(props.documentId, block, pageNo, chunkType);
    snackbar.success('บันทึกประเภทแล้ว');
  } catch (e) {
    block.meta.chunk_type = previous; // revert on failure
    snackbar.error(e instanceof Error ? e.message : 'บันทึกประเภทไม่สำเร็จ');
  }
}

function escapeForHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
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
  flex: 1 1 0;
  max-height: 100%;
  min-height: 0;
  gap: 12px;
  overflow: hidden;
}

.rag-history-fab {
  position: fixed;
  right: 24px;
  bottom: 78px;
  z-index: 60;
}

.rag-selection-bar {
  background: #1a3673;
  color: #fff;
  flex: 0 0 auto;
  min-height: 0;
  height: 0;
  padding-top: 0 !important;
  padding-bottom: 0 !important;
  overflow: hidden;
  opacity: 0;
  pointer-events: none;
  position: sticky;
  top: 0;
  visibility: hidden;
  z-index: 5;
  transition: height 0.15s ease, opacity 0.15s ease;
}

.rag-selection-bar.is-visible {
  min-height: 44px;
  height: auto;
  padding-top: 8px !important;
  padding-bottom: 8px !important;
  opacity: 1;
  pointer-events: auto;
  visibility: visible;
}

.rag-history-drawer {
  display: flex;
  flex-direction: column;
  height: 100%;
  padding: 18px 16px;
  gap: 14px;
}

.rag-history-drawer__head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.rag-history-drawer__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  flex: 1 1 auto;
  border: 1px dashed #cbd5e1;
  border-radius: 14px;
  background: #f8fafc;
  padding: 18px;
}

.rag-history-drawer__list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  overflow-y: auto;
}

.rag-history-drawer__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 10px 12px;
  background: #f8fbff;
}

.rag-history-drawer__meta {
  min-width: 0;
}

.rag-doc-card {
  display: flex;
  flex-direction: column;
  flex: 1 1 0;
  min-height: 0;
  background: #fff;
  border: 1px solid #e6e8ef;
  border-radius: 16px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
  overflow: hidden;
}

.rag-doc-card__head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 18px 24px 14px;
  border-bottom: 1px solid #eef1f6;
  flex: 0 0 auto;
}

.rag-doc-card__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 9px;
  background: rgba(26, 54, 115, 0.08);
  color: #1a3673;
}

.rag-doc-card__title {
  font-size: 1.05rem;
  font-weight: 800;
  color: #1a3673;
}

.rag-block-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  overflow-y: auto;
  overflow-x: hidden;
  flex: 1;
  min-height: 0;
  padding: 20px 24px 60px;
  overscroll-behavior: contain;
}

.rag-sec {
  background: #fff;
  border: 1px solid #eef1f6;
  border-radius: 14px;
  padding: 18px 20px;
  min-width: 0;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
}

.rag-sec__head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}

.rag-sec__typechip {
  cursor: pointer;
  align-self: center;
}

.rag-sec__flow {
  display: flex;
  flex-direction: column;
  gap: 4px;
  align-items: stretch;
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

.rag-blockrow:focus-visible {
  outline: 2px solid rgba(26, 54, 115, 0.35);
  outline-offset: 2px;
}

.rag-blockrow.is-selected {
  background: #eef2ff;
  border-color: #c7d2fe;
  box-shadow: inset 0 0 0 1px rgba(99, 102, 241, 0.08);
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


</style>
