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

          <div class="d-flex flex-column flex-md-row ga-3 align-stretch align-md-center">
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
              >
                <template #prepend-inner>
                  <v-icon icon="mdi-magnify" size="18" color="#3c2900" />
                </template>
                <template #append-inner>
                  <button
                    type="button"
                    class="elaw-search-input__button"
                    @mousedown.prevent
                    @click.stop="doSearch"
                  >
                    <v-icon icon="mdi-magnify" size="18" />
                    ค้นหาข้อมูล
                  </button>
                </template>
              </v-text-field>
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

            <span class="elaw-filter-row__label elaw-filter-row__label--group">กลุ่มกฎหมาย:</span>
            <v-menu :close-on-content-click="false" max-height="320">
              <template #activator="{ props: menuProps }">
                <button type="button" class="elaw-type-pill elaw-group-pill" v-bind="menuProps">
                  {{ selectedGroups.length > 0 ? `${selectedGroups.length} กลุ่มที่เลือก` : `${groupFilters.length} กลุ่ม` }}
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
                bg-color="white"
                class="elaw-sort-select"
                style="max-width: 220px"
              />
            </div>

            <div v-if="searchStore.loading" class="d-flex justify-center py-10">
              <v-progress-circular indeterminate color="primary" />
            </div>

            <div v-else-if="sortedResults.length === 0" class="law-empty-state">
              <v-icon icon="mdi-file-search-outline" size="28" color="medium-emphasis" />
              <p class="text-body-2 text-medium-emphasis mb-0">ไม่พบเอกสาร</p>
              <div v-if="nearbySearchTerms.length > 0" class="law-empty-suggestions">
                <span class="law-empty-suggestions__label">ลองค้นหาคำใกล้เคียง:</span>
                <button
                  v-for="term in nearbySearchTerms.slice(0, 5)"
                  :key="term"
                  type="button"
                  class="law-empty-suggestions__item"
                  @click="applySearchTerm(term)"
                >
                  {{ term }}
                </button>
              </div>
            </div>

            <div v-else class="law-results-list">
              <v-alert
                v-if="isFuzzyResults"
                type="info"
                variant="tonal"
                density="compact"
                class="mb-1"
                icon="mdi-magnify-scan"
              >
                ไม่พบผลลัพธ์ที่ตรงกับ "{{ query }}" — แสดงผลใกล้เคียงที่พบแทน
              </v-alert>
              <div
                v-for="law in sortedResults"
                :key="law.law_id"
                class="law-list-card"
                :class="`law-list-card--${toDocType(law.law_type)}`"
                role="article"
                tabindex="0"
                @click="openLaw(law)"
                @keydown.enter="openLaw(law)"
              >
                <div class="law-list-card__body">
                  <div class="law-list-card__tags">
                    <DocBadge v-if="lawTypeBadgeKey(law.law_type)" :type="lawTypeBadgeKey(law.law_type)!" />
                    <span v-if="law.status" class="law-use-status" :class="useStatusClass(law.status)">
                      <v-icon size="9" icon="mdi-circle" />
                      {{ statusLabel(law.status) }}
                    </span>
                    <span
                      v-if="cardChangeState(law.change_status, law.status).variant !== 'new'"
                      class="law-change-tag"
                      :class="`law-change-tag--${cardChangeState(law.change_status, law.status).variant}`"
                    >{{ cardChangeState(law.change_status, law.status).label }}</span>
                  </div>
                  <h3
                    class="law-list-card__title"
                    v-html="law.title_highlighted ? sanitizeHighlight(law.title_highlighted) : (law.title || 'ไม่ระบุชื่อกฎหมาย')"
                  />
                  <p class="law-list-card__desc" :class="{ 'law-list-card__desc--empty': !law.summary }">
                    {{ law.summary || 'ไม่มีสรุปข้อมูล' }}
                  </p>
                  <div class="law-list-card__meta">
                    <span v-if="law.published_date">
                      <span class="mdi mdi-calendar" />
                      นับบังคับตั้งแต่ {{ formatThaiDate(law.published_date) || law.published_date }}
                    </span>
                    <span v-if="law.agency">
                      <v-icon size="13" icon="mdi-domain" />
                      {{ law.agency }}
                    </span>
                    <span v-if="law.law_group">
                      <v-icon size="13" icon="mdi-sitemap-outline" />
                      {{ law.law_group }}
                    </span>
                    <span v-if="law.signer_group">
                      <v-icon size="13" icon="mdi-folder-outline" />
                      {{ law.signer_group }}
                    </span>
                  </div>
                  <div v-if="law.keywords?.length" class="law-list-card__keywords">
                    <v-icon size="12" icon="mdi-tag-outline" class="mr-1" />
                    <v-chip
                      v-for="kw in law.keywords.slice(0, 5)"
                      :key="kw"
                      size="x-small"
                      variant="tonal"
                      color="primary"
                      rounded="pill"
                      class="law-keyword-chip"
                    >
                      <span v-html="highlightKeyword(kw)"></span>
                    </v-chip>
                  </div>
                  <div class="law-list-card__children" :class="{ 'law-list-card__children--empty': childChips(law).length === 0 }">
                    <span v-if="childChips(law).length" class="law-list-card__children-label">
                      <v-icon size="12" icon="mdi-link-variant" />
                      เอกสารที่อ้างถึง
                    </span>
                    <span
                      v-for="chip in childChips(law)"
                      :key="chip.type"
                      class="law-child-chip"
                      :class="`law-child-chip--${chip.type}`"
                    >
                      {{ chip.label }} {{ chip.count }}
                    </span>
                  </div>
                  <div class="law-list-card__snippets" :class="{ 'law-list-card__snippets--empty': law.snippets.length === 0 }">
                    <div
                      v-for="(snippet, index) in law.snippets.slice(0, 2)"
                      :key="`${law.law_id}-${index}`"
                      class="law-snippet"
                      v-html="sanitizeHighlight(snippet)"
                    />
                  </div>
                </div>
                <div v-if="law.restricted" class="law-list-card__restricted">
                  <v-icon icon="mdi-lock-outline" size="16" />
                  <span class="law-list-card__restricted-label">Private · เฉพาะผู้ได้รับสิทธิ์</span>
                  <button type="button" class="law-restricted-btn" @click.stop="openLaw(law)">
                    {{ auth.isAuthenticated ? 'เปิดเอกสาร' : 'เข้าสู่ระบบเพื่อดูเอกสาร' }}
                    <v-icon size="14" icon="mdi-arrow-right" />
                  </button>
                </div>
                <div v-else class="law-list-card__arrow">
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
import { fetchLawFacets, getLookups, type LookupData } from '../../api/client';
import DocBadge from '../../components/shared/DocBadge.vue';
import ELawFooter from '../../components/shared/ELawFooter.vue';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import {
  LAW_TYPE_TO_BADGE,
  LAW_TYPE_TO_DOC_TYPE,
  type ChangeStatus,
  type LawTypeBadge,
  type LawTypeCardClass,
} from '../../components/shared/lawBadge';
import { useAuthStore } from '../../stores/authStore';
import { useLawSearchStore } from '../../stores/lawSearchStore';
import type { FacetBucket, LawSearchFacets, LawSearchFilters, LawSearchResult, LawSuggestion } from '../../types/lawSearch';
import { sanitizeHighlight } from '../../utils/highlightSanitizer';
import { cardChangeState } from '../../utils/cardChangeState';
import { formatThaiDate } from '../../utils/thaiDate';

