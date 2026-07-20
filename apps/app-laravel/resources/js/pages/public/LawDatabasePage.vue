<template>
  <div class="d-flex flex-column" style="min-height: 100vh">
    <ELawNavbar @go-admin="router.push('/admin')" />
    <v-main>
      <section class="elaw-db-header">
        <v-container style="max-width: 1280px">
          <div class="text-center mb-5">
            <h1 class="text-h5 font-weight-black text-elaw-navy mb-2">
              สืบค้นกฎหมายและลำดับศักดิ์เอกสารภาครัฐ
            </h1>
            <p class="text-body-2 text-medium-emphasis mb-0">
              ค้นหาชื่อกฎหมาย, เลขที่ประกาศ, คำสำคัญ, มาตรา หรือหน่วยงานที่เกี่ยวข้อง
            </p>
          </div>

          <div class="d-flex flex-column flex-md-row ga-3 align-stretch align-md-start">
            <div class="flex-grow-1 elaw-search-shell">
              <v-text-field
                v-model="query"
                :placeholder="'พิมพ์คำค้น เช่น พระราชบัญญัติ, มาตรา, สิทธิข้อมูลส่วนบุคคล...'"
                variant="outlined"
                density="comfortable"
                hide-details
                rounded="xl"
                class="elaw-search-input"
                bg-color="detail-surface"
                @focus="searchFocused = true"
                @blur="queueHideSuggestions"
                @keydown.enter.prevent="doSearch"
              />
              <v-card
                v-if="showSuggestions"
                class="elaw-suggest-card"
                flat
                border
              >
                <div v-if="searchStore.suggesting" class="d-flex align-center ga-2 px-4 py-3 text-caption text-medium-emphasis">
                  <v-progress-circular indeterminate size="14" width="2" />
                  กำลังแนะนำคำค้น...
                </div>
                <div v-else-if="searchStore.suggestions.length === 0" class="px-4 py-3 text-caption text-medium-emphasis">
                  ไม่พบคำแนะนำเพิ่มเติม
                </div>
                <button
                  v-for="suggestion in searchStore.suggestions"
                  :key="suggestion.law_id"
                  type="button"
                  class="elaw-suggest-item"
                  @mousedown.prevent="applySuggestion(suggestion)"
                >
                  <div class="d-flex align-center justify-space-between ga-3">
                    <span class="text-body-2 font-weight-bold text-left">
                      {{ suggestion.title || 'ไม่ระบุชื่อกฎหมาย' }}
                    </span>
                    <v-chip size="x-small" color="primary" rounded="pill">
                      {{ lawTypeLabel(suggestion.law_type) }}
                    </v-chip>
                  </div>
                  <div class="d-flex flex-wrap ga-2 mt-2">
                    <v-chip
                      v-for="keyword in suggestion.keywords.slice(0, 3)"
                      :key="`${suggestion.law_id}-${keyword}`"
                      size="x-small"
                      variant="tonal"
                      color="secondary"
                    >
                      {{ keyword }}
                    </v-chip>
                  </div>
                  <div class="d-flex flex-wrap ga-3 mt-2 text-caption text-medium-emphasis">
                    <span>{{ suggestion.agency || 'ไม่ระบุหน่วยงาน' }}</span>
                    <span>{{ suggestion.published_date || 'ไม่ระบุปีประกาศ' }}</span>
                  </div>
                </button>
              </v-card>
            </div>
            <v-btn color="secondary" size="large" rounded="lg" @click="doSearch">
              <v-icon start icon="mdi-magnify" />
              ค้นหาข้อมูล
            </v-btn>
          </div>

          <v-row class="ga-4 mt-4 justify-start align-start">
            <v-col cols="12" md="7">
              <div class="d-flex align-start flex-wrap ga-3 elaw-filter-row">
                <p class="text-caption font-weight-bold text-medium-emphasis mb-0 mt-0">ประเภทเอกสาร</p>
                <v-chip
                  :variant="isTypeSelected('all') ? 'flat' : 'outlined'"
                  color="primary"
                  rounded="pill"
                  size="small"
                  class="elaw-search-chip"
                  @click="toggleType('all')"
                >
                  ทั้งหมด
                </v-chip>
                <v-chip
                  v-for="type in typeFilters"
                  :key="type.value"
                  :variant="isTypeSelected(type.value) ? 'flat' : 'outlined'"
                  color="primary"
                  rounded="pill"
                  size="small"
                  class="elaw-search-chip"
                  @click="toggleType(type.value)"
                >
                  {{ type.label }}
                  <span class="ml-1 text-caption">({{ type.count }})</span>
                </v-chip>
              </div>
            </v-col>
          </v-row>
        </v-container>
      </section>

      <v-container style="max-width: 1280px" class="mt-6 mb-10">
        <v-row>
          <v-col cols="12" md="3">
            <v-card flat border rounded="lg" class="pa-4">
              <div class="d-flex justify-space-between align-center mb-3">
                <span class="text-subtitle-2 font-weight-bold">ตัวกรองผลการค้นหา</span>
                <v-btn variant="text" size="x-small" color="error" @click="clearFilters">ล้างทั้งหมด</v-btn>
              </div>

              <v-expansion-panels v-model="filterPanels" multiple variant="accordion" class="elaw-filter-panels">
                <v-expansion-panel value="change-status">
                  <v-expansion-panel-title>สถานะการเปลี่ยนแปลง</v-expansion-panel-title>
                  <v-expansion-panel-text>
                    <v-checkbox
                      v-for="status in changeStatusFilters"
                      :key="status.value"
                      v-model="selectedStatuses"
                      :value="status.value"
                      density="compact"
                      hide-details
                      class="mb-n1"
                    >
                      <template #label>
                        <span class="elaw-filter-option">
                          <span>{{ status.label }}</span>
                          <v-chip size="x-small" color="primary" variant="tonal" rounded="pill">{{ status.count }}</v-chip>
                        </span>
                      </template>
                    </v-checkbox>
                  </v-expansion-panel-text>
                </v-expansion-panel>

                <v-expansion-panel value="use-status">
                  <v-expansion-panel-title>สถานะการบังคับใช้</v-expansion-panel-title>
                  <v-expansion-panel-text>
                    <v-checkbox
                      v-for="status in useStatusFilters"
                      :key="status.value"
                      v-model="selectedUseStatuses"
                      :value="status.value"
                      density="compact"
                      hide-details
                      class="mb-n1"
                    >
                      <template #label>
                        <span class="elaw-filter-option">
                          <span>{{ status.label }}</span>
                          <v-chip size="x-small" color="primary" variant="tonal" rounded="pill">{{ status.count }}</v-chip>
                        </span>
                      </template>
                    </v-checkbox>
                  </v-expansion-panel-text>
                </v-expansion-panel>

                <v-expansion-panel value="year">
                  <v-expansion-panel-title>ปีประกาศ</v-expansion-panel-title>
                  <v-expansion-panel-text>
                    <div class="d-flex ga-2 align-center">
                      <v-select v-model="yearFrom" :items="years" label="ปีเริ่มต้น" density="compact" hide-details />
                      <span class="text-caption">ถึง</span>
                      <v-select v-model="yearTo" :items="years" label="ปีสิ้นสุด" density="compact" hide-details />
                    </div>
                  </v-expansion-panel-text>
                </v-expansion-panel>

                <v-expansion-panel value="agency">
                  <v-expansion-panel-title>หน่วยงาน</v-expansion-panel-title>
                  <v-expansion-panel-text>
                    <v-checkbox
                      v-for="agency in agencyFilters"
                      :key="agency.value"
                      v-model="selectedAgencies"
                      :value="agency.value"
                      density="compact"
                      hide-details
                      class="mb-n1"
                    >
                      <template #label>
                        <span class="elaw-filter-option">
                          <span>{{ agency.label }}</span>
                          <v-chip size="x-small" color="primary" variant="tonal" rounded="pill">{{ agency.count }}</v-chip>
                        </span>
                      </template>
                    </v-checkbox>
                  </v-expansion-panel-text>
                </v-expansion-panel>

                <v-expansion-panel value="law-group">
                  <v-expansion-panel-title>กลุ่มกฎหมาย</v-expansion-panel-title>
                  <v-expansion-panel-text>
                    <v-checkbox
                      v-for="group in groupFilters"
                      :key="group.value"
                      v-model="selectedGroups"
                      :value="group.value"
                      density="compact"
                      hide-details
                      class="mb-n1"
                    >
                      <template #label>
                        <span class="elaw-filter-option">
                          <v-tooltip :text="group.label" location="top">
                            <template #activator="{ props: tooltipProps }">
                              <span v-bind="tooltipProps" class="elaw-filter-option__label elaw-filter-option__label--truncate">
                                {{ group.label }}
                              </span>
                            </template>
                          </v-tooltip>
                          <v-chip size="x-small" color="primary" variant="tonal" rounded="pill">{{ group.count }}</v-chip>
                        </span>
                      </template>
                    </v-checkbox>
                  </v-expansion-panel-text>
                </v-expansion-panel>

                <v-expansion-panel value="keeper-group">
                  <v-expansion-panel-title>กลุ่มผู้ออกคำสั่ง/ลงนาม</v-expansion-panel-title>
                  <v-expansion-panel-text>
                    <v-checkbox
                      v-for="keeper in keeperGroupFilters"
                      :key="keeper.value"
                      v-model="selectedKeeperGroups"
                      :value="keeper.value"
                      density="compact"
                      hide-details
                      class="mb-n1"
                    >
                      <template #label>
                        <span class="elaw-filter-option">
                          <span>{{ keeper.label }}</span>
                          <v-chip size="x-small" color="primary" variant="tonal" rounded="pill">{{ keeper.count }}</v-chip>
                        </span>
                      </template>
                    </v-checkbox>
                  </v-expansion-panel-text>
                </v-expansion-panel>
              </v-expansion-panels>

              <v-divider class="my-3" />

              <v-btn color="primary" block size="small" rounded="lg" @click="clearFilters">
                <v-icon start icon="mdi-refresh" />
                ล้างตัวกรอง
              </v-btn>
            </v-card>
          </v-col>

          <v-col cols="12" md="9">
            <div class="d-flex flex-column flex-sm-row align-start align-sm-center justify-space-between mb-3 ga-3">
              <div class="d-flex flex-column">
                <span class="text-body-2">
                  พบผลการค้นหา <strong>{{ searchStore.total }}</strong> รายการ
                </span>
                <span v-if="searchStore.loading" class="text-caption text-medium-emphasis">กำลังค้นหา...</span>
              </div>
              <v-select
                v-model="sortBy"
                :items="sortOptions"
                item-title="label"
                item-value="value"
                variant="outlined"
                density="compact"
                hide-details
                style="max-width: 220px"
              />
            </div>

            <div v-if="searchStore.loading" class="d-flex justify-center py-10">
              <v-progress-circular indeterminate color="primary" />
            </div>

            <div v-else-if="sortedResults.length === 0" class="law-empty-state">
              <v-icon icon="mdi-file-search-outline" size="28" color="medium-emphasis" />
              <p class="text-body-2 text-medium-emphasis mb-0">ไม่พบเอกสาร</p>
            </div>

            <div v-else class="d-flex flex-column ga-3">
              <v-card
                v-for="law in sortedResults"
                :key="law.law_id"
                flat
                border
                rounded="lg"
                class="pa-4 law-result-card"
                style="cursor: pointer"
                @click="router.push({ name: 'law', params: { documentId: law.law_id } })"
              >
                <div class="d-flex flex-wrap align-center ga-2 mb-3">
                  <v-chip size="x-small" color="primary" rounded="pill">{{ lawTypeLabel(law.law_type) }}</v-chip>
                  <v-chip v-if="law.status" size="x-small" color="success" variant="tonal" rounded="pill">
                    {{ statusLabel(law.status) }}
                  </v-chip>
                  <v-chip v-if="law.change_status" size="x-small" color="warning" variant="tonal" rounded="pill">
                    {{ changeStatusLabel(law.change_status) }}
                  </v-chip>
                </div>

                <h2 class="text-subtitle-1 font-weight-bold mb-2 law-result-card__title">
                  {{ law.title || 'ไม่ระบุชื่อกฎหมาย' }}
                </h2>

                <p v-if="law.summary" class="text-body-2 text-medium-emphasis mb-3 law-result-card__summary">
                  {{ law.summary }}
                </p>

                <div class="d-flex flex-wrap ga-4 text-caption text-medium-emphasis mb-3">
                  <span class="law-result-card__meta-item">
                    <v-icon size="13" icon="mdi-calendar-month-outline" />
                    {{ law.published_date || 'ไม่ระบุวันที่ประกาศ' }}
                  </span>
                  <span class="law-result-card__meta-item">
                    <v-icon size="13" icon="mdi-domain" />
                    {{ law.agency || 'ไม่ระบุหน่วยงาน' }}
                  </span>
                  <span v-if="law.signer_group" class="law-result-card__meta-item">
                    <v-icon size="13" icon="mdi-account-group-outline" />
                    {{ law.signer_group }}
                  </span>
                </div>

                <div class="d-flex flex-column ga-2">
                  <div
                    v-for="(snippet, index) in law.snippets"
                    :key="`${law.law_id}-${index}`"
                    class="law-snippet text-body-2"
                    v-html="sanitizeHighlight(snippet)"
                  />
                </div>
              </v-card>
            </div>

            <div class="d-flex justify-center mt-6">
              <v-pagination
                v-model="page"
                :length="pageCount"
                :total-visible="7"
                rounded="circle"
              />
            </div>
          </v-col>
        </v-row>
      </v-container>

      <ELawFooter />
    </v-main>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { fetchLawFacets, getLookups } from '../../api/client';
