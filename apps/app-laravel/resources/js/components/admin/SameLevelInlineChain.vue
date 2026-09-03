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
          <span class="slc__kind">{{ item.kindLabel }}</span>
          <span class="slc__status" :class="`is-${item.kind}`">{{ item.statusLabel }}</span>
        </div>
        <div class="slc__title">{{ item.row.title }}</div>
      </button>
      <span v-if="index < items.length - 1" class="slc__arrow" aria-hidden="true">→</span>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import {
  editionKindLabel,
  versionNodeSize,
  versionStatusKind,
  type ShowRelRow,
} from '../../composables/useShowRelations';

const props = defineProps<{
  versions: ShowRelRow[];
  currentId?: string;
}>();

defineEmits<{ select: [id: string] }>();

const items = computed(() =>
  props.versions.map((row) => {
    const kind = versionStatusKind(row.metaStatus);
    return {
      row,
      kind,
      size: versionNodeSize(row.changeStatus),
      kindLabel: editionKindLabel(row.changeStatus),
      statusLabel: kind === 'revoked' ? 'ยกเลิก' : kind === 'active' ? 'บังคับใช้' : (row.metaStatus || '—'),
    };
  }),
);
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
  width: 220px;
  padding: 10px 12px;
  border: 1px solid #94a3b8;
  border-radius: 12px;
  background: #fff;
  text-align: left;
  cursor: pointer;
}

.slc__card.is-small {
  width: 180px;
  padding: 8px 10px;
  border-style: dashed;
}

.slc__card.is-current {
  border-color: #1e3a8a;
  box-shadow: 0 0 0 2px rgba(30, 58, 138, 0.14);
}

.slc__meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;
}

.slc__kind {
  font-size: 11px;
  font-weight: 700;
  color: #64748b;
}

.slc__status {
  font-size: 11px;
  font-weight: 700;
}

.slc__status.is-revoked { color: #dc2626; }
.slc__status.is-active { color: #16a34a; }
.slc__status.is-other { color: #64748b; }

.slc__title {
  font-size: 13px;
  font-weight: 700;
  line-height: 1.4;
  color: #1e293b;
}

.slc__arrow {
  color: #94a3b8;
  font-size: 16px;
  font-weight: 700;
}
</style>