const PER_PAGE = 20;

const LAW_TYPE_LABELS: Record<string, string> = {
  phrb: 'กฎหมายภายนอก',
  'พ.ร.บ.': 'กฎหมายภายนอก',
  พระราชบัญญัติ: 'กฎหมายภายนอก',
  'kotmai-krung': 'กฎหมายภายนอก',
  'kotmai-phaainok': 'กฎหมายภายนอก',
  กฎหมายภายนอก: 'กฎหมายภายนอก',
  'kho-bangkhab': 'ข้อบังคับ',
  ข้อบังคับ: 'ข้อบังคับ',
  rabiap: 'ระเบียบ',
  ระเบียบ: 'ระเบียบ',
  prakat: 'ประกาศ',
  ประกาศ: 'ประกาศ',
  command: 'ประกาศ',
  คำสั่ง: 'ประกาศ',
  resolution: 'ประกาศ',
  มติ: 'ประกาศ',
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
  มีผลใช้บังคับ: 'มีผลบังคับใช้',
  ใช้บังคับ: 'มีผลบังคับใช้',
  บังคับใช้: 'มีผลบังคับใช้',
  cancelled: 'ยกเลิก',
  ยกเลิก: 'ยกเลิก',
  draft: 'ร่าง',
  ร่าง: 'ร่าง',
};