import ELawFooter from '../../components/shared/ELawFooter.vue';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import { useLawSearchStore } from '../../stores/lawSearchStore';
import type { FacetBucket, LawSearchFacets, LawSearchFilters, LawSearchResult, LawSuggestion } from '../../types/lawSearch';
import { sanitizeHighlight } from '../../utils/highlightSanitizer';

const PER_PAGE = 20;

const LAW_TYPE_LABELS: Record<string, string> = {
  phrb: 'พ.ร.บ.',
  'พ.ร.บ.': 'พ.ร.บ.',
  พระราชบัญญัติ: 'พ.ร.บ.',
  'kho-bangkhab': 'ข้อบังคับ',
  ข้อบังคับ: 'ข้อบังคับ',
  rabiap: 'ระเบียบ',
  ระเบียบ: 'ระเบียบ',
  prakat: 'ประกาศ',
  ประกาศ: 'ประกาศ',
  command: 'คำสั่ง',
  คำสั่ง: 'คำสั่ง',
  resolution: 'มติ',
  มติ: 'มติ',
};

const CHANGE_STATUS_LABELS: Record<string, string> = {
  new: 'ออกใหม่',
  amended: 'แก้ไขเพิ่มเติม',
  repealed: 'ยกเลิก',
  consolidated: 'ฉบับรวม',
};

