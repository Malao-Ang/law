<template>
  <div class="h-node" :class="{ 'is-root': node.level === 0 }">
    <div v-if="node.level > 0" class="h-edge">{{ relationTypeLabel(node.edgeType) }}</div>
    <div class="h-row">
      <v-icon :icon="rowIcon" size="20" class="h-icon" :color="node.level === 0 ? 'admin-primary' : undefined" />
      <span class="h-title">{{ node.row.title }}</span>
      <v-chip v-if="node.level === 0" size="x-small" color="admin-primary" variant="tonal" class="h-chip">
        Root
      </v-chip>
      <v-chip
        v-else-if="node.row.lawType"
        size="x-small"
        variant="flat"
        :color="typeColor(node.row.lawType)"
        class="h-chip font-weight-bold text-white"
      >
        {{ node.row.typeShort }}
      </v-chip>
      <v-chip
        size="x-small"
        :color="statusChipColor"
        variant="tonal"
        class="h-chip"
      >
        {{ statusLabel }}
      </v-chip>
      <v-chip
        v-if="versionLabel"
        size="x-small"
        color="admin-primary"
        variant="tonal"
        class="h-chip"
      >
        {{ versionLabel }}
      </v-chip>
      <button
        v-if="canTogglePeers"
        type="button"
        class="h-toggle"
        :aria-expanded="expanded"
        @click.stop="expanded = !expanded"
      >
        <v-icon :icon="expanded ? 'mdi-chevron-up' : 'mdi-chevron-down'" size="16" />
        {{ expanded ? 'ย่อประวัติการปรับปรุง' : 'แก้ไขการปรับปรุง' }}
      </button>
    </div>
    <div v-if="canTogglePeers && expanded" class="h-peers">
      <SameLevelInlineChain
        :versions="peers"
        :chain="node.sameLevelVersions"
        :current-id="node.row.id"
      />
    </div>
    <ul v-if="node.children.length" class="h-list">
      <li v-for="child in node.children" :key="child.row.id" class="h-item">
        <HierarchyList :node="child" />
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { relationTypeLabel } from '../../types/lawRelation';
import {
  metaStatusColor,
  sameLevelVersionLabel,
  typeColor,
  typeIcon,
  workflowStageColor,
  type RelTreeNode,
} from '../../composables/useShowRelations';
import SameLevelInlineChain from './SameLevelInlineChain.vue';

defineOptions({ name: 'HierarchyList' });

const props = defineProps<{ node: RelTreeNode }>();

const expanded = ref(true);
const isLeaf = computed(() => props.node.children.length === 0);
const peers = computed(() =>
  (props.node.sameLevelVersions ?? []).filter((row) => row.id !== props.node.row.id),
);
const canTogglePeers = computed(() => isLeaf.value && peers.value.length > 0);
const versionLabel = computed(() =>
  sameLevelVersionLabel(props.node.sameLevelVersions ?? [], props.node.row.id),
);

const rowIcon = computed(() =>
  props.node.level === 0 ? 'mdi-office-building' : typeIcon(props.node.row.lawType),
);

const statusLabel = computed(() =>
  props.node.row.metaStatus || props.node.row.workflowStage || '—',
);

const statusChipColor = computed(() =>
  props.node.row.metaStatus
    ? metaStatusColor(props.node.row.metaStatus)
    : workflowStageColor(props.node.row.workflowStage),
);
</script>

<style scoped>
.h-node {
  min-width: 0;
}

.h-edge {
  color: #2563eb;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.2;
  margin: 0 0 4px 28px;
}

.h-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  min-height: 32px;
}

.h-icon {
  flex-shrink: 0;
  color: #64748b;
}

.h-title {
  color: #0f172a;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.4;
}

.h-chip {
  flex-shrink: 0;
}

.h-toggle {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  background: #f8fafc;
  color: #475569;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  cursor: pointer;
}

.h-peers {
  display: flex;
  overflow-x: auto;
  padding: 8px 0 4px 28px;
}

.h-list {
  list-style: none;
  margin: 0 0 0 10px;
  padding: 0 0 0 18px;
  position: relative;
}

.h-list::before {
  background: #cbd5e1;
  bottom: 18px;
  content: '';
  left: 0;
  position: absolute;
  top: 0;
  width: 1px;
}

.h-item {
  padding: 10px 0 2px;
  position: relative;
}

.h-item::before {
  background: #cbd5e1;
  content: '';
  height: 1px;
  left: -18px;
  position: absolute;
  top: 22px;
  width: 18px;
}

.h-item:last-child::after {
  background: #fff;
  bottom: 0;
  content: '';
  left: -19px;
  position: absolute;
  top: 23px;
  width: 3px;
}
</style>
