<template>
  <AppShell :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'จัดการ RAG บล็อก']" title="จัดการเนื้อหา RAG" full-height
    subtitle="จัดการความสัมพันธ์และบล็อกก่อนเผยแพร่">
    <template #actions>
      <v-btn variant="outlined" @click="router.push(`/documents/${props.documentId}/review`)">
        ย้อนกลับ
      </v-btn>
      <v-btn color="admin-primary" append-icon="mdi-arrow-right" :disabled="blockBusy"
        @click="router.push(`/documents/${props.documentId}/law-info`)">
        ถัดไป
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

        <!-- Section list (e-Law style) -->
        <div ref="blockListEl" class="rag-block-list">
          <div v-for="section in sections" :key="section.id" class="rag-sec">
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
                    :active="section.headBlock.meta.chunk_type === ct"
                    @click="setChunkType(section.headBlock, ct)"
                  />
                </v-list>
              </v-menu>
              <div class="rag-sec__actions">
                <v-btn size="x-small" variant="outlined" prepend-icon="mdi-link-variant"
                  @click="openRelationDialog('section', section.id, 'related')">เพิ่มความสัมพันธ์</v-btn>
                <v-btn size="x-small" variant="outlined" color="error" prepend-icon="mdi-cancel"
                  @click="openRelationDialog('section', section.id, 'repeals')">ยกเลิกมาตรา</v-btn>
              </div>
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
                <button class="rag-blockrow__split" :disabled="blockBusy" title="แบ่งบล็อก"
                  @click.prevent.stop="splitBlock(section.headBlock)">
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
                <button class="rag-blockrow__split" :disabled="blockBusy" title="แบ่งบล็อก"
                  @click.prevent.stop="splitBlock(child)">
                  <span class="mdi mdi-call-split" />
                </button>
              </div>
            </div>


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

      </div>
    </template>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useComposeStore } from '../../stores/composeStore';
import { useBlockStore } from '../../stores/blockStore';
import { useDocumentStore } from '../../stores/documentStore';
import { useSnackbarStore } from '../../stores/snackbarStore';
import type { DocumentBlock, LawRelation, RelationScope, RelationType } from '../../types/document';
import AppShell from '../shared/AppShell.vue';
import { buildSections, relationsForSection, suggestChunkType, type LawSection } from '../../composables/useLawSections';
import AddRelationDialog from '../shared/AddRelationDialog.vue';
import BlockFlow from '../shared/BlockFlow.vue';
import { HEAD_CHUNK_TYPES, CHUNK_TYPE_LABELS, CHUNK_TYPE_COLORS } from '../../types/chunkType';
import type { ChunkType } from '../../types/chunkType';
import Swal from 'sweetalert2';

const props = defineProps<{ documentId: string }>();

const router = useRouter();
const composeStore = useComposeStore();
const blockStore = useBlockStore();
const documentStore = useDocumentStore();
const snackbar = useSnackbarStore();

const sections = computed(() => buildSections(composeStore.review));

function containerType(section: LawSection): ChunkType | null {
  const stored = section.headBlock.meta.chunk_type as ChunkType | null | undefined;
  return stored ?? suggestChunkType(section.headBlock);
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
const relations = computed<LawRelation[]>(() => documentStore.review?.relations ?? []);
const selectedBlockIds = ref<Set<string>>(new Set());
const blockBusy = ref(false);
const blockListEl = ref<HTMLElement | null>(null);
const dialog = ref<{ scope: RelationScope; blockId: string | null; type: RelationType } | null>(null);

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
  return suggestChunkType(block) ?? 'SECTION';
}

async function persistChunkType(block: DocumentBlock, chunkType: ChunkType): Promise<void> {
  const pageNo = blockPage.value.get(block.block_id) ?? 1;
  await blockStore.patchChunkType(props.documentId, block, pageNo, chunkType);
}

async function mergeSelected(): Promise<void> {
  const selected = allBlocks.value.filter((b) => selectedBlockIds.value.has(b.block_id));
  if (selected.length < 2 || blockBusy.value) return;
  blockBusy.value = true;
  try {
    // First selected becomes the container head; the rest become its children.
    const [head, ...rest] = selected;
    const headType = headTypeFor(head);
    head.meta.chunk_type = headType;                 // optimistic
    rest.forEach((b) => { b.meta.chunk_type = 'PARAGRAPH'; });
    await Promise.all([
      persistChunkType(head, headType),
      ...rest.map((b) => persistChunkType(b, 'PARAGRAPH')),
    ]);
    clearSelection();
    snackbar.success('จัดกลุ่มเป็นคอนเทนเนอร์เดียวแล้ว');
  } catch (e) {
    snackbar.error(e instanceof Error ? e.message : 'จัดกลุ่มไม่สำเร็จ');
    await reloadBlocks();
  } finally {
    blockBusy.value = false;
  }
}

async function splitBlock(block: DocumentBlock): Promise<void> {
  if (blockBusy.value) return;
  // Already a section head — nothing to promote.
  if (block.meta.chunk_type && (HEAD_CHUNK_TYPES as readonly string[]).includes(block.meta.chunk_type)) {
    snackbar.success('บล็อกนี้เป็น section head อยู่แล้ว');
    return;
  }
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

async function handleExport(): Promise<void> {
  if (blockBusy.value) return;

  // 1. Persist any accepted auto-suggestions (stored empty but suggestion exists).
  const toPersist = sections.value.filter(
    (s) => !s.headBlock.meta.chunk_type && suggestChunkType(s.headBlock),
  );
  if (toPersist.length > 0) {
    blockBusy.value = true;
    try {
      await Promise.all(
        toPersist.map((s) => {
          const pageNo = blockPage.value.get(s.headBlock.block_id) ?? 1;
          return blockStore.patchChunkType(props.documentId, s.headBlock, pageNo, suggestChunkType(s.headBlock));
        }),
      );
      await reloadBlocks();
    } catch (e) {
      documentStore.setSaveError(e instanceof Error ? e.message : 'บันทึกประเภทไม่สำเร็จ');
      return;
    } finally {
      blockBusy.value = false;
    }
  }

  // 2. Validate: every container must now have a stored type.
  const missing = sections.value.filter((s) => !s.headBlock.meta.chunk_type);
  if (missing.length > 0) {
    await Swal.fire({
      icon: 'warning',
      title: 'ยังกำหนดประเภทไม่ครบ',
      html:
        'กรุณาเลือกประเภทให้ container ต่อไปนี้ก่อนเผยแพร่:<br><br>' +
        missing.map((s) => `• ${escapeForHtml(s.badge)}`).join('<br>'),
      confirmButtonText: 'ตกลง',
      confirmButtonColor: '#1a3673',
    });
    return;
  }

  // 3. All typed — publish and go to the law view.
  await composeStore.triggerExport(props.documentId);
  if (!composeStore.error) {
    router.push(`/law/${props.documentId}`);
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
/* ponytail: keep page scroll locked; only rag-block-list should scroll */
.rag-content-area {
  display: flex;
  flex-direction: column;
  flex: 1 1 0;
  max-height: 100%;
  min-height: 0;
  gap: 12px;
  overflow: hidden;
}

.rag-selection-bar {
  background: #1a3673;
  color: #fff;
  flex: 0 0 auto;
  min-height: 44px;
  opacity: 0;
  pointer-events: none;
  position: sticky;
  top: 0;
  visibility: hidden;
  z-index: 5;
}

.rag-selection-bar.is-visible {
  opacity: 1;
  pointer-events: auto;
  visibility: visible;
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