const LAW_TYPE_CANONICAL_VALUES: Record<string, string> = {
  phrb: 'kotmai-phaainok',
  'พ.ร.บ.': 'kotmai-phaainok',
  พระราชบัญญัติ: 'kotmai-phaainok',
  'kotmai-krung': 'kotmai-phaainok',
  'kotmai-phaainok': 'kotmai-phaainok',
  กฎหมายภายนอก: 'kotmai-phaainok',
  'kho-bangkhab': 'kho-bangkhab',
  ข้อบังคับ: 'kho-bangkhab',
  rabiap: 'rabiap',
  ระเบียบ: 'rabiap',
  prakat: 'prakat',
  ประกาศ: 'prakat',
  command: 'prakat',
  คำสั่ง: 'prakat',
  resolution: 'prakat',
  มติ: 'prakat',
};

const LAW_TYPE_FILTER_ALIASES: Record<string, string[]> = {
  'kotmai-phaainok': ['kotmai-phaainok', 'kotmai-krung', 'phrb', 'พ.ร.บ.', 'พระราชบัญญัติ', 'กฎหมายภายนอก'],
  'kho-bangkhab': ['kho-bangkhab', 'ข้อบังคับ'],
  rabiap: ['rabiap', 'ระเบียบ'],
  prakat: ['prakat', 'ประกาศ', 'command', 'คำสั่ง', 'resolution', 'มติ', 'ประกาศที่ออกโดยมหาวิทยาลัย', 'ประกาศที่ออกโดยสภามหาวิทยาลัย'],
};

const CHILD_CHIP_LABELS: Record<string, string> = {
  'kotmai-phaainok': 'กฎหมายภายนอก',
  'kho-bangkhab': 'ข้อบังคับ',
  rabiap: 'ระเบียบ',
  prakat: 'ประกาศ',
  other: 'อื่น ๆ',
};

const LAW_TYPE_ORDER = ['kotmai-phaainok', 'prakat', 'kho-bangkhab', 'rabiap'];

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
const auth = useAuthStore();
const baseFacets = ref<LawSearchFacets | null>(null);
const lookupFacets = ref<LawSearchFacets | null>(null);

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
// Near-word results: the search fell back to fuzzy matching (no exact hit).
const isFuzzyResults = computed(() =>
  query.value.trim() !== ''
  && searchStore.meta.mode.includes('fuzzy')
  && searchStore.results.length > 0,
);
const showSuggestions = computed(() => searchFocused.value && query.value.trim().length >= 2 && (searchStore.suggesting || searchStore.suggestions.length > 0));
const nearbySearchTerms = computed(() => {
  const fromMeta = searchStore.meta.suggestions
    .map((term) => term.trim())
    .filter(Boolean);
  if (fromMeta.length > 0) {
    return uniqueStrings(fromMeta);
  }

  return uniqueStrings(searchStore.suggestions
    .flatMap((suggestion) => [suggestion.title ?? '', ...suggestion.keywords])
    .map((term) => term.trim())
    .filter(Boolean));
});

