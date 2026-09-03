<template>
  <div class="rel-tree">
    <div v-if="node.level > 0" class="rel-tree__edge">{{ relationTypeLabel(node.edgeType) }}</div>

    <div class="rel-tree__same-row">
      <div class="rel-tree__node" :class="{ 'is-root': node.level === 0 }">
        <div class="rel-tree__card" :class="{ 'is-current': isCurrent }">
          <div class="d-flex align-center ga-2 mb-2">
            <v-chip
              v-if="node.row.lawType"
              size="x-small"
              variant="tonal"
              color="grey"
              class="font-weight-bold"
            >{{ node.row.typeShort }}</v-chip>
            <v-spacer />
            <v-chip v-if="node.level === 0" size="x-small" color="admin-primary" variant="flat">
              เอกสารปัจจุบัน
            </v-chip>
          </div>
          <div class="rel-tree__title">{{ node.row.title }}</div>
          <div class="text-caption mt-2" :class="statusClass(node.row.metaStatus || node.row.workflowStage)">
            สถานะ: {{ node.row.metaStatus || node.row.workflowStage || '—' }}
          </div>
        </div>
      </div>

      <button
        v-if="canTogglePeers"
        type="button"
        class="rel-tree__toggle"
        :aria-expanded="expanded"
        @click.stop="expanded = !expanded"
      >
        <v-icon :icon="expanded ? 'mdi-chevron-left' : 'mdi-chevron-right'" size="18" />
        <span>{{ expanded ? 'ย่อ' : `ขั้นเดียวกัน ${peers.length}` }}</span>
      </button>

      <SameLevelInlineChain
        v-if="canTogglePeers && expanded"
        :versions="peers"
        :current-id="currentId ?? node.row.id"
        @select="$emit('select', $event)"
      />
    </div>

    <div v-if="node.children.length" class="rel-tree__children">
      <div class="rel-tree__level-label">
        {{ node.level === 0 ? 'ระดับลำดับรองที่ 1' : `ระดับที่ ${node.level + 1}` }}
      </div>
      <div class="rel-tree__row">
        <RelationTreeView
          v-for="child in node.children"
          :key="child.row.id"
          :node="child"
          :current-id="currentId"
          @select="$emit('select', $event)"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { relationTypeLabel } from '../../types/lawRelation';
import type { RelTreeNode } from '../../composables/useShowRelations';
import SameLevelInlineChain from './SameLevelInlineChain.vue';

defineOptions({ name: 'RelationTreeView' });
const props = defineProps<{
  node: RelTreeNode;
  currentId?: string;
}>();
defineEmits<{ select: [id: string] }>();

const expanded = ref(true);

const isCurrent = computed(() =>
  props.node.level === 0 || props.node.row.id === props.currentId,
);

const isLeaf = computed(() => props.node.children.length === 0);

const peers = computed(() =>
  (props.node.sameLevelVersions ?? []).filter((row) => row.id !== props.node.row.id),
);

const canTogglePeers = computed(() => isLeaf.value && peers.value.length > 0);

function statusClass(status: string): string {
  if (status.includes('ยกเลิก')) return 'text-error';
  if (status.includes('มีผล') || status === 'เผยแพร่' || status === 'ใช้บังคับ' || status === 'บังคับใช้') {
    return 'text-success';
  }
  return 'text-medium-emphasis';
}
</script>

<style scoped>
.rel-tree {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 240px;
}

.rel-tree__same-row {
  display: flex;
  flex-direction: row;
  align-items: center;
  justify-content: center;
  gap: 12px;
  width: max-content;
  max-width: 100%;
}

.rel-tree__node {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 280px;
  flex-shrink: 0;
}

.rel-tree__edge {
  color: #64748b;
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 8px;
  position: relative;
  padding-top: 18px;
}

.rel-tree__edge::before {
  content: '';
  position: absolute;
  top: 0;
  left: 50%;
  height: 14px;
  border-left: 2px solid #cbd5e1;
}

.rel-tree__card {
  width: 100%;
  padding: 14px 16px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.rel-tree__card.is-current {
  border-color: #1e3a8a;
  box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.08);
}

.rel-tree__title {
  font-size: 14px;
  font-weight: 700;
  line-height: 1.45;
  color: #1e293b;
}

.rel-tree__toggle {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  flex-shrink: 0;
  padding: 6px 8px;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  background: #f8fafc;
  color: #475569;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
}

.rel-tree__children {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  margin-top: 8px;
  position: relative;
}

.rel-tree__children::before {
  content: '';
  position: absolute;
  top: 0;
  left: 50%;
  height: 18px;
  border-left: 2px solid #cbd5e1;
}

.rel-tree__level-label {
  margin: 18px 0 12px;
  padding: 2px 10px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #475569;
  font-size: 12px;
  font-weight: 700;
  z-index: 1;
}

.rel-tree__row {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 20px 24px;
  width: 100%;
}
</style>
