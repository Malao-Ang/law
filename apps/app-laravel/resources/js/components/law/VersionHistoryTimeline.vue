<template>
  <div class="d-flex flex-column ga-3">
    <button
      v-for="v in ordered"
      :key="v.document_id"
      type="button"
      class="ver-card"
      :class="{ 'ver-card--active': v.document_id === viewedDocumentId }"
      @click="open(v)"
    >
      <div class="d-flex align-center justify-space-between ga-2">
        <span class="font-weight-bold">{{ v.version_label }}</span>
        <v-chip
          size="x-small"
          :color="v.is_current ? 'success' : 'warning'"
          variant="tonal"
          rounded="pill"
        >
          {{ v.is_current ? (v.status || 'มีผลบังคับใช้') : 'ถูกแทนที่' }}
        </v-chip>
      </div>
      <div class="text-caption text-medium-emphasis mt-1 d-flex flex-column ga-1">
        <span v-if="v.promulgation_date"><v-icon icon="mdi-calendar" size="12" /> ประกาศ {{ formatLawDate(v.promulgation_date) }}</span>
        <span v-if="v.issuer || v.agency"><v-icon icon="mdi-office-building-outline" size="12" /> {{ v.issuer || v.agency }}</span>
      </div>
      <div v-if="v.change_status" class="text-caption mt-1">{{ v.change_status }}</div>
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import type { VersionChainItem } from '../../types/versionChain';
import { formatThaiDate } from '../../utils/thaiDate';

const props = defineProps<{ versions: VersionChainItem[]; viewedDocumentId: string }>();
const router = useRouter();

// versions arrive oldest -> newest; show newest first (matches the mockup v3/v2/v1).
const ordered = computed(() => [...props.versions].reverse());

function formatLawDate(value: string): string {
  return formatThaiDate(value) || value || '';
}

function open(v: VersionChainItem): void {
  if (v.document_id !== props.viewedDocumentId) {
    router.push(`/law/${encodeURIComponent(v.document_id)}`);
  }
}
</script>

<style scoped>
.ver-card {
  text-align: start;
  border: 1px solid rgba(0, 0, 0, 0.12);
  border-radius: 10px;
  padding: 10px 12px;
  cursor: pointer;
  background: #fff;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.ver-card:hover {
  border-color: rgb(var(--v-theme-admin-primary));
}
.ver-card--active {
  border-color: rgb(var(--v-theme-admin-primary));
  box-shadow: 0 0 0 1px rgb(var(--v-theme-admin-primary));
}
</style>
