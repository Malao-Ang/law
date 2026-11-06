<template>
  <div class="comments-page">
    <ELawNavbar @go-admin="router.push('/admin')" />
    <div class="comments-page__crumb">
      <v-container style="max-width: 1180px" class="py-0">
        <v-breadcrumbs density="compact" class="pa-0">
          <v-breadcrumbs-item to="/">หน้าแรก</v-breadcrumbs-item>
          <v-breadcrumbs-divider>/</v-breadcrumbs-divider>
          <v-breadcrumbs-item to="/database">ฐานข้อมูลกฎหมาย</v-breadcrumbs-item>
          <v-breadcrumbs-divider>/</v-breadcrumbs-divider>
          <v-breadcrumbs-item :to="`/law/${encodeURIComponent(documentId)}`">{{ title }}</v-breadcrumbs-item>
        </v-breadcrumbs>
      </v-container>
    </div>

    <v-main class="comments-page__main">
      <v-container style="max-width: 1180px" class="py-6">
        <v-btn variant="outlined" size="small" prepend-icon="mdi-arrow-left" class="mb-4" :to="`/law/${encodeURIComponent(documentId)}`">
          ย้อนกลับ
        </v-btn>

        <v-card class="comments-hero mb-4" rounded="xl" flat border>
          <div>
            <h1>ความคิดเห็นและข้อซักถามทั้งหมด</h1>
            <p>สอบถามหรือเสนอข้อคิดเห็นเกี่ยวกับ {{ title }} เพื่อให้เจ้าหน้าที่ผู้รับผิดชอบพิจารณาและตอบกลับ</p>
          </div>
          <v-btn color="primary" size="large" prepend-icon="mdi-plus" class="text-none" @click="composer?.open()">
            แสดงความคิดเห็น
          </v-btn>
        </v-card>

        <div class="comments-layout">
          <aside class="comments-filters">
            <v-card rounded="xl" flat border>
              <v-card-title class="text-subtitle-1">ตัวกรอง</v-card-title>
              <v-card-text class="pt-0">
                <p class="filter-label">ประเภทความคิดเห็น</p>
                <button type="button" class="filter-row" :class="{ 'is-active': type === '' }" @click="setType('')">
                  <span>ทั้งหมด</span>
                </button>
                <button
                  v-for="option in COMMENT_TYPES"
                  :key="option.value"
                  type="button"
                  class="filter-row"
                  :class="{ 'is-active': type === option.value }"
                  @click="setType(option.value)"
                >
                  <span>{{ option.label }}</span>
                  <span>{{ counts?.types[option.value] ?? 0 }}</span>
                </button>

                <p class="filter-label mt-4">สถานะการตอบกลับ</p>
                <button type="button" class="filter-row" :class="{ 'is-active': status === '' }" @click="setStatus('')">
                  <span>ทั้งหมด</span>
                </button>
                <button type="button" class="filter-row" :class="{ 'is-active': status === 'answered' }" @click="setStatus('answered')">
                  <span>ตอบแล้ว</span><span>{{ counts?.statuses.answered ?? 0 }}</span>
                </button>
                <button type="button" class="filter-row" :class="{ 'is-active': status === 'pending' }" @click="setStatus('pending')">
                  <span>รอพิจารณา</span><span>{{ counts?.statuses.pending ?? 0 }}</span>
                </button>

                <p class="filter-label mt-4">การเผยแพร่</p>
                <button type="button" class="filter-row" :class="{ 'is-active': visibility === 'all' }" @click="setVisibility('all')">
                  <span>ทั้งหมด</span>
                </button>
                <button type="button" class="filter-row" :class="{ 'is-active': visibility === 'public' }" @click="setVisibility('public')">
                  <span>Public</span><span>{{ counts?.visibility.public ?? 0 }}</span>
                </button>
                <button type="button" class="filter-row" :class="{ 'is-active': visibility === 'mine' }" @click="setVisibility('mine')">
                  <span>Private ของฉัน</span><span>{{ counts?.visibility.mine ?? 0 }}</span>
                </button>

                <v-btn block variant="outlined" class="mt-4 text-none" prepend-icon="mdi-filter-off-outline" @click="clearFilters">
                  ล้างตัวกรอง
                </v-btn>
              </v-card-text>
            </v-card>
          </aside>

          <section>
            <div class="comments-toolbar">
              <v-text-field
                v-model="search"
                placeholder="ค้นหาความคิดเห็น..."
                prepend-inner-icon="mdi-magnify"
                variant="outlined"
                density="comfortable"
                hide-details
                bg-color="white"
                rounded="lg"
                @update:model-value="queueSearch"
              />
              <div class="comments-toolbar__meta">
                <span>แสดงทั้งหมด {{ total }} รายการ</span>
                <v-select
                  v-model="sort"
                  :items="sortItems"
                  item-title="title"
                  item-value="value"
                  density="compact"
                  variant="outlined"
                  hide-details
                  bg-color="white"
                  style="max-width: 140px"
                  @update:model-value="load"
                />
                <v-btn-toggle v-model="view" mandatory density="compact" variant="outlined" divided>
                  <v-btn value="list" icon="mdi-view-list" />
                  <v-btn value="grid" icon="mdi-view-grid-outline" />
                </v-btn-toggle>
              </div>
            </div>

            <div v-if="loading" class="d-flex justify-center py-10">
              <v-progress-circular indeterminate />
            </div>
            <v-alert v-else-if="error" type="error" variant="tonal">{{ error }}</v-alert>
            <v-alert v-else-if="!items.length" type="info" variant="tonal">
              ยังไม่มีความคิดเห็นที่ตรงกับตัวกรองนี้
            </v-alert>
            <div v-else class="comments-list" :class="{ 'is-grid': view === 'grid' }">
              <LawCommentCard
                v-for="comment in items"
                :key="comment.id"
                :comment="comment"
                :can-reply="canReply"
                @replied="load"
              />
            </div>
          </section>
        </div>
      </v-container>
      <ELawFooter />
    </v-main>

    <LawCommentComposer
      ref="composer"
      :document-id="documentId"
      :document-title="title"
      @submitted="load"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchLawComments } from '../../api/lawComments';