const STATUS_LABELS: Record<string, string> = {
  active: 'มีผลบังคับใช้',
  มีผลบังคับใช้: 'มีผลบังคับใช้',
  cancelled: 'ยกเลิก',
  ยกเลิก: 'ยกเลิก',
  draft: 'ร่าง',
  ร่าง: 'ร่าง',
};

const LAW_TYPE_CANONICAL_VALUES: Record<string, string> = {
  phrb: 'phrb',
  'พ.ร.บ.': 'phrb',
  พระราชบัญญัติ: 'phrb',
  'kho-bangkhab': 'kho-bangkhab',
  ข้อบังคับ: 'kho-bangkhab',
  rabiap: 'rabiap',
  ระเบียบ: 'rabiap',
  prakat: 'prakat',
  ประกาศ: 'prakat',
  command: 'command',
  คำสั่ง: 'command',
  resolution: 'resolution',
  มติ: 'resolution',
};

const LAW_TYPE_FILTER_ALIASES: Record<string, string[]> = {
  phrb: ['phrb', 'พ.ร.บ.', 'พระราชบัญญัติ'],
  'kho-bangkhab': ['kho-bangkhab', 'ข้อบังคับ'],
  rabiap: ['rabiap', 'ระเบียบ'],
  prakat: ['prakat', 'ประกาศ'],
  command: ['command', 'คำสั่ง'],
  resolution: ['resolution', 'มติ'],
};

