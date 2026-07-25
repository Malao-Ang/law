<template>
  <div class="d-flex flex-column" style="min-height: 100vh">
    <ELawNavbar @go-admin="router.push('/admin')" />
    <v-main>
      <section class="elaw-db-header">
        <v-container style="max-width: 1280px">
          <div class="text-center mb-5">
            <h1 class="elaw-db-header__title mb-2">
              สืบค้นกฎหมายและลำดับศักดิ์เอกสารภาครัฐ
            </h1>
            <p class="elaw-db-header__sub mb-0">
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
            <button type="button" class="elaw-db-search-btn" @click="doSearch">
              <v-icon icon="mdi-magnify" size="18" />
              ค้นหาข้อมูล
            </button>
          </div>

          <div class="d-flex align-center flex-wrap ga-2 mt-4 elaw-filter-row">
            <span class="elaw-filter-row__label">ประเภทเอกสาร:</span>
            <button
              type="button"
              class="elaw-type-pill"
              :class="{ 'elaw-type-pill--active': isTypeSelected('all') }"
              @click="toggleType('all')"
            >ทั้งหมด</button>
            <button
              v-for="type in typeFilters"
              :key="type.value"
              type="button"
              class="elaw-type-pill"
              :class="{ 'elaw-type-pill--active': isTypeSelected(type.value) }"
              @click="toggleType(type.value)"
            >{{ type.label }}<span class="elaw-type-pill__count"> ({{ type.count }})</span></button>

            <v-menu :close-on-content-click="false" max-height="320">
              <template #activator="{ props: menuProps }">
                <button type="button" class="elaw-type-pill elaw-group-pill" v-bind="menuProps">
                  {{ selectedGroups.length > 0 ? `กลุ่มกฎหมาย (${selectedGroups.length})` : `กลุ่มกฎหมาย (${groupFilters.length} กลุ่ม)` }}
                  <v-icon icon="mdi-chevron-down" size="14" />
                </button>
              </template>
              <v-list density="compact" class="elaw-group-menu">
                <v-list-item
                  v-for="group in groupFilters"
                  :key="group.value"
                  @click="toggleGroupFilter(group.value)"
                >
                  <template #prepend>
                    <v-checkbox-btn :model-value="selectedGroups.includes(group.value)" density="compact" hide-details readonly />
                  </template>
                  <v-list-item-title class="text-body-2">{{ group.label }}</v-list-item-title>
                  <template #append>
                    <span class="text-caption text-medium-emphasis">{{ group.count }}</span>
                  </template>
                </v-list-item>
              </v-list>
            </v-menu>
          </div>
        </v-container>
      </section>

      <div class="elaw-stats-bar">
        <v-container style="max-width: 1280px" class="py-0">
          <div class="elaw-stats-row">
            <div v-for="stat in statsCards" :key="stat.label" class="elaw-stat-card">
              <div class="elaw-stat-card__icon-wrap" :style="{ background: `${stat.color}18`, color: stat.color }">
                <v-icon :icon="stat.icon" size="18" />
              </div>
              <div>
                <div class="elaw-stat-card__num">{{ stat.value.toLocaleString() }}</div>
                <div class="elaw-stat-card__label">{{ stat.label }}</div>
              </div>
            </div>
          </div>
        </v-container>
      </div>

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
              <div
                v-for="law in sortedResults"
                :key="law.law_id"
                class="law-list-card"
                :class="`law-list-card--${toDocType(law.law_type)}`"
                role="article"
                tabindex="0"
                @click="router.push({ name: 'law', params: { documentId: law.law_id } })"
                @keydown.enter="router.push({ name: 'law', params: { documentId: law.law_id } })"
              >
                <div class="law-list-card__body">
                  <div class="law-list-card__tags">
                    <DocBadge v-if="lawTypeBadgeKey(law.law_type)" :type="lawTypeBadgeKey(law.law_type)!" />
                    <span v-if="law.status" class="law-use-status" :class="useStatusClass(law.status)">
                      <v-icon size="9" icon="mdi-circle" />
                      {{ statusLabel(law.status) }}
                    </span>
                  </div>
                  <h3 class="law-list-card__title">{{ law.title || 'ไม่ระบุชื่อกฎหมาย' }}</h3>
                  <p v-if="law.summary" class="law-list-card__desc">{{ law.summary }}</p>
                  <div class="law-list-card__meta">
                    <span v-if="law.published_date">
                      <v-icon size="13" icon="mdi-calendar-blank-outline" />
                      นับบังคับตั้งแต่ {{ law.published_date }}
                    </span>
                    <span v-if="law.agency">
                      <v-icon size="13" icon="mdi-domain" />
                      {{ law.agency }}
                    </span>
                    <span v-if="law.signer_group">
                      <v-icon size="13" icon="mdi-folder-outline" />
                      {{ law.signer_group }}
                    </span>
                  </div>
                  <div v-if="law.snippets.length" class="law-list-card__snippets">
                    <div
                      v-for="(snippet, index) in law.snippets.slice(0, 2)"
                      :key="`${law.law_id}-${index}`"
                      class="law-snippet"
                      v-html="sanitizeHighlight(snippet)"
                    />
                  </div>
                </div>
                <div class="law-list-card__arrow">
                  <v-icon icon="mdi-chevron-right" color="#b68d40" size="22" />
                </div>
              </div>
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
import DocBadge from '../../components/shared/DocBadge.vue';
import ELawFooter from '../../components/shared/ELawFooter.vue';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import type { ChangeStatus, DocType } from '../../components/shared/lawBadge';
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