import LawCommentCard from '../../components/law/LawCommentCard.vue';
import LawCommentComposer from '../../components/law/LawCommentComposer.vue';
import ELawFooter from '../../components/shared/ELawFooter.vue';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import { COMMENT_TYPES, type LawComment, type LawCommentCounts, type LawCommentType } from '../../types/lawComment';

const props = defineProps<{ documentId: string }>();
const router = useRouter();

const items = ref<LawComment[]>([]);
const counts = ref<LawCommentCounts | null>(null);
const total = ref(0);
const title = ref('กฎหมาย');
const canReply = ref(false);
const loading = ref(false);
const error = ref('');
const type = ref<LawCommentType | ''>('');
const status = ref<'' | 'pending' | 'answered'>('');
const visibility = ref<'all' | 'public' | 'mine'>('all');
const search = ref('');
const sort = ref<'newest' | 'oldest'>('newest');
const view = ref<'list' | 'grid'>('list');
const composer = ref<{ open: () => void } | null>(null);
const sortItems = [
  { title: 'ล่าสุด', value: 'newest' },
  { title: 'เก่าสุด', value: 'oldest' },
];

let searchTimer = 0;

function setType(value: LawCommentType | ''): void {
  type.value = value;
  void load();
}

function setStatus(value: '' | 'pending' | 'answered'): void {
  status.value = value;
  void load();
}

function setVisibility(value: 'all' | 'public' | 'mine'): void {
  visibility.value = value;
  void load();
}

function clearFilters(): void {
  type.value = '';
  status.value = '';
  visibility.value = 'all';
  search.value = '';
  void load();
}

function queueSearch(): void {
  window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => { void load(); }, 300);
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const result = await fetchLawComments(props.documentId, {
      type: type.value,
      status: status.value,
      visibility: visibility.value,
      q: search.value.trim(),
      sort: sort.value,
    });
    items.value = result.items;
    counts.value = result.counts;
    total.value = result.total;
    canReply.value = result.can_reply;
    if (result.document.title) title.value = result.document.title;
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'โหลดความคิดเห็นไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

onMounted(() => { void load(); });
</script>

<style scoped>
.comments-page {
  min-height: 100vh;
  background: #f6f4ef;
  color: #1e293b;
}

.comments-page__crumb {
  background: #fff;
  border-bottom: 1px solid rgba(0, 0, 0, 0.08);
  min-height: 40px;
  display: flex;
  align-items: center;
}

.comments-page__main { background: #f6f4ef; }

.comments-hero {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  padding: 22px 24px;
  background: #fff;
}

.comments-hero h1 {
  margin: 0;
  font-size: 1.45rem;
  color: #1a2e52;
}

.comments-hero p {
  margin: 6px 0 0;
  color: #64748b;
}

.comments-layout {
  display: grid;
  grid-template-columns: 260px minmax(0, 1fr);
  gap: 18px;
  align-items: start;
}

.comments-filters { position: sticky; top: 112px; }

.filter-label {
  margin: 0 0 6px;
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
}

.filter-row {
  width: 100%;
  display: flex;
  justify-content: space-between;
  gap: 8px;
  border: 0;
  background: transparent;
  border-radius: 8px;
  padding: 8px 10px;
  text-align: left;
  cursor: pointer;
  color: #1e293b;
}

.filter-row.is-active {
  background: #f3ead3;
  color: #8a6418;
  font-weight: 700;
}

.comments-toolbar {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 14px;
}

.comments-toolbar__meta {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  color: #64748b;
  font-size: 14px;
}

.comments-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.comments-list.is-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

@media (max-width: 960px) {
  .comments-layout,
  .comments-list.is-grid { grid-template-columns: 1fr; }
  .comments-filters { position: static; }
  .comments-hero { flex-direction: column; align-items: stretch; }
}
</style>