const LAW_GROUP_ALIAS_VALUES: Record<string, string> = {
  academic: 'ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร',
  'student-affairs': 'ด้านกิจการนิสิต',
  'research-innovation': 'ด้านการวิจัย นวัตกรรม และการนำไปใช้ประโยชน์',
  'academic-service': 'ด้านบริการวิชาการ',
  'organization-admin': 'ด้านโครงสร้างองค์กรและระบบการบริหาร',
  'hr-discipline': 'ด้านการบริหารงานบุคคล สิทธิประโยชน์ วินัยและจรรยาบรรณ',
  'finance-assets-risk': 'ด้านการเงินและทรัพย์สิน พัสดุ การตรวจสอบ และการบริหารความเสี่ยง',
  other: 'ด้านอื่น ๆ',
};

type SortValue = 'relevance' | 'thai-asc' | 'thai-desc' | 'newest' | 'oldest';

const router = useRouter();
const route = useRoute();
const searchStore = useLawSearchStore();
const baseFacets = ref<LawSearchFacets | null>(null);

const query = ref('');
const selectedTypes = ref<string[]>(['all']);
const selectedGroups = ref<string[]>([]);
const selectedStatuses = ref<string[]>([]);
const selectedUseStatuses = ref<string[]>([]);
const selectedAgencies = ref<string[]>([]);
const selectedKeeperGroups = ref<string[]>([]);
const yearFrom = ref<string | null>(null);
const yearTo = ref<string | null>(null);
const sortBy = ref<SortValue>('relevance');
const page = ref(1);
const filterPanels = ref(['change-status', 'use-status', 'year']);
const searchFocused = ref(false);

