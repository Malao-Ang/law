<template>
  <section class="elaw-hero">
    <v-container class="elaw-hero__container" style="max-width: 980px">
      <div class="d-flex justify-center">
        <v-chip class="elaw-hero__eyebrow" size="small" rounded="pill" variant="outlined">
          แพลตฟอร์มสืบค้นกฎหมายสำหรับทุกคน
        </v-chip>
      </div>

      <h1 class="text-center font-weight-black mb-3 elaw-hero__title">
        <span class="elaw-hero__title-accent">ค้นหาและเข้าถึงข้อมูลกฎหมาย</span>
        <br>
        ได้อย่างสะดวกรวดเร็ว
      </h1>
      <p class="text-center mb-7 elaw-hero__subtitle">
        อัปเดตข้อมูลล่าสุด รวบรวมพระราชบัญญัติ กฎกระทรวง และประกาศต่าง ๆ ไว้ในที่เดียว
      </p>

      <v-card flat rounded="xl" class="pa-5 pa-md-6 elaw-search-card">
        <div class="mb-4">
          <p class="text-caption mb-2 elaw-search-card__label">ประเภทเอกสาร</p>
          <div class="d-flex flex-wrap ga-2">
            <v-chip
              v-for="type in docTypes"
              :key="type.value"
              :variant="isTypeSelected(type.value) ? 'flat' : 'outlined'"
              rounded="pill"
              class="elaw-search-card__type-chip"
              @click="toggleType(type.value)"
            >
              {{ type.label }}
            </v-chip>
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
              append-inner-icon="mdi-magnify"
              class="elaw-search-card__query"
              @click:append-inner="emitSearch"
              @keydown.enter="emitSearch"
            />
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

<script setup lang="ts">
import { nextTick, ref } from 'vue';

const emit = defineEmits<{
  search: [query: string, types: string[]];
}>();

const query = ref('');
const selectedTypes = ref<string[]>(['all']);
const queryInput = ref<{ focus?: () => void } | null>(null);

const docTypes = [
  { label: 'ทั้งหมด', value: 'all' },
  { label: 'พ.ร.บ.', value: 'phrb' },
  { label: 'ข้อบังคับ', value: 'kho-bangkhab' },
  { label: 'ระเบียบ', value: 'rabiap' },
  { label: 'ประกาศ', value: 'prakat' },
];

const popularTags = [
  'อัตราเบิกค่าใช้จ่ายเดินทาง',
  'กองทุนสร้างเสริมสุขภาพ',
  'โครงสร้างสถาบันวิจัย',
];

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

function emitSearch(): void {
  const normalizedTypes = selectedTypes.value.includes('all') ? [] : selectedTypes.value;
  emit('search', query.value, normalizedTypes);
}

async function applyPopularTag(tag: string): Promise<void> {
  query.value = tag;
  await nextTick();
  queryInput.value?.focus?.();
}
</script>

<style scoped>
.elaw-hero {
  background: linear-gradient(180deg, #f8f7f1 8%, #fff4b5 100%);
  border-bottom: 1px solid #eadfcb;
  min-height: 100vh;
  padding: 24px 24px 40px;
}

.elaw-hero__container {
  min-height: calc(100vh - 48px);
  display: flex;
  flex-direction: column;
  justify-content: start;
}

.elaw-hero__eyebrow {
  border-color: #d9c9ab;
  color: rgb(var(--v-theme-primary));
  background: rgba(255, 255, 255, 0.82);
}

.elaw-hero__title {
  font-size: clamp(1.85rem, 3.2vw, 3rem);
  line-height: 1.6;
  color: #33302a;
}

.elaw-hero__title-accent {
  color: rgb(var(--v-theme-primary));
}

.elaw-hero__subtitle {
  color: #6e685e;
  font-size: 1.3rem;
}

.elaw-search-card {
  background: rgb(var(--v-theme-detail-surface));
  border: 1px solid #e8dcc7 !important;
  box-shadow: 0 18px 46px rgba(186, 151, 63, 0.15) !important;
}

.elaw-search-card__label {
  color: #7d705a;
}

.elaw-search-card__type-chip {
  min-height: 40px;
  border-color: #decfb8;
  color: #7f6440;
  background: #fffdfa;
}

.elaw-search-card__type-chip.v-chip--variant-flat {
  border-color: #bf9139;
  color: #8f6718;
  background: #fff2cf;
}

.elaw-search-card__search-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  align-items: stretch;
}

.elaw-search-card__query :deep(.v-field) {
  border-radius: 20px;
}

.elaw-search-card__query :deep(.v-field__append-inner) {
  padding-inline-end: 10px;
}

.elaw-search-card__query :deep(.v-icon) {
  color: rgba(52, 48, 40, 1);
}

.elaw-search-card__tags {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding-top: 18px;
}

.elaw-search-card__tags-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: #9b7625;
  font-size: 1rem;
  white-space: nowrap;
}

.elaw-search-card__tag {
  border-color: #e2d7c4;
  color: #7a6551;
  background: #fffdfa;
}

@media (max-width: 640px) {
  .elaw-hero {
    min-height: auto;
    padding: 32px 16px 32px;
  }

  .elaw-hero__container {
    min-height: auto;
    justify-content: flex-start;
  }

  .elaw-hero__title {
    font-size: clamp(1.55rem, 7vw, 2.25rem);
  }

  .elaw-hero__subtitle {
    font-size: 1.05rem;
  }

  .elaw-search-card__search-row {
    grid-template-columns: 1fr;
  }

  .elaw-search-card__tags {
    align-items: flex-start;
  }
}
</style>
