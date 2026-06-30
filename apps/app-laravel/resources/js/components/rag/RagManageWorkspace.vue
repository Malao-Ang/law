<template>
  <LawspaceShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'จัดการ RAG บล็อก']"
    title="จัดการเนื้อหา RAG"
    subtitle="เลือกบล็อกเพื่อรวมหรือลบก่อนเผยแพร่"
  >
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
      <!-- Action bar -->
      <div v-if="selectedBlockIds.size > 0" class="rag-action-bar">
        <span class="rag-action-bar__count">{{ selectedBlockIds.size }} รายการที่เลือก</span>
        <v-btn
          v-if="selectedBlockIds.size >= 2"
          size="small"
          color="primary"
          :loading="blockOpBusy"
          @click="handleMerge"
        >
          รวม RAG
        </v-btn>
        <v-btn
          size="small"
          color="error"
          variant="tonal"
          :loading="blockOpBusy"
          @click="handleRemove"
        >
          ลบ
        </v-btn>
        <v-btn size="small" variant="text" @click="selectedBlockIds = new Set()">
          ยกเลิกการเลือก
        </v-btn>
      </div>

      <!-- Block cards -->
      <div class="rag-block-list">
        <div
          v-for="item in flatBlocks"
          :key="item.block.block_id"
          class="rag-block-card"
          :class="{ 'rag-block-card--selected': selectedBlockIds.has(item.block.block_id) }"
        >
          <v-checkbox
            :model-value="selectedBlockIds.has(item.block.block_id)"
            density="compact"
            hide-details
            @update:model-value="toggleSelect(item.block.block_id)"
          />
          <div class="rag-block-card__body">
            <div class="rag-block-card__header">
              <span
                class="rag-block-badge"
                :style="{ background: typeColor(item.block.type) }"
              >
                {{ item.block.type }}
              </span>
              <span class="rag-block-card__page">หน้า {{ item.pageNo }}</span>
            </div>
            <p class="rag-block-card__text">
              {{ item.block.approved_text || item.block.normalized_text }}
            </p>
          </div>
        </div>

        <div v-if="flatBlocks.length === 0" class="rag-state">
          <p>ไม่พบบล็อกเนื้อหา</p>
        </div>
      </div>
    </template>
  </LawspaceShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useComposeStore } from '../../stores/composeStore';
import { useBlockStore } from '../../stores/blockStore';
import LawspaceShell from '../shared/LawspaceShell.vue';

const props = defineProps<{ documentId: string }>();

const router = useRouter();
const composeStore = useComposeStore();
const blockStore = useBlockStore();

const selectedBlockIds = ref(new Set<string>());
const blockOpBusy = ref(false);

const TYPE_COLORS: Record<string, string> = {
  title: '#1d4ed8',
  section_header: '#2563eb',
  paragraph: '#6b7280',
  list_item: '#0891b2',
  table: '#7c3aed',
  image: '#059669',
  figure_caption: '#64748b',
  footnote: '#78716c',
  unknown: '#9ca3af',
};

const flatBlocks = computed(() =>
  composeStore.review?.pages.flatMap(p =>
    p.blocks.map(b => ({ pageNo: p.page_no, block: b })),
  ) ?? [],
);

function typeColor(type: string): string {
  return TYPE_COLORS[type] ?? '#9ca3af';
}

function toggleSelect(blockId: string): void {
  const next = new Set(selectedBlockIds.value);
  if (next.has(blockId)) next.delete(blockId);
  else next.add(blockId);
  selectedBlockIds.value = next;
}

async function handleMerge(): Promise<void> {
  if (selectedBlockIds.value.size < 2 || blockOpBusy.value) return;
  blockOpBusy.value = true;
  try {
    const ordered = flatBlocks.value
      .filter(i => selectedBlockIds.value.has(i.block.block_id))
      .map(i => i.block.block_id);
    await blockStore.merge(props.documentId, ordered);
    selectedBlockIds.value = new Set();
    await composeStore.fetch(props.documentId);
  } catch (err) {
    composeStore.setError(err instanceof Error ? err.message : 'รวมบล็อกไม่สำเร็จ');
  } finally {
    blockOpBusy.value = false;
  }
}

async function handleRemove(): Promise<void> {
  if (selectedBlockIds.value.size === 0 || blockOpBusy.value) return;
  blockOpBusy.value = true;
  try {
    for (const item of flatBlocks.value) {
      if (selectedBlockIds.value.has(item.block.block_id)) {
        await blockStore.remove(props.documentId, item.block.block_id, item.pageNo);
      }
    }
    selectedBlockIds.value = new Set();
    await composeStore.fetch(props.documentId);
  } catch (err) {
    composeStore.setError(err instanceof Error ? err.message : 'ลบบล็อกไม่สำเร็จ');
  } finally {
    blockOpBusy.value = false;
  }
}

async function handleExport(): Promise<void> {
  await composeStore.triggerExport(props.documentId);
  if (!composeStore.error) {
    router.push(`/documents/${props.documentId}/preview`);
  }
}

onMounted(() => composeStore.fetch(props.documentId));
onBeforeUnmount(() => composeStore.reset());
</script>

<style scoped>
.rag-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px;
  gap: 12px;
  color: #64748b;
}

.rag-action-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  margin-bottom: 12px;
}

.rag-action-bar__count {
  font-size: 13px;
  font-weight: 600;
  color: #1d4ed8;
  flex: 1;
}

.rag-block-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.rag-block-card {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 16px;
  background: white;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  transition: border-color 0.15s, background 0.15s;
}

.rag-block-card--selected {
  border-color: #6366f1;
  background: #f5f3ff;
}

.rag-block-card__body {
  flex: 1;
  min-width: 0;
}

.rag-block-card__header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.rag-block-badge {
  font-size: 10px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 99px;
  color: white;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.rag-block-card__page {
  font-size: 11px;
  color: #94a3b8;
}

.rag-block-card__text {
  font-size: 13px;
  color: #334155;
  line-height: 1.6;
  margin: 0;
  white-space: pre-wrap;
  font-family: 'Sarabun', sans-serif;
}
</style>