const sortOptions = [
  { label: 'เกี่ยวข้องมากที่สุด', value: 'relevance' },
  { label: 'เรียงลำดับตาม ก-ฮ', value: 'thai-asc' },
  { label: 'เรียงลำดับตาม ฮ-ก', value: 'thai-desc' },
  { label: 'ล่าสุด', value: 'newest' },
  { label: 'เก่าสุด', value: 'oldest' },
] as const;

const currentTypes = computed(() => selectedTypes.value.includes('all') ? [] : selectedTypes.value);
const pageCount = computed(() => Math.max(1, Math.ceil(searchStore.total / PER_PAGE)));
const showSuggestions = computed(() => searchFocused.value && query.value.trim().length >= 2 && (searchStore.suggesting || searchStore.suggestions.length > 0));

function effectiveFacet(key: keyof Omit<LawSearchFacets, 'years'>): FacetBucket[] {
  const fromSearch = searchStore.facets[key];
  if (fromSearch.length > 0) return fromSearch;
  return baseFacets.value?.[key] ?? [];
}

function staticFacet(key: keyof Omit<LawSearchFacets, 'years'>, knownValues: string[]): FacetBucket[] {
  const live = effectiveFacet(key);
  const liveMap = new Map(live.map((b) => [b.value, b.count]));
  return knownValues.map((v) => ({ value: v, count: liveMap.get(v) ?? 0 }));
}

const typeFilters = computed(() => mapFacetOptions(staticFacet('law_type', Object.keys(LAW_TYPE_LABELS)), lawTypeLabel));
const groupFilters = computed(() => mapFacetOptions(effectiveFacet('law_group')));
const agencyFilters = computed(() => mapFacetOptions(effectiveFacet('agency')));
const keeperGroupFilters = computed(() => mapFacetOptions(effectiveFacet('signer_group')));
const changeStatusFilters = computed(() => mapFacetOptions(staticFacet('change_status', Object.keys(CHANGE_STATUS_LABELS)), changeStatusLabel));
const useStatusFilters = computed(() => mapFacetOptions(staticFacet('status', Object.keys(STATUS_LABELS)), statusLabel));
const years = computed(() => {
  const yearBuckets = searchStore.facets.years.length > 0 ? searchStore.facets.years : (baseFacets.value?.years ?? []);
  const values = yearBuckets.map((bucket) => String(bucket.year));
  if (yearFrom.value) values.push(yearFrom.value);
  if (yearTo.value) values.push(yearTo.value);

  return Array.from(new Set(values.filter(Boolean))).sort((left, right) => Number(right) - Number(left));
});

const sortedResults = computed(() => {
  const items = [...searchStore.results];

  switch (sortBy.value) {
    case 'thai-asc':
      return items.sort((left, right) => (left.title || '').localeCompare(right.title || '', 'th'));
    case 'thai-desc':
      return items.sort((left, right) => (right.title || '').localeCompare(left.title || '', 'th'));
    case 'newest':
      return items.sort((left, right) => extractYear(right) - extractYear(left));
    case 'oldest':
      return items.sort((left, right) => extractYear(left) - extractYear(right));
    default:
      return items;
  }
});

let syncingFromRoute = false;
let suggestTimer: ReturnType<typeof setTimeout> | null = null;
let hideSuggestionsTimer: ReturnType<typeof setTimeout> | null = null;
let routeUpdateTimer: ReturnType<typeof setTimeout> | null = null;
let suppressNextRouteSearch = false;
let mutatingSearchState = false;

watch(
  () => route.query,
  () => {
    syncingFromRoute = true;
    syncFromRoute();
    syncingFromRoute = false;
    if (suppressNextRouteSearch) {
      suppressNextRouteSearch = false;
      return;
    }
    searchStore.clearSuggestions();
    void runSearch();
  },
  { immediate: true },
);