function effectiveFacet(key: keyof Omit<LawSearchFacets, 'years'>): FacetBucket[] {
  const fromSearch = searchStore.facets[key];
  const counted = fromSearch.length > 0 ? fromSearch : (baseFacets.value?.[key] ?? []);
  return mergeFacetBuckets(lookupFacets.value?.[key] ?? [], counted);
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
    if (suggestTimer) {
      clearTimeout(suggestTimer);
      suggestTimer = null;
    }
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

function applySearchTerm(term: string): void {
  if (hideSuggestionsTimer) {
    clearTimeout(hideSuggestionsTimer);
  }
  mutatingSearchState = true;
  query.value = term;
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
  if (searchStore.total === 0 && query.value.trim().length >= 2) {
    await searchStore.suggest(query.value, 5);
  }
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

function mergeFacetBuckets(baseline: FacetBucket[], counted: FacetBucket[]): FacetBucket[] {
  const byValue = new Map<string, number>();
  const order: string[] = [];

  for (const bucket of baseline) {
    if (!byValue.has(bucket.value)) {
      order.push(bucket.value);
    }
    byValue.set(bucket.value, 0);
  }

  for (const bucket of counted) {
    if (!byValue.has(bucket.value)) {
      order.push(bucket.value);
    }
    byValue.set(bucket.value, bucket.count);
  }

  return order.map((value) => ({ value, count: byValue.get(value) ?? 0 }));
}

function lookupBuckets(options: LookupData[keyof LookupData]): FacetBucket[] {
  return options.map((option) => ({ value: option.value, count: 0 }));
}

function lookupDataToFacets(data: LookupData): LawSearchFacets {
  return {
    law_type: lookupBuckets(data.document_types),
    status: lookupBuckets(data.statuses),
    change_status: lookupBuckets(data.change_status_types),
    agency: lookupBuckets(data.agencies),
    law_group: lookupBuckets(data.law_groups),
    signer_group: [],
    years: [],
  };
}

function canonicalLawTypeValue(value: string): string {
  if (LAW_TYPE_CANONICAL_VALUES[value]) return LAW_TYPE_CANONICAL_VALUES[value];
  if (value.includes('ประกาศ')) return 'prakat';
  return value;
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

function toDocType(lawType: string | null | undefined): LawTypeCardClass {
  const raw = lawType ?? '';
  return LAW_TYPE_TO_DOC_TYPE[raw]
    ?? LAW_TYPE_TO_DOC_TYPE[canonicalLawTypeValue(raw)]
    ?? (raw.includes('ประกาศ') ? 'prakat' : 'other');
}

function childChips(law: LawSearchResult): Array<{ type: string; label: string; count: number }> {
  const types = law.child_types ?? {};
  return Object.entries(types)
    .filter(([, count]) => count > 0)
    .map(([type, count]) => {
      const canonical = canonicalLawTypeValue(type);
      return { type: canonical, label: CHILD_CHIP_LABELS[canonical] ?? type, count };
    });
}

function highlightKeyword(kw: string): string {
  const q = query.value.trim();
  if (!q) return sanitizeHighlight(kw);

  const escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return sanitizeHighlight(kw.replace(new RegExp(`(${escaped})`, 'gi'), '<mark>$1</mark>'));
}

function openLaw(law: LawSearchResult): void {
  const lawPath = `/law/${encodeURIComponent(law.law_id)}`;
  if (law.restricted && !auth.isAuthenticated) {
    router.push({ path: '/login', query: { redirect: lawPath } });
    return;
  }

  router.push(lawPath);
}

function toChangeStatus(cs: string | null | undefined): ChangeStatus | undefined {
  if (cs === 'new' || cs === 'amended' || cs === 'repealed') return cs;
  return undefined;
}

function lawTypeBadgeKey(lawType: string | null | undefined): LawTypeBadge | null {
  if (!lawType) return null;
  return LAW_TYPE_TO_BADGE[lawType] ?? LAW_TYPE_TO_BADGE[canonicalLawTypeValue(lawType)] ?? null;
}

function useStatusClass(status: string | null | undefined): string {
  if (status === 'active' || status === 'มีผลบังคับใช้' || status === 'มีผลใช้บังคับ' || status === 'ใช้บังคับ' || status === 'บังคับใช้') return 'law-use-status--active';
  if (status === 'cancelled' || status === 'ยกเลิก') return 'law-use-status--cancelled';
  return 'law-use-status--draft';
}

function toggleGroupFilter(value: string): void {
  const idx = selectedGroups.value.indexOf(value);
  if (idx >= 0) selectedGroups.value.splice(idx, 1);
  else selectedGroups.value.push(value);
}

let refreshTimer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
  fetchLawFacets().then((f) => { baseFacets.value = f; }).catch(() => { /* non-fatal */ });
  getLookups().then((lookups) => { lookupFacets.value = lookupDataToFacets(lookups); }).catch(() => { /* non-fatal */ });
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
  position: relative;
  z-index: 40;
  background: linear-gradient(180deg, #f8f7f1 8%, #fff4b5 100%);
  border-bottom: 1px solid #eadfcb;
  padding: 28px 24px 34px;
  overflow: visible;
}

.elaw-db-header__title {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 28px;
  font-weight: 700;
  color: #1f1b14;
  margin: 0;
}

.elaw-db-header__sub {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 16px;
  color: #4e4538;
}

.elaw-search-input__button {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: #343028;
  border: none;
  cursor: pointer;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 16px;
  font-weight: 700;
  line-height: 1;
  color: #ffffff;
  min-height: 42px;
  padding: 0 22px;
  border-radius: 9999px;
  white-space: nowrap;
  flex-shrink: 0;
}

.elaw-type-pill {
  background: #ffffff;
  border: 1px solid #d2c5b3;
  border-radius: 9999px;
  padding: 6px 16px;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-weight: 700;
  font-size: 14px;
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
  color: #ffffff;
}

.elaw-type-pill__count {
  font-size: 12px;
  font-weight: 400;
  opacity: 0.8;
}

.elaw-type-pill--active .elaw-type-pill__count {
  color: #ffffff;
  opacity: 0.9;
}

.elaw-filter-row {
  min-height: 44px;
}

.elaw-filter-row__label {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 14px;
  font-weight: 700;
  color: #7b580d;
  white-space: nowrap;
}

.elaw-filter-row__label--group {
  margin-left: 8px;
}

.elaw-sort-select :deep(.v-field) {
  background: #ffffff;
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
  font-size: 16px;
  min-height: 54px;
  padding-top: 8px;
  padding-bottom: 8px;
}

.elaw-search-input :deep(.v-field__append-inner) {
  align-items: center;
  padding-inline-start: 10px;
}

.elaw-search-shell {
  position: relative;
  z-index: 100;
  overflow: visible;
}

.elaw-suggest-card {
  position: absolute;
  top: calc(100% + 8px);
  left: 0;
  right: 0;
  z-index: 4000;
  overflow: hidden;
  border-radius: 12px !important;
  background: rgb(var(--v-theme-detail-surface));
  box-shadow: 0 16px 42px rgba(75, 70, 61, 0.16) !important;
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
  padding: 28px;
  border: 1px dashed rgba(171, 127, 41, 0.28);
  border-radius: 12px;
  background: rgba(255, 250, 236, 0.55);
}

.law-empty-suggestions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 8px;
  max-width: 100%;
}

.law-empty-suggestions__label {
  color: #7b580d;
  font-size: 14px;
  font-weight: 700;
}

.law-empty-suggestions__item {
  max-width: 260px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  border: 1px solid #d2c5b3;
  border-radius: 9999px;
  background: #ffffff;
  color: #3c2900;
  cursor: pointer;
  font-size: 14px;
  font-weight: 700;
  padding: 7px 14px;
}

.law-empty-suggestions__item:hover {
  border-color: #b68d40;
  color: #7b580d;
}

/* ── List result cards ── */
.law-change-tag {
  display: inline-flex;
  align-items: center;
  border-radius: 9999px;
  padding: 2px 10px;
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
}
.law-change-tag--revise    { background: #f5f3ff; color: #6d28d9; }
.law-change-tag--partial   { background: #fffbeb; color: #b45309; }
.law-change-tag--cancelled { background: #fef2f2; color: #b91c1c; }

.law-results-list {
  display: grid;
  gap: 12px;
}

.law-list-card {
  display: flex;
  align-items: stretch;
  min-height: 0;
  height: auto;
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  border-left: 4px solid #9e9e9e;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
  cursor: pointer;
  transition: box-shadow 0.15s ease;
  overflow: visible;
}

.law-list-card:hover {
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.10);
}

.law-list-card--kotmai-phaainok { border-left-color: #854d0e; }
.law-list-card--rabiap       { border-left-color: #3b82f6; }
.law-list-card--kho-bangkhab { border-left-color: #10b981; }
.law-list-card--prakat       { border-left-color: #fb923c; }
.law-list-card--prb          { border-left-color: #7c3aed; }
.law-list-card--phrk         { border-left-color: #6d28d9; }
.law-list-card--kotmai-krw   { border-left-color: #2563eb; }
.law-list-card--prakat-krw   { border-left-color: #0369a1; }
.law-list-card--command      { border-left-color: #64748b; }
.law-list-card--resolution   { border-left-color: #475569; }

.law-list-card__body {
  display: grid;
  grid-template-rows: auto;
  row-gap: 8px;
  flex: 1;
  padding: 18px 22px;
  min-width: 0;
  overflow: visible;
}

.law-list-card__tags {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  min-width: 0;
  overflow: visible;
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
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 18px;
  font-weight: 700;
  color: #1e293b;
  margin: 0;
  line-height: 1.4;
  overflow-wrap: anywhere;
}

.law-list-card__desc {
  font-family: 'Sarabun', sans-serif;
  font-size: 14px;
  color: #4b5563;
  margin: 0;
  line-height: 1.5;
  overflow-wrap: anywhere;
}

.law-list-card__desc--empty {
  visibility: hidden;
}

.law-list-card__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 16px;
  font-family: 'Sarabun', sans-serif;
  font-size: 13px;
  color: #6b7280;
  align-items: center;
  overflow: visible;
}

.law-list-card__meta span {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  max-width: 100%;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.law-list-card__keywords {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 6px;
}

.law-keyword-chip {
  font-size: 11px !important;
}

.law-list-card__snippets {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-height: auto;
  overflow: visible;
}

.law-list-card__snippets--empty {
  visibility: hidden;
}

.law-list-card__children {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  min-width: 0;
  overflow: visible;
}

.law-list-card__children--empty {
  visibility: hidden;
}

.law-list-card__children-label {
  font-size: 12px;
  color: #6b7280;
  display: inline-flex;
  align-items: center;
  gap: 3px;
}

.law-child-chip {
  font-size: 12px;
  padding: 2px 8px;
  border-radius: 9999px;
  background: #f3f4f6;
  color: #374151;
}

.law-child-chip--kotmai-phaainok {
  background: #fef3c7;
  color: #92400e;
}

.law-child-chip--rabiap {
  background: #dbeafe;
  color: #1e40af;
}

.law-child-chip--kho-bangkhab {
  background: #d1fae5;
  color: #065f46;
}

.law-child-chip--prakat {
  background: #ffedd5;
  color: #9a3412;
}

.law-list-card__arrow {
  display: flex;
  align-items: center;
  padding: 0 18px;
  flex-shrink: 0;
}

.law-list-card__restricted {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 18px;
  flex-shrink: 0;
  color: #6b7280;
}

.law-list-card__restricted-label {
  font-size: 13px;
  white-space: nowrap;
}

.law-restricted-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #b68d40;
  color: #fff;
  border: none;
  border-radius: 9999px;
  padding: 8px 16px;
  font-size: 13px;
  cursor: pointer;
  white-space: nowrap;
}

@media (max-width: 760px) {
  .elaw-search-input__button {
    gap: 5px;
    min-height: 38px;
    padding: 0 14px;
    font-size: 16px;
  }

  .law-list-card {
    flex-direction: column;
    height: auto;
    min-height: 214px;
  }

  .law-list-card__body {
    grid-template-rows: auto;
  }

  .law-list-card__restricted,
  .law-list-card__arrow {
    justify-content: flex-end;
    padding: 0 18px 16px;
  }

  .law-list-card__restricted {
    align-items: flex-start;
    flex-wrap: wrap;
  }
}

/* ── Group dropdown pill ── */
.elaw-group-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.elaw-group-menu {
  min-width: 320px;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
}
</style>
