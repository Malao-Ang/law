<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import FilterTypeBadge from './FilterTypeBadge.vue';
import { useLawSearchStore } from '../../stores/lawSearchStore';
import { sanitizeHighlight } from '../../utils/highlightSanitizer';
import type { LawSuggestion } from '../../types/lawSearch';

const emit = defineEmits<{
  search: [query: string, types: string[], groups: string[]];
}>();

const query = ref('');
const selectedType = ref('ทั้งหมด');
const selectedGroups = ref<string[]>([]);
const queryInput = ref<{ focus?: () => void } | null>(null);

// Live near-word suggestions (the suggest endpoint already falls back to fuzzy).
const searchStore = useLawSearchStore();
const searchFocused = ref(false);
let suggestTimer: ReturnType<typeof setTimeout> | null = null;
let hideTimer: ReturnType<typeof setTimeout> | null = null;

const showSuggestions = computed(() =>
  searchFocused.value
  && query.value.trim().length >= 2
  && (searchStore.suggesting || searchStore.suggestions.length > 0),
);

function onQueryInput(): void {
  if (suggestTimer) clearTimeout(suggestTimer);
  if (query.value.trim().length < 2) {
    searchStore.clearSuggestions();
    return;
  }
  suggestTimer = setTimeout(() => void searchStore.suggest(query.value), 300);
}

function onQueryFocus(): void {
  searchFocused.value = true;
}

function onQueryBlur(): void {
  hideTimer = setTimeout(() => { searchFocused.value = false; }, 150);
}

function pickSuggestion(suggestion: LawSuggestion): void {
  if (hideTimer) clearTimeout(hideTimer);
  query.value = suggestion.title ?? query.value;
  searchFocused.value = false;
  searchStore.clearSuggestions();
  emitSearch();
}