watch(query, () => {
  if (syncingFromRoute || mutatingSearchState) return;
  page.value = 1;
  if (query.value.trim().length < 2) {
    searchStore.clearSuggestions();
  }
  queueSuggest();
  queueRouteUpdate();
});

watch([selectedGroups, selectedStatuses, selectedUseStatuses, selectedAgencies, selectedKeeperGroups, yearFrom, yearTo], () => {
  if (syncingFromRoute || mutatingSearchState) return;
  page.value = 1;
  searchStore.clearSuggestions();
  void syncRouteAndSearch();
}, { deep: true });

watch(selectedTypes, () => {
  if (syncingFromRoute || mutatingSearchState) return;
  page.value = 1;
  searchStore.clearSuggestions();
  void syncRouteAndSearch();
}, { deep: true });

watch(page, () => {
  if (syncingFromRoute || mutatingSearchState) return;
  searchStore.clearSuggestions();
  void syncRouteAndSearch();
});

watch(sortBy, () => {
  if (syncingFromRoute || mutatingSearchState) return;
  void replaceRoute();
});

function currentFilters(): LawSearchFilters {
  return {
    law_type: currentTypes.value.length > 0 ? expandLawTypeFilterValues(currentTypes.value) : undefined,
    status: selectedUseStatuses.value.length > 0 ? selectedUseStatuses.value : undefined,
    change_status: selectedStatuses.value.length > 0 ? selectedStatuses.value : undefined,
    agency: selectedAgencies.value.length > 0 ? selectedAgencies.value : undefined,
    law_group: selectedGroups.value.length > 0 ? selectedGroups.value : undefined,
    signer_group: selectedKeeperGroups.value.length > 0 ? selectedKeeperGroups.value : undefined,
    year_from: yearFrom.value ? Number(yearFrom.value) : null,
    year_to: yearTo.value ? Number(yearTo.value) : null,
  };
}

function isTypeSelected(value: string): boolean {
  return selectedTypes.value.includes(value);
}

function toggleType(value: string): void {
  if (value === 'all') {
    selectedTypes.value = ['all'];
    return;
  }

  const next = new Set(selectedTypes.value);
  next.delete('all');

  if (next.has(value)) {
    next.delete(value);
  } else {
    next.add(value);
  }

  selectedTypes.value = next.size > 0 ? Array.from(next) : ['all'];
}

function doSearch(): void {
  mutatingSearchState = true;
  page.value = 1;
  mutatingSearchState = false;
  if (suggestTimer) {
    clearTimeout(suggestTimer);
    suggestTimer = null;
  }
  searchFocused.value = false;
  searchStore.clearSuggestions();
  void syncRouteAndSearch();
}

function clearFilters(): void {
  mutatingSearchState = true;
  selectedTypes.value = ['all'];
  selectedGroups.value = [];
  selectedStatuses.value = [];
  selectedUseStatuses.value = [];
  selectedAgencies.value = [];
  selectedKeeperGroups.value = [];
  yearFrom.value = null;
  yearTo.value = null;
  page.value = 1;
  mutatingSearchState = false;
  searchStore.clearSuggestions();
  void syncRouteAndSearch();
}

function queueSuggest(): void {
  if (suggestTimer) {
    clearTimeout(suggestTimer);
  }

  suggestTimer = setTimeout(() => {
    void searchStore.suggest(query.value);
  }, 350);
}

function queueRouteUpdate(): void {
  if (routeUpdateTimer) {
    clearTimeout(routeUpdateTimer);
  }
  routeUpdateTimer = setTimeout(() => {
    suppressNextRouteSearch = true;
    void replaceRoute();
  }, 120);
}

function queueHideSuggestions(): void {
  hideSuggestionsTimer = setTimeout(() => {
    searchFocused.value = false;
  }, 120);
}

function applySuggestion(suggestion: LawSuggestion): void {
  if (hideSuggestionsTimer) {
    clearTimeout(hideSuggestionsTimer);
  }
  mutatingSearchState = true;
  query.value = suggestion.title || query.value;
  page.value = 1;
  mutatingSearchState = false;
  searchFocused.value = false;
  searchStore.clearSuggestions();
  void syncRouteAndSearch();
}

async function syncRouteAndSearch(): Promise<void> {
  suppressNextRouteSearch = true;
  await replaceRoute();
  await runSearch();
}

