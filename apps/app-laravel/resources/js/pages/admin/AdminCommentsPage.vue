<template>
  <AppShell
    :breadcrumbs="['ข้อคิดเห็นและการสอบถาม', 'จัดการความคิดเห็น']"
    title="จัดการความคิดเห็น"
    subtitle="จัดการข้อซักถามและข้อเสนอแนะเกี่ยวกับเอกสารกฎหมายจากผู้ใช้งาน"
  >
    <v-row class="mb-4">
      <v-col v-for="card in statCards" :key="card.key" cols="12" sm="6" md="3" class="d-flex">
        <AdminStatCard
          :icon="card.icon"
          :icon-color="card.iconColor"
          :icon-bg="card.iconBg"
          :number="card.number"
          :label="card.label"
        />
      </v-col>
    </v-row>

    <v-card flat border rounded="lg">
      <div class="pa-4 d-flex flex-wrap align-center ga-3">
        <v-tabs v-model="statusTab" color="admin-primary" density="comfortable" class="comment-tabs flex-grow-1">
          <v-tab value="all" class="text-none">ทั้งหมด ({{ counts.all }})</v-tab>
          <v-tab value="pending" class="text-none">รอพิจารณา ({{ counts.pending }})</v-tab>
          <v-tab value="answered" class="text-none">ตอบแล้ว ({{ counts.answered }})</v-tab>
          <v-tab value="archived" class="text-none">จัดเก็บเข้าคลัง ({{ counts.archived }})</v-tab>
        </v-tabs>
      </div>
      <v-divider />
      <div class="pa-4 d-flex flex-wrap align-center ga-3 comment-filters">
        <v-text-field
          v-model="filters.q"
          label="ค้นหา"
          placeholder="รหัสหรือชื่อเอกสาร"
          prepend-inner-icon="mdi-magnify"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          class="comment-filters__search"
          @keyup.enter="search"
        />
        <v-select
          v-model="filters.type"
          :items="typeOptions"
          item-title="title"
          item-value="value"
          label="ประเภทความคิดเห็น"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          class="comment-filters__select"
        />
        <v-select
          v-model="filters.unit"
          :items="unitOptions"
          item-title="title"
          item-value="value"
          label="หน่วยงาน"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          class="comment-filters__select"
        />
        <v-text-field
          v-model="filters.date"
          label="วันที่"
          type="date"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          class="comment-filters__date"
        />
        <v-btn variant="text" color="grey" prepend-icon="mdi-close" class="text-none" @click="clearFilters">ล้างตัวกรอง</v-btn>
        <v-btn color="admin-primary" class="text-none" @click="search">ค้นหา</v-btn>
      </div>
      <v-divider />
      <div style="height: 3px"><v-progress-linear v-if="loading" indeterminate color="admin-primary" height="3" /></div>
      <v-alert v-if="error" type="error" variant="tonal" class="ma-4" density="compact">{{ error }}</v-alert>
      <v-table density="comfortable" class="comment-table">
        <thead>
          <tr>
            <th>รหัส</th>
            <th>ผู้แสดงความคิดเห็น</th>
            <th>ประเภท / ความคิดเห็น</th>
            <th>เอกสารกฎหมาย</th>
            <th>หน่วยงาน</th>
            <th>สถานะ</th>
            <th>การเผยแพร่</th>
            <th class="text-center">ดำเนินการ</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && items.length === 0">
            <td colspan="8" class="text-center text-medium-emphasis py-8">ไม่พบความคิดเห็น</td>
          </tr>
          <tr v-for="item in items" :key="item.id">
            <td class="text-center font-weight-medium text-no-wrap">{{ tableValue(item.code) }}</td>
            <td>
              <div class="font-weight-medium">{{ tableValue(item.author_name) }}</div>
              <div class="text-caption text-medium-emphasis">{{ formatThaiDateShort(item.created_at) }}</div>
            </td>
            <td>
              <v-chip size="small" variant="tonal" color="admin-primary" class="mb-1">{{ item.type_label }}</v-chip>
              <div class="text-body-2 comment-preview">{{ tableValue(item.body_preview || item.body) }}</div>
              <div v-if="item.attachment_count" class="text-caption text-medium-emphasis d-flex align-center ga-1 mt-1">
                <v-icon icon="mdi-paperclip" size="14" />
                {{ item.attachment_count }} ไฟล์
              </div>
            </td>
            <td>
              <div class="font-weight-medium">{{ tableValue(item.document_title) }}</div>
              <div v-if="item.section_ref" class="text-caption text-medium-emphasis">{{ item.section_ref }}</div>
            </td>
            <td>{{ tableValue(item.unit_name || item.agency) }}</td>
            <td class="text-center">
              <v-chip size="small" :color="statusColor(item.status)" variant="tonal">{{ item.status_label }}</v-chip>
            </td>
            <td class="text-center">
              <v-chip
                size="small"
                :color="item.visibility === 'public' ? 'success' : 'grey'"
                :variant="item.visibility === 'public' ? 'tonal' : 'outlined'"
              >
                {{ item.visibility === 'public' ? 'Public' : 'Private' }}
              </v-chip>
            </td>
            <td class="text-center">
              <v-btn
                size="small"
                variant="tonal"
                color="admin-primary"
                class="text-none"
                prepend-icon="mdi-file-document-outline"
                :to="`/admin/comments/${encodeURIComponent(item.id)}`"
              >
                เปิดรายการ
              </v-btn>
            </td>
          </tr>
        </tbody>
      </v-table>
      <div class="pa-4 d-flex align-center justify-space-between flex-wrap ga-2">
        <span class="text-body-2 text-medium-emphasis">
          แสดง {{ rangeFrom }} ถึง {{ rangeTo }} จาก {{ total }} รายการ
        </span>
        <v-pagination v-model="page" :length="lastPage" :total-visible="7" density="comfortable" @update:model-value="load" />
      </div>
    </v-card>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { fetchAdminComments } from '../../api/lawComments';
