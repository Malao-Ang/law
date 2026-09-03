<template>
  <div v-if="versions.length" class="slc" @click.stop>
    <template v-for="(item, index) in items" :key="item.row.id">
      <button
        type="button"
        class="slc__card"
        :class="{
          'is-current': item.row.id === currentId,
          'is-big': item.size === 'big',
          'is-small': item.size === 'small',
        }"
        @click="$emit('select', item.row.id)"
      >
        <div class="slc__meta">
          <v-chip
            v-if="item.kindLabel"
            size="x-small"
            variant="tonal"
            color="grey"
            class="font-weight-bold"
          >{{ item.kindLabel }}</v-chip>
          <v-chip
            v-if="item.versionLabel"
            size="x-small"
            color="admin-primary"
            variant="tonal"
          >{{ item.versionLabel }}</v-chip>
        </div>
        <div class="slc__title">{{ item.row.title }}</div>
        <div class="slc__status" :class="`is-${item.kind}`">สถานะ: {{ item.statusLabel }}</div>
      </button>
      <span v-if="index < items.length - 1" class="slc__arrow" aria-hidden="true">
        <span class="slc__rel">{{ peerEdgeLabel(items[index + 1].row) }}</span>
        →
      </span>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { relationTypeLabel } from '../../types/lawRelation';
import {
  editionKindLabel,
  isWholeEditionChange,
  sameLevelVersionLabel,
  versionNodeSize,
  versionStatusKind,
  type ShowRelRow,
} from '../../composables/useShowRelations';

const props = defineProps<{
  versions: ShowRelRow[];
  chain?: ShowRelRow[];
  currentId?: string;
}>();

defineEmits<{ select: [id: string] }>();

function peerEdgeLabel(row: ShowRelRow): string {
  if (isWholeEditionChange(row.changeStatus)) return relationTypeLabel('supersedes');
  return relationTypeLabel('amends');
}

const items = computed(() => {
  const chain = props.chain?.length ? props.chain : props.versions;
  return props.versions.map((row) => {
    const kind = versionStatusKind(row.metaStatus);
    return {
      row,
      kind,
      size: versionNodeSize(row.changeStatus),
      kindLabel: editionKindLabel(row.typeShort || row.lawType),
      versionLabel: sameLevelVersionLabel(chain, row.id),
      statusLabel: row.metaStatus || (kind === 'revoked' ? 'ยกเลิก' : kind === 'active' ? 'มีผลบังคับใช้' : '—'),
    };
  });
});
</script>

<style scoped>
.slc {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 8px;
  min-width: max-content;
}

.slc__card {
  width: 240px;
  padding: 12px 14px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  background: #fff;
  text-align: left;
  cursor: pointer;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.slc__card.is-small {
  width: 220px;
  border-style: dashed;
}

.slc__card.is-current {
  border-color: #1e3a8a;
  box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.08);
}

.slc__meta {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
}

.slc__title {
  font-size: 13px;
  font-weight: 700;
  line-height: 1.45;
  color: #1e293b;
}

.slc__status {
  margin-top: 8px;
  font-size: 12px;
  font-weight: 600;
}

.slc__status.is-revoked { color: #dc2626; }
.slc__status.is-active { color: #16a34a; }
.slc__status.is-other { color: #64748b; }

.slc__arrow {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  color: #94a3b8;
  font-size: 16px;
  font-weight: 700;
}

.slc__rel {
  color: #0f766e;
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
}
</style>
