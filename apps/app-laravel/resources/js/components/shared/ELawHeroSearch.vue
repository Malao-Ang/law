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
                  color="#343028"
                  variant="flat"
                  rounded="lg"
                  size="default"
                  class="elaw-search-card__search-btn"
                  @click="emitSearch"
                >
                  <v-icon icon="mdi-magnify" size="15" start />
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
/* ── Hero section ──────────────────────────────────── */
.elaw-hero {
  background: linear-gradient(180deg, #f8f7f1 8.17%, #fff4b5 100%);
  border-bottom: 1px solid #eadfcb;
  padding: 50px 24px 60px;
}

.elaw-hero__container {
  display: flex;
  flex-direction: column;
  align-items: center;
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
  font-family: 'Sarabun', 'TH Sarabun New', sans-serif;
  font-size: 12px;
  font-weight: 700;
  margin-bottom: 16px;
}

/* H1: 64px two-color — first line #7b580d, second line #1f1b14 */
.elaw-hero__title {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: clamp(2.2rem, 4.5vw, 4rem);
  font-weight: 700;
  line-height: 65px;
  color: #1f1b14;
  text-align: center;
  margin-bottom: 12px;
}

.elaw-hero__title-accent {
  color: #7b580d;
}

/* Subtitle: 24px #4e4538 */
.elaw-hero__subtitle {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 24px;
  font-weight: 700;
  color: #4e4538;
  text-align: center;
  margin-bottom: 28px;
}

/* ── Search card — exact Figma: rounded-32px, blur, border #e7e2d9, shadow ── */
.elaw-search-card {
  background: rgba(255, 255, 255, 0.8) !important;
  backdrop-filter: blur(6px) !important;
  border: 1px solid #e7e2d9 !important;
  border-radius: 32px !important;
  box-shadow: 0 10px 40px 0 rgba(75, 70, 61, 0.08) !important;
  max-width: 992px;
  width: 100%;
}

/* Label: 18px TH Sarabun New Bold #4e4538 */
.elaw-search-card__label {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 18px;
  font-weight: 700;
  color: #4e4538;
  letter-spacing: 0.36px;
  margin: 0 0 5px;
}

/* Category dropdown — white bg, border #d2c5b3, rounded 16px */
.elaw-search-card :deep(.v-field) {
  border-radius: 16px !important;
  border-color: #d2c5b3 !important;
  background: #ffffff !important;
}

/* Search input row */
.elaw-search-card__search-row {
  width: 100%;
}

/* Search input: border #d2c5b3, rounded 16px */
.elaw-search-card__query :deep(.v-field) {
  border-radius: 16px !important;
  border-color: #d2c5b3 !important;
  background: #ffffff !important;
}

.elaw-search-card__query :deep(.v-field__input) {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 22px;
  color: #6b7280;
}

/* Search button: #343028, rounded 12px */
.elaw-search-card__search-btn {
  background: #343028 !important;
  color: #ffffff !important;
  border-radius: 12px !important;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 20px !important;
  font-weight: 700;
  letter-spacing: 0.36px;
  padding: 0 24px !important;
  height: 48px !important;
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

/* Trending label: #7b580d 18px */
.elaw-search-card__tags-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: #7b580d;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 18px;
  font-weight: 700;
  letter-spacing: 0.36px;
  white-space: nowrap;
}

/* Trending pill: white bg, border #d2c5b3, text #4e4538 16px */
.elaw-search-card__tag {
  background: #ffffff !important;
  border-color: #d2c5b3 !important;
  color: #4e4538 !important;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px !important;
  font-weight: 700;
  padding: 7px 17px !important;
}

@media (max-width: 640px) {
  .elaw-hero {
    padding: 32px 16px 40px;
  }
  .elaw-hero__title {
    font-size: clamp(1.6rem, 7vw, 2.4rem);
    line-height: 1.5;
  }
  .elaw-hero__subtitle {
    font-size: 18px;
  }
}
</style>