import AdminStatCard from '../../components/admin/AdminStatCard.vue';
import AppShell from '../../components/shared/AppShell.vue';
import type { AdminLawComment, LawCommentStatus, LawCommentType } from '../../types/lawComment';
import { COMMENT_STATUS_META, COMMENT_TYPES } from '../../types/lawComment';
import { formatThaiDateShort } from '../../utils/thaiDate';
import { tableValue } from '../../utils/tableValue';

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const error = ref('');
const items = ref<AdminLawComment[]>([]);
const units = ref<Array<{ code: string; name: string }>>([]);
const counts = reactive({ all: 0, pending: 0, answered: 0, archived: 0 });
const total = ref(0);
const lastPage = ref(1);
const page = ref(1);
const statusTab = ref<'all' | LawCommentStatus>('all');
const filters = reactive({
  q: '',
  type: '' as LawCommentType | '',
  unit: '',
  date: '',
});

const typeOptions = [{ title: 'ทุกประเภท', value: '' }, ...COMMENT_TYPES.map((item) => ({ title: item.label, value: item.value }))];
const unitOptions = computed(() => [
  { title: 'ทุกหน่วยงาน', value: '' },
  ...units.value.map((unit) => ({ title: unit.name, value: unit.code })),
]);

const statCards = computed(() => [
  { key: 'all', label: 'ความคิดเห็นทั้งหมด', number: counts.all, icon: 'mdi-comment-multiple-outline', iconColor: '#1e3a8a', iconBg: '#dbeafe' },
  { key: 'pending', label: 'รอพิจารณา', number: counts.pending, icon: 'mdi-clock-outline', iconColor: '#b45309', iconBg: '#fef3c7' },
  { key: 'answered', label: 'ตอบแล้ว', number: counts.answered, icon: 'mdi-check-circle-outline', iconColor: '#047857', iconBg: '#d1fae5' },
  { key: 'archived', label: 'จัดเก็บเข้าคลัง', number: counts.archived, icon: 'mdi-archive-outline', iconColor: '#4b5563', iconBg: '#e5e7eb' },
]);

const rangeFrom = computed(() => (total.value === 0 ? 0 : (page.value - 1) * 20 + 1));
const rangeTo = computed(() => Math.min(page.value * 20, total.value));

function statusColor(status: string): string {
  return COMMENT_STATUS_META[status as LawCommentStatus]?.color ?? 'grey';
}

function readQuery(): void {
  const status = String(route.query.status ?? 'all');
  statusTab.value = (['all', 'pending', 'answered', 'archived'].includes(status) ? status : 'all') as 'all' | LawCommentStatus;
  filters.q = String(route.query.q ?? '');
  filters.type = (String(route.query.type ?? '') || '') as LawCommentType | '';
  filters.unit = String(route.query.unit ?? '');
  filters.date = String(route.query.date ?? '');
  page.value = Math.max(1, Number(route.query.page) || 1);
}

function syncQuery(): void {
  router.replace({
    query: {
      status: statusTab.value === 'all' ? undefined : statusTab.value,
      q: filters.q || undefined,
      type: filters.type || undefined,
      unit: filters.unit || undefined,
      date: filters.date || undefined,
      page: page.value > 1 ? String(page.value) : undefined,
    },
  });
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const result = await fetchAdminComments({
      status: statusTab.value,
      type: filters.type,
      unit: filters.unit,
      date: filters.date,
      q: filters.q,
      page: page.value,
      per_page: 20,
    });
    items.value = result.items;
    total.value = result.total;
    lastPage.value = result.last_page;
    page.value = result.page;
    Object.assign(counts, result.counts);
    units.value = result.units;
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'โหลดรายการไม่สำเร็จ';
    items.value = [];
  } finally {
    loading.value = false;
  }
}

function search(): void {
  page.value = 1;
  syncQuery();
  void load();
}

function clearFilters(): void {
  filters.q = '';
  filters.type = '';
  filters.unit = '';
  filters.date = '';
  statusTab.value = 'all';
  page.value = 1;
  syncQuery();
  void load();
}

watch(statusTab, () => {
  page.value = 1;
  syncQuery();
  void load();
});

onMounted(() => {
  readQuery();
  void load();
});
</script>

<style scoped>
.comment-filters {
  gap: 12px;
}
.comment-filters__search {
  min-width: 220px;
  flex: 1 1 220px;
}
.comment-filters__select,
.comment-filters__date {
  min-width: 180px;
  flex: 0 1 200px;
}
.comment-preview {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  max-width: 280px;
}
.comment-table :deep(tbody td) {
  padding-block: 14px;
  vertical-align: top;
}
</style>
