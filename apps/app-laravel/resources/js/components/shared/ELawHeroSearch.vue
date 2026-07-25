<script setup lang="ts">
import { nextTick, ref } from 'vue';
import FilterTypeBadge from './FilterTypeBadge.vue';

const emit = defineEmits<{
  search: [query: string, types: string[], groups: string[]];
}>();

const query = ref('');
const selectedType = ref('ทั้งหมด');
const selectedGroups = ref<string[]>([]);
const queryInput = ref<{ focus?: () => void } | null>(null);

const typeOptions = ['ทั้งหมด', 'พ.ร.บ.', 'ข้อบังคับ', 'ระเบียบ', 'ประกาศ'];

const typeToValue: Record<string, string> = {
  'พ.ร.บ.': 'phrb',
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
        <span class="elaw-hero__title-accent">ค้นหาและเข้าถึงข้อมูลกฎหมาย</span>
        <br>
        ได้อย่างสะดวกรวดเร็ว
      </h1>
      <p class="text-center mb-7 elaw-hero__subtitle">
        อัปเดตข้อมูลล่าสุด รวบรวมพระราชบัญญัติ กฎกระทรวง และประกาศต่าง ๆ ไว้ในที่เดียว
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
              rounded="lg"
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
            >
              <template #prepend-inner>
                <v-icon icon="mdi-magnify" size="18" color="#3c2900" />
              </template>
              <template #append-inner>
                <v-btn
                  color="#ab7f29"
                  variant="flat"
                  rounded="lg"
                  size="small"
                  class="elaw-search-card__search-btn"
                  @click="emitSearch"
                >
                  ค้นหาทันที
                </v-btn>
              </template>
            </v-text-field>
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
  display: inline-flex;
  align-items: center;
  padding: 5px 13px;
  border: 1px solid #d9c9ab;
  border-radius: 9999px;
  color: rgb(var(--v-theme-primary));
  background: rgba(255, 255, 255, 0.82);
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px;
  margin-bottom: 16px;
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
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px;
  font-weight: 700;
}

.elaw-search-card__search-btn {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px;
  font-weight: 700;
  color: #ffffff !important;
  margin-right: -4px;
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