// The only law types shown in the filter, in display order (พ.ร.บ. / ประกาศ /
// ข้อบังคับ / ระเบียบ). Any other stored type (คำสั่ง, มติ, …) is hidden.
const LAW_TYPE_ORDER = ['phrb', 'prakat', 'kho-bangkhab', 'rabiap'];

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

// Build filter options from the REAL facet buckets (values actually present in
// the data), merged by a canonical key so aliases (e.g. 'phrb' / 'พ.ร.บ.' /
// 'พระราชบัญญัติ') collapse into one option with the summed count. When a
// whitelist is given the output is exactly that fixed set in order (unknown
// values hidden, missing values shown with count 0); otherwise every distinct
// canonical value present in the data is shown.
function canonicalFacetOptions(
  key: keyof Omit<LawSearchFacets, 'years'>,
  canonicalize: (value: string) => string,
  labelResolver: (value: string | null) => string,
  whitelist?: string[],
): Array<{ label: string; value: string; count: number }> {
  const counts = new Map<string, number>();
  for (const bucket of effectiveFacet(key)) {
    const canonical = canonicalize(bucket.value);
    if (!canonical || (whitelist && !whitelist.includes(canonical))) continue;
    counts.set(canonical, (counts.get(canonical) ?? 0) + bucket.count);
  }
  const values = whitelist ?? Array.from(counts.keys());
  return values.map((value) => ({ label: labelResolver(value), value, count: counts.get(value) ?? 0 }));
}

const typeFilters = computed(() => canonicalFacetOptions('law_type', canonicalLawTypeValue, lawTypeLabel, LAW_TYPE_ORDER));
const groupFilters = computed(() => mapFacetOptions(effectiveFacet('law_group')));
const agencyFilters = computed(() => mapFacetOptions(effectiveFacet('agency')));
const keeperGroupFilters = computed(() => mapFacetOptions(effectiveFacet('signer_group')));
const changeStatusFilters = computed(() => canonicalFacetOptions('change_status', (value) => value, changeStatusLabel));
const useStatusFilters = computed(() => canonicalFacetOptions('status', (value) => value, statusLabel));
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

const LAW_TYPE_TO_DOC_TYPE: Record<string, DocType> = {
  phrb: 'kotmai-krung',
  'พ.ร.บ.': 'kotmai-krung',
  พระราชบัญญัติ: 'kotmai-krung',
  prakat: 'prakat',
  ประกาศ: 'prakat',
  'kho-bangkhab': 'kho-bangkhab',
  ข้อบังคับ: 'kho-bangkhab',
  rabiap: 'rabiap',
  ระเบียบ: 'rabiap',
};

function toDocType(lawType: string | null | undefined): DocType {
  return LAW_TYPE_TO_DOC_TYPE[lawType ?? ''] ?? 'other';
}

function toChangeStatus(cs: string | null | undefined): ChangeStatus | undefined {
  if (cs === 'new' || cs === 'amended' || cs === 'repealed') return cs;
  return undefined;
}

type DocBadgeKey = 'พ.ร.บ.' | 'ระเบียบ' | 'ข้อบังคับ' | 'ประกาศ';

const LAW_TYPE_TO_BADGE: Partial<Record<string, DocBadgeKey>> = {
  phrb: 'พ.ร.บ.',
  'kho-bangkhab': 'ข้อบังคับ',
  rabiap: 'ระเบียบ',
  prakat: 'ประกาศ',
};

function lawTypeBadgeKey(lawType: string | null | undefined): DocBadgeKey | null {
  if (!lawType) return null;
  return LAW_TYPE_TO_BADGE[canonicalLawTypeValue(lawType)] ?? null;
}

function useStatusClass(status: string | null | undefined): string {
  if (status === 'active' || status === 'มีผลบังคับใช้') return 'law-use-status--active';
  if (status === 'cancelled' || status === 'ยกเลิก') return 'law-use-status--cancelled';
  return 'law-use-status--draft';
}

function toggleGroupFilter(value: string): void {
  const idx = selectedGroups.value.indexOf(value);
  if (idx >= 0) selectedGroups.value.splice(idx, 1);
  else selectedGroups.value.push(value);
}