function escapeRegExp(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function highlightTitle(text: string | null): string {
  const value = text ?? '';
  const q = query.value.trim();
  if (q === '') {
    return sanitizeHighlight(value);
  }

  return sanitizeHighlight(value.replace(new RegExp(`(${escapeRegExp(q)})`, 'giu'), '<mark>$1</mark>'));
}

onBeforeUnmount(() => {
  if (suggestTimer) clearTimeout(suggestTimer);
  if (hideTimer) clearTimeout(hideTimer);
  searchStore.clearSuggestions();
});

const typeOptions = ['ทั้งหมด', 'ข้อบังคับ', 'ระเบียบ', 'ประกาศ', 'กฎหมายภายนอก', 'พระราชบัญญัติ', 'พระราชกำหนด', 'กฎกระทรวง', 'ประกาศกระทรวง'];

const typeToValue: Record<string, string> = {
  'กฎหมายภายนอก': 'kotmai-phaainok',
  'พระราชบัญญัติ': 'พระราชบัญญัติ',
  'พระราชกำหนด': 'พระราชกำหนด',
  'กฎกระทรวง': 'กฎกระทรวง',
  'ประกาศกระทรวง': 'ประกาศกระทรวง',
  'ข้อบังคับ': 'kho-bangkhab',
  'ระเบียบ': 'rabiap',
  'ประกาศ': 'prakat',
};

const groupFilters = [
  { label: 'ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร', value: 'ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร' },
  { label: 'ด้านกิจการนิสิต', value: 'ด้านกิจการนิสิต' },
  { label: 'ด้านการวิจัย นวัตกรรม และการนำไปใช้ประโยชน์', value: 'ด้านการวิจัย นวัตกรรม และการนำไปใช้ประโยชน์' },
  { label: 'ด้านบริการวิชาการ', value: 'ด้านบริการวิชาการ' },
  { label: 'ด้านโครงสร้างองค์กรและระบบการบริหาร', value: 'ด้านโครงสร้างองค์กรและระบบการบริหาร' },
  { label: 'ด้านการบริหารงานบุคคล สิทธิประโยชน์ วินัยและจรรยาบรรณ', value: 'ด้านการบริหารงานบุคคล สิทธิประโยชน์ วินัยและจรรยาบรรณ' },
  { label: 'ด้านการเงินและทรัพย์สิน พัสดุ การตรวจสอบ และการบริหารความเสี่ยง', value: 'ด้านการเงินและทรัพย์สิน พัสดุ การตรวจสอบ และการบริหารความเสี่ยง' },
  { label: 'ด้านอื่น ๆ', value: 'ด้านอื่น ๆ' },
];

const popularTags = ['อัตราเบิกค่าใช้จ่ายเดินทาง', 'กองทุนสร้างเสริมสุขภาพ', 'โครงสร้างสถาบันวิจัย'];

function emitSearch(): void {
  const types = selectedType.value === 'ทั้งหมด' ? [] : [typeToValue[selectedType.value] ?? selectedType.value];
  emit('search', query.value, types, selectedGroups.value);
}

async function applyPopularTag(tag: string): Promise<void> {
  query.value = tag;
  await nextTick();
  queryInput.value?.focus?.();
}
</script>

<template>
  <section class="elaw-hero">
    <v-container class="elaw-hero__container" style="max-width: 980px">
      <div class="d-flex justify-center">
        <div class="elaw-hero__eyebrow">
          แพลตฟอร์มสืบค้นกฎหมายสำหรับทุกคน
        </div>
      </div>

      <h1 class="text-center font-weight-black mb-3 elaw-hero__title">
        <span class="elaw-hero__title-accent ">ค้นหาและเข้าถึงข้อมูลกฎหมาย</span>
        <br>
        ได้อย่างสะดวกรวดเร็ว
      </h1>
      <p class="text-center mb-7 elaw-hero__subtitle">
        อัปเดตข้อมูลล่าสุด รวบรวมกฎหมายภายนอก ระเบียบ ข้อบังคับ และประกาศต่าง ๆ ไว้ในที่เดียว
      </p>

      <v-card flat rounded="xl" class="pa-5 pa-md-6 elaw-search-card">
        <div class="d-flex ga-6 mb-4 align-start flex-wrap">
          <div class="flex-shrink-0">
            <p class="elaw-search-card__label mb-2">1. ประเภทเอกสาร:</p>
            <FilterTypeBadge v-model="selectedType" :options="typeOptions" />
          </div>
          <div style="min-width: 220px; flex: 1 1 220px">
            <p class="elaw-search-card__label mb-2">2. กลุ่มกฎหมาย:</p>
            <v-select
              v-model="selectedGroups"
              :items="groupFilters"
              item-title="label"
              item-value="value"
              label="เลือกได้หลายกลุ่ม"
              variant="outlined"
              density="compact"
              rounded="xl"
              hide-details
              multiple
              chips
              closable-chips
              bg-color="detail-surface"
            />
          </div>
        </div>

        <div class="mb-4">
          <div class="elaw-search-card__search-row">
            <v-text-field
              ref="queryInput"
              v-model="query"
              placeholder="พิมพ์ชื่อกฎหมาย, เลขที่ประกาศ, หรือคำสำคัญ..."
              density="comfortable"
              hide-details
              variant="outlined"
              rounded="xl"
              bg-color="detail-surface"
              class="elaw-search-card__query"
              @keydown.enter="emitSearch"
              @focus="onQueryFocus"
              @blur="onQueryBlur"
              @update:model-value="onQueryInput"
            >
              <template #prepend-inner>
                <v-icon icon="mdi-magnify" size="18" color="#3c2900" />
              </template>
              <template #append-inner>
                <v-btn
                  color="#343028"
                  variant="flat"
                  rounded="pill"
                  size="default"
                  class="elaw-search-card__search-btn"
                  @click="emitSearch"
                >
                  <v-icon icon="mdi-magnify" size="15" start />
                  ค้นหาทันที
                </v-btn>
              </template>
            </v-text-field>

            <v-card v-if="showSuggestions" class="elaw-hero-suggest" flat border>
              <div v-if="searchStore.suggesting" class="elaw-hero-suggest__status">
                <v-progress-circular indeterminate size="14" width="2" />
                กำลังแนะนำคำค้น...
              </div>
              <div v-else-if="searchStore.suggestions.length === 0" class="elaw-hero-suggest__status">
                ไม่พบคำแนะนำเพิ่มเติม
              </div>
              <button
                v-for="suggestion in searchStore.suggestions"
                :key="suggestion.law_id"
                type="button"
                class="elaw-hero-suggest__item"
                @mousedown.prevent="pickSuggestion(suggestion)"
              >
                <span class="elaw-hero-suggest__title" v-html="highlightTitle(suggestion.title)" />
                <span class="elaw-hero-suggest__meta">
                  {{ suggestion.agency || 'ไม่ระบุหน่วยงาน' }}<template v-if="suggestion.published_date"> · {{ suggestion.published_date }}</template>
                </span>
              </button>
            </v-card>
          </div>
        </div>

        <div class="elaw-search-card__tags">
          <div class="elaw-search-card__tags-label">
            <v-icon size="14" icon="mdi-arrow-top-right" />
            <span>คำค้นหายอดนิยม</span>
          </div>
          <div class="d-flex flex-wrap ga-2">
            <v-chip
              v-for="tag in popularTags"
              :key="tag"
              rounded="pill"
              variant="outlined"
              class="elaw-search-card__tag"
              @click="applyPopularTag(tag)"
            >
              {{ tag }}
            </v-chip>
          </div>
        </div>
      </v-card>
    </v-container>
  </section>
</template>

<style scoped>
/* ── Hero section ──────────────────────────────────── */
.elaw-hero {
  position: relative;
  z-index: 30;
  background: linear-gradient(180deg, #f8f7f1 8.17%, #fff4b5 100%);
  border-bottom: 1px solid #eadfcb;
  padding: 50px 24px 60px;
  overflow: visible;
}

.elaw-hero__container {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  overflow: visible;
}

/* Eyebrow pill — exact Figma: white bg, border rgba(123,88,13,0.3), text #7b580d 12px */
.elaw-hero__eyebrow {
  display: inline-flex;
  align-items: center;
  padding: 5px 13px;
  border: 1px solid rgba(123, 88, 13, 0.3);
  border-radius: 9999px;
  background: #ffffff;
  color: #7b580d;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 12px;
  font-weight: 700;
  margin-bottom: 16px;
}

.elaw-hero__title {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: clamp(32px, 3.2vw, 46px);
  font-weight: 700;
  line-height: 1.38;
  color: #1f1b14;
  text-align: center;
  margin-bottom: 12px;
}

.elaw-hero__title-accent {
  color: #7b580d;
}

.elaw-hero__subtitle {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 18px;
  font-weight: 700;
  color: #4e4538;
  text-align: center;
  margin-bottom: 28px;
}

/* ── Search card — exact Figma: rounded-32px, blur, border #e7e2d9, shadow ── */
.elaw-search-card {
  position: relative;
  z-index: 20;
  background: rgba(255, 255, 255, 0.8) !important;
  backdrop-filter: blur(6px) !important;
  border: 1px solid #e7e2d9 !important;
  border-radius: 32px !important;
  box-shadow: 0 10px 40px 0 rgba(75, 70, 61, 0.08) !important;
  max-width: 992px;
  width: 100%;
  overflow: visible !important;
}

/* Label */
.elaw-search-card__label {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 15px;
  font-weight: 700;
  color: #4e4538;
  letter-spacing: 0.36px;
  margin: 0 0 5px;
}

/* Category dropdown and search field */
.elaw-search-card :deep(.v-field) {
  border-radius: 9999px !important;
  border-color: #d2c5b3 !important;
  background: #ffffff !important;
}

.elaw-search-card :deep(.v-chip) {
  border-radius: 9999px !important;
}

/* Search input row */
.elaw-search-card__search-row {
  width: 100%;
  position: relative;
  z-index: 60;
  overflow: visible;
}

/* Live suggestion dropdown */
.elaw-hero-suggest {
  position: absolute;
  top: calc(100% + 8px);
  left: 0;
  right: 0;
  z-index: 4000;
  overflow: hidden;
  border-radius: 16px !important;
  background: #ffffff;
  box-shadow: 0 12px 40px rgba(75, 70, 61, 0.14) !important;
}

.elaw-hero-suggest__status {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 16px;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 13px;
  color: #6b7280;
}

.elaw-hero-suggest__item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  width: 100%;
  padding: 11px 16px;
  border: 0;
  border-top: 1px solid rgba(210, 197, 179, 0.28);
  background: transparent;
  text-align: left;
  cursor: pointer;
}

.elaw-hero-suggest__item:first-of-type {
  border-top: 0;
}

.elaw-hero-suggest__item:hover {
  background: rgba(255, 250, 236, 0.85);
}

.elaw-hero-suggest__title {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 15px;
  font-weight: 700;
  color: #1f1b14;
}

.elaw-hero-suggest__title :deep(mark) {
  background: rgba(182, 141, 64, 0.32);
  padding: 0 2px;
  border-radius: 2px;
}

.elaw-hero-suggest__meta {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 12px;
  color: #6b7280;
}

/* Search input */
.elaw-search-card__query :deep(.v-field) {
  border-radius: 9999px !important;
  border-color: #d2c5b3 !important;
  background: #ffffff !important;
}

.elaw-search-card__query :deep(.v-field__input) {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 16px;
  color: #6b7280;
  min-height: 54px;
  padding-top: 8px;
  padding-bottom: 8px;
}

.elaw-search-card__query :deep(.v-field__append-inner) {
  align-items: center;
  padding-inline-start: 10px;
}

/* Search button */
.elaw-search-card__search-btn {
  background: #343028 !important;
  color: #ffffff !important;
  border-radius: 9999px !important;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 15px !important;
  font-weight: 700;
  letter-spacing: 0.36px;
  padding: 0 24px !important;
  height: 42px !important;
  margin-right: 0;
}

/* Trending row */
.elaw-search-card__tags {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding-top: 11px;
  border-top: 1px solid rgba(210, 197, 179, 0.3);
  margin-top: 8px;
}

.elaw-search-card__tags-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: #7b580d;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0.36px;
  white-space: nowrap;
}

.elaw-search-card__tag {
  background: #ffffff !important;
  border-color: #d2c5b3 !important;
  color: #4e4538 !important;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 13px !important;
  font-weight: 700;
  padding: 7px 17px !important;
}

@media (max-width: 640px) {
  .elaw-hero {
    padding: 32px 16px 40px;
  }
  .elaw-hero__title {
    font-size: clamp(26px, 6vw, 32px);
    line-height: 1.45;
  }
  .elaw-hero__subtitle {
    font-size: 16px;
  }
}
</style>