async function replaceRoute(): Promise<void> {
  const nextQuery: Record<string, string | string[]> = {};

  if (query.value.trim() !== '') nextQuery.q = query.value.trim();
  if (currentTypes.value.length > 0) nextQuery.type = currentTypes.value;
  if (selectedGroups.value.length > 0) nextQuery.group = selectedGroups.value;
  if (selectedStatuses.value.length > 0) nextQuery.change_status = selectedStatuses.value;
  if (selectedUseStatuses.value.length > 0) nextQuery.status = selectedUseStatuses.value;
  if (selectedAgencies.value.length > 0) nextQuery.agency = selectedAgencies.value;
  if (selectedKeeperGroups.value.length > 0) nextQuery.signer_group = selectedKeeperGroups.value;
  if (yearFrom.value) nextQuery.year_from = yearFrom.value;
  if (yearTo.value) nextQuery.year_to = yearTo.value;
  if (sortBy.value !== 'relevance') nextQuery.sort = sortBy.value;
  if (page.value > 1) nextQuery.page = String(page.value);

  await router.replace({ path: '/database', query: nextQuery });
}

async function runSearch(): Promise<void> {
  await searchStore.search(query.value.trim(), currentFilters(), page.value, PER_PAGE);
  if (page.value > pageCount.value) {
    page.value = pageCount.value;
  }
}

function syncFromRoute(): void {
  query.value = readString(route.query.q);
  selectedTypes.value = readTypeArray(route.query.type);
  selectedGroups.value = uniqueStrings(readStringArray(route.query.group).map(normalizeLawGroupValue));
  selectedStatuses.value = readStringArray(route.query.change_status);
  selectedUseStatuses.value = readStringArray(route.query.status);
  selectedAgencies.value = readStringArray(route.query.agency);
  selectedKeeperGroups.value = readStringArray(route.query.signer_group);
  yearFrom.value = readNullableString(route.query.year_from);
  yearTo.value = readNullableString(route.query.year_to);
  sortBy.value = readSortValue(route.query.sort);
  page.value = readPositiveInt(route.query.page, 1);
}

function readTypeArray(value: unknown): string[] {
  if (Array.isArray(value)) {
    const next = uniqueStrings(value
      .filter((entry): entry is string => typeof entry === 'string' && entry.length > 0)
      .map(canonicalLawTypeValue));
    return next.length > 0 ? next : ['all'];
  }

  if (typeof value === 'string' && value.length > 0) {
    return [canonicalLawTypeValue(value)];
  }

  return ['all'];
}

function readStringArray(value: unknown): string[] {
  if (Array.isArray(value)) {
    return value.filter((entry): entry is string => typeof entry === 'string' && entry.length > 0);
  }

  if (typeof value === 'string' && value.length > 0) {
    return [value];
  }

  return [];
}

function readString(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function readNullableString(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 ? value : null;
}

function readPositiveInt(value: unknown, fallback: number): number {
  const raw = Array.isArray(value) ? value[0] : value;
  const parsed = Number(raw);

  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
}

function readSortValue(value: unknown): SortValue {
  const raw = Array.isArray(value) ? value[0] : value;
  const allowed = sortOptions.map((option) => option.value);

  return typeof raw === 'string' && allowed.includes(raw as SortValue)
    ? raw as SortValue
    : 'relevance';
}

function mapFacetOptions(
  buckets: FacetBucket[],
  labelResolver?: (value: string | null) => string,
): Array<{ label: string; value: string; count: number }> {
  return buckets.map((bucket) => ({
    label: labelResolver ? labelResolver(bucket.value) : bucket.value,
    value: bucket.value,
    count: bucket.count,
  }));
}

function canonicalLawTypeValue(value: string): string {
  return LAW_TYPE_CANONICAL_VALUES[value] ?? value;
}

function expandLawTypeFilterValues(values: string[]): string[] {
  return Array.from(new Set(values.flatMap((value) => {
    const canonical = canonicalLawTypeValue(value);
    return LAW_TYPE_FILTER_ALIASES[canonical] ?? [canonical];
  })));
}

function normalizeLawGroupValue(value: string): string {
  return LAW_GROUP_ALIAS_VALUES[value] ?? value;
}

function uniqueStrings(values: string[]): string[] {
  return Array.from(new Set(values));
}

function lawTypeLabel(value: string | null): string {
  if (!value) return 'ไม่ระบุประเภท';
  return LAW_TYPE_LABELS[value] ?? value;
}

function changeStatusLabel(value: string | null): string {
  if (!value) return 'ไม่ระบุสถานะ';
  return CHANGE_STATUS_LABELS[value] ?? value;
}

function statusLabel(value: string | null): string {
  if (!value) return 'ไม่ระบุสถานะ';
  return STATUS_LABELS[value] ?? value;
}

function extractYear(item: LawSearchResult): number {
  const match = item.published_date?.match(/\d{4}/);
  return match ? Number(match[0]) : 0;
}

let refreshTimer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
  fetchLawFacets().then((f) => { baseFacets.value = f; }).catch(() => { /* non-fatal */ });
  refreshTimer = setInterval(() => {
    void runSearch();
    fetchLawFacets().then((f) => { baseFacets.value = f; }).catch(() => { /* non-fatal */ });
  }, 30_000);
});