const statsCards = computed(() => {
  const f = baseFacets.value;
  const csMap = new Map((f?.change_status ?? []).map((b) => [b.value, b.count]));
  return [
    { label: 'กฎหมายทั้งหมด', icon: 'mdi-file-document-multiple-outline', color: '#3b82f6', value: searchStore.total },
    { label: 'กฎหมายใหม่', icon: 'mdi-plus-circle-outline', color: '#10b981', value: csMap.get('new') ?? 0 },
    { label: 'ปรับปรุง', icon: 'mdi-pencil-outline', color: '#6366f1', value: csMap.get('amended') ?? 0 },
    { label: 'ยกเลิก', icon: 'mdi-close-circle-outline', color: '#ef4444', value: csMap.get('repealed') ?? 0 },
  ];
});

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
  border-bottom: 1px solid #eadfcb;
  padding: 28px 24px 34px;
}

.elaw-db-header__title {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 32px;
  font-weight: 700;
  color: #1f1b14;
  margin: 0;
}

.elaw-db-header__sub {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 18px;
  color: #4e4538;
}

.elaw-db-search-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: #343028;
  border: none;
  cursor: pointer;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 20px;
  font-weight: 700;
  color: #ffffff;
  padding: 14px 28px;
  border-radius: 12px;
  white-space: nowrap;
  flex-shrink: 0;
}

.elaw-type-pill {
  background: #ffffff;
  border: 1px solid #d2c5b3;
  border-radius: 9999px;
  padding: 7px 18px;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-weight: 700;
  font-size: 16px;
  color: #3c2900;
  cursor: pointer;
  white-space: nowrap;
  transition: background-color 0.15s ease, border-color 0.15s ease;
}

.elaw-type-pill:focus-visible {
  outline: 2px solid #b68d40;
  outline-offset: 2px;
}

.elaw-type-pill--active {
  background: #b68d40;
  border-color: #b68d40;
  color: #3c2900;
}

.elaw-type-pill__count {
  font-size: 13px;
  font-weight: 400;
  opacity: 0.8;
}

.elaw-filter-row {
  min-height: 44px;
}

.elaw-filter-row__label {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px;
  font-weight: 700;
  color: #7b580d;
  white-space: nowrap;
}

.law-result-wrapper {
  cursor: pointer;
}

.law-snippets-row {
  margin-top: -12px;
  padding: 0 28px 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;
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

/* ── Stats bar ── */
.elaw-stats-bar {
  background: #ffffff;
  border-bottom: 1px solid #e7e2d9;
  padding: 14px 0;
}

.elaw-stats-row {
  display: flex;
  gap: 0;
  flex-wrap: wrap;
}

.elaw-stat-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 20px;
  border-right: 1px solid #f0ede8;
  flex: 1 1 160px;
}

.elaw-stat-card:last-child {
  border-right: none;
}

.elaw-stat-card__icon-wrap {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.elaw-stat-card__num {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 22px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1.1;
}

.elaw-stat-card__label {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 13px;
  color: #64748b;
}

/* ── List result cards ── */
.law-list-card {
  display: flex;
  align-items: stretch;
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  border-left: 4px solid #9e9e9e;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
  cursor: pointer;
  transition: box-shadow 0.15s ease;
  overflow: hidden;
}

.law-list-card:hover {
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.10);
}

.law-list-card--kotmai-krung { border-left-color: #854d0e; }
.law-list-card--rabiap       { border-left-color: #3b82f6; }
.law-list-card--kho-bangkhab { border-left-color: #10b981; }
.law-list-card--prakat       { border-left-color: #fb923c; }

.law-list-card__body {
  flex: 1;
  padding: 18px 22px;
  min-width: 0;
}

.law-list-card__tags {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}

.law-use-status {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-family: 'Sarabun', sans-serif;
  font-size: 13px;
  padding: 2px 9px;
  border-radius: 9999px;
  background: #f0fdf4;
  color: #15803d;
  border: 1px solid #bbf7d0;
}

.law-use-status--cancelled {
  background: #fff1f2;
  color: #be123c;
  border-color: #fecdd3;
}

.law-use-status--draft {
  background: #f8fafc;
  color: #64748b;
  border-color: #e2e8f0;
}

.law-list-card__title {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 18px;
  font-weight: 700;
  color: #1e293b;
  margin: 0 0 6px;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.law-list-card__desc {
  font-family: 'Sarabun', sans-serif;
  font-size: 14px;
  color: #4b5563;
  margin: 0 0 10px;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.law-list-card__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  font-family: 'Sarabun', sans-serif;
  font-size: 13px;
  color: #6b7280;
  align-items: center;
}

.law-list-card__meta span {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.law-list-card__snippets {
  margin-top: 10px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.law-list-card__arrow {
  display: flex;
  align-items: center;
  padding: 0 18px;
  flex-shrink: 0;
}

/* ── Group dropdown pill ── */
.elaw-group-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.elaw-group-menu {
  min-width: 320px;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
}
</style>
