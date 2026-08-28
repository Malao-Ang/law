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

    <v-dialog :model-value="pending !== null" max-width="460" @update:model-value="cancel">
      <v-card v-if="pending" rounded="xl">
        <div class="d-flex align-center ga-2 px-5 pt-4 pb-2">
          <v-avatar color="admin-primary" size="36" rounded="lg">
            <v-icon icon="mdi-history" color="white" size="18" />
          </v-avatar>
          <div class="text-subtitle-1 font-weight-bold">ไปยังเอกสารเวอร์ชันนี้?</div>
        </div>
        <v-card-text class="px-5 pt-2">
          <div class="text-body-2">
            <strong>{{ pending.version_label }}</strong> — {{ pending.title || pending.document_id }}
          </div>
          <div class="text-caption text-medium-emphasis mt-1">
            ระบบจะเปิดหน้าเอกสารของเวอร์ชันที่เลือก
          </div>
        </v-card-text>
        <v-card-actions class="px-5 pb-5">
          <v-spacer />
          <v-btn variant="outlined" class="text-none" @click="cancel">ยกเลิก</v-btn>
          <v-btn color="admin-primary" class="text-none" @click="confirmNavigate">ไปยังเวอร์ชันนี้</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { VersionChainItem } from '../../types/versionChain';
import { formatThaiDate } from '../../utils/thaiDate';

const props = defineProps<{ versions: VersionChainItem[]; viewedDocumentId: string }>();
const router = useRouter();

// versions arrive oldest -> newest; show newest first (matches the mockup v3/v2/v1).
const ordered = computed(() => [...props.versions].reverse());

const pending = ref<VersionChainItem | null>(null);

function formatLawDate(value: string): string {
  return formatThaiDate(value) || value || '';
}

function open(v: VersionChainItem): void {
  if (v.document_id === props.viewedDocumentId) return;
  pending.value = v;
}

function confirmNavigate(): void {
  const target = pending.value;
  pending.value = null;
  if (target) {
    router.push(`/law/${encodeURIComponent(target.document_id)}`);
  }
}

function cancel(): void {
  pending.value = null;
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