onBeforeUnmount(() => {
  if (suggestTimer) clearTimeout(suggestTimer);
  if (hideSuggestionsTimer) clearTimeout(hideSuggestionsTimer);
  if (routeUpdateTimer) clearTimeout(routeUpdateTimer);
  if (refreshTimer) clearInterval(refreshTimer);
  searchStore.clearSuggestions();
});
</script>

<style scoped>
.elaw-db-header {
  background: linear-gradient(180deg, #f8f7f1 8%, #fff4b5 100%);
  border-bottom: 1px solid var(--elaw-border);
  padding: 28px 24px 34px;
}

.elaw-search-input :deep(.v-field__input) {
  min-height: 58px;
}

.elaw-search-shell {
  position: relative;
}

.elaw-suggest-card {
  position: absolute;
  top: calc(100% + 8px);
  left: 0;
  right: 0;
  z-index: 20;
  overflow: hidden;
  border-radius: 12px !important;
  background: rgb(var(--v-theme-detail-surface));
}

.elaw-suggest-item {
  display: block;
  width: 100%;
  padding: 14px 16px;
  border: 0;
  border-top: 1px solid rgba(171, 127, 41, 0.14);
  background: transparent;
  text-align: left;
}

.elaw-suggest-item:first-of-type {
  border-top: 0;
}

.elaw-suggest-item:hover {
  background: rgba(255, 250, 236, 0.85);
}

.elaw-search-chip {
  font-weight: 700;
}

.elaw-filter-panels {
  border: 1px solid rgba(171, 127, 41, 0.14);
  border-radius: 12px;
  overflow: hidden;
}

.elaw-filter-panels :deep(.v-expansion-panel) {
  box-shadow: none !important;
}

.elaw-filter-panels :deep(.v-expansion-panel-title) {
  min-height: 42px;
  padding: 10px 12px;
  font-size: 0.82rem;
  font-weight: 700;
}

.elaw-filter-panels :deep(.v-expansion-panel-text__wrapper) {
  padding: 4px 12px 12px;
}

.elaw-filter-option {
  display: inline-flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  width: 100%;
  max-width: 100%;
  min-width: 0;
  line-height: 1.35;
}

.elaw-filter-panels :deep(.v-selection-control) {
  min-width: 0;
}

.elaw-filter-panels :deep(.v-selection-control__wrapper) {
  flex: 0 0 auto;
}

.elaw-filter-panels :deep(.v-selection-control .v-label) {
  width: 100%;
  min-width: 0;
  overflow: hidden;
  opacity: 1;
}

.elaw-filter-option > span:first-child {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.elaw-filter-option :deep(.v-chip) {
  flex-shrink: 0;
}

.elaw-filter-option__label {
  flex: 1 1 auto;
  min-width: 0;
  max-width: 100%;
}

.elaw-filter-option__label--truncate {
  display: inline-block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.elaw-filter-row {
  min-height: 54px;
}

.elaw-filter-row > p {
  line-height: 1;
}

.law-result-card__title {
  line-height: 1.5;
}

.law-result-card__summary {
  line-height: 1.6;
}

.law-result-card__meta-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.law-snippet {
  border-left: 3px solid rgba(var(--v-theme-primary), 0.24);
  padding: 8px 0 8px 12px;
  color: rgba(0, 0, 0, 0.76);
}

.law-snippet :deep(mark) {
  background: rgba(var(--v-theme-warning), 0.35);
  padding: 0 2px;
  border-radius: 2px;
}

.law-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  min-height: 240px;
  border: 1px dashed rgba(171, 127, 41, 0.28);
  border-radius: 12px;
  background: rgba(255, 250, 236, 0.55);
}
</style>
