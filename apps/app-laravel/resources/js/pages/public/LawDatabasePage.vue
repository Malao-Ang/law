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
            <div>
              <div class="d-flex flex-column flex-md-row ga-3 align-stretch align-md-center">
                <v-text-field
                  v-model="query"
                  :placeholder="'พิมพ์คำค้น เช่น พระราชบัญญัติ, มาตรา, สิทธิข้อมูลส่วนบุคคล...'"
                  variant="outlined"
                  density="comfortable"
                  hide-details
                  rounded="xl"
                  class="flex-grow-1 elaw-search-input"
                  bg-color="white"
                  @keydown.enter="doSearch"
                />
                <v-btn color="secondary" size="large" rounded="lg" @click="doSearch">
                  <v-icon start icon="mdi-magnify" />
                  ค้นหาข้อมูล
                </v-btn>
              </div>
            </div>

            <v-row class="ga-4 mt-4 justify-start align-start">
              <v-col cols="12" md="7">
                <div class="d-flex align-start flex-wrap ga-3 elaw-filter-row">
                  <p class="text-caption font-weight-bold text-medium-emphasis mb-0 mt-0">ประเภทเอกสาร</p>
                  <v-chip
                    v-for="type in typeFilters"
                    :key="type.value"
                    :value="type.value"
                    :variant="isTypeSelected(type.value) ? 'flat' : 'outlined'"
                    color="primary"
                    rounded="pill"
                    size="small"
                    class="elaw-search-chip"
                    @click="toggleType(type.value)"
                  >
                    {{ type.label }}
                  </v-chip>
                </div>
              </v-col>

              <!-- <v-col cols="12" md="5">
                <div class="d-flex align-start ga-3 elaw-filter-row">
                  <p class="text-caption font-weight-bold text-medium-emphasis mb-0 text-no-wrap">กลุ่มกฎหมาย</p>
                  <v-select
                    v-model="selectedGroups"
                    :items="groupFilters"
                    item-title="label"
                    item-value="value"
                    label="เลือกได้หลายกลุ่ม"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    hide-details
                    multiple
                    chips
                    closable-chips
                    size="small"
                    class="elaw-group-select flex-grow-1"
                    bg-color="white"
                  />
                </div>
              </v-col> -->
            </v-row>
        </v-container>
      </section>

      <v-container style="max-width: 1280px" class="mt-6 mb-10">
        <v-row>
          <v-col cols="12" class="pb-2">
            <div class="d-flex flex-wrap ga-3">
              <v-card v-for="stat in summaryStats" :key="stat.label" flat border rounded="lg"
                class="pa-3 d-flex flex-column align-center" style="min-width: 110px">
                <span class="text-h6 font-weight-black" :style="`color:${stat.color}`">{{ stat.count }}</span>
                <span class="text-caption text-medium-emphasis">{{ stat.label }}</span>
              </v-card>
            </div>
          </v-col>

          <v-col cols="12" md="3">
            <v-card flat border rounded="lg" class="pa-4">
              <div class="d-flex justify-space-between align-center mb-3">
                <span class="text-subtitle-2 font-weight-bold">ตัวกรองผลการค้นหา</span>
                <v-btn variant="text" size="x-small" color="error" @click="clearFilters">ล้างทั้งหมด</v-btn>
              </div>

              <p class="text-caption font-weight-bold text-medium-emphasis mb-1">สถานะการเปลี่ยนแปลง</p>
              <v-checkbox v-for="status in changeStatuses" :key="status.value" v-model="selectedStatuses"
                :value="status.value" :label="`${status.label} (${status.count})`" density="compact" hide-details
                class="mb-n1" />

              <v-divider class="my-3" />

              <p class="text-caption font-weight-bold text-medium-emphasis mb-1">สถานะการบังคับใช้</p>
              <v-checkbox v-for="status in useStatuses" :key="status.value" v-model="selectedUseStatuses"
                :value="status.value" :label="`${status.label} (${status.count})`" density="compact" hide-details
                class="mb-n1" />

              <v-divider class="my-3" />

              <p class="text-caption font-weight-bold text-medium-emphasis mb-1">ปีประกาศ</p>
              <div class="d-flex ga-2 align-center">
                <v-select v-model="yearFrom" :items="years" label="จาก" variant="outlined" density="compact"
                  hide-details />
                <span class="text-caption">ถึง</span>
                <v-select v-model="yearTo" :items="years" label="ถึง" variant="outlined" density="compact"
                  hide-details />
              </div>

              <v-divider class="my-3" />

              <p class="text-caption font-weight-bold text-medium-emphasis mb-1">หน่วยงาน</p>
              <v-text-field v-model="orgSearch" placeholder="ค้นหาหน่วยงาน..." variant="outlined" density="compact"
                hide-details />

              <v-divider class="my-3" />

              <v-btn color="admin-navy" block size="small" rounded="lg" @click="clearFilters">
                <v-icon start icon="mdi-refresh" />
                ล้างตัวกรอง
              </v-btn>
            </v-card>
          </v-col>

          <v-col cols="12" md="9">
            <div class="d-flex flex-column flex-sm-row align-start align-sm-center justify-space-between mb-3 ga-3">
              <span class="text-body-2">
                พบผลการค้นหา <strong>{{ results.length }}</strong> รายการ
              </span>
              <v-select v-model="sortBy" :items="sortOptions" item-title="label" item-value="value" variant="outlined"
                density="compact" hide-details style="max-width: 220px" />
            </div>

            <div class="d-flex flex-column ga-3">
              <DocumentVersionCard
                v-for="item in results"
                :key="item._id"
                :version="item"
              />
            </div>

            <div class="d-flex justify-center mt-6">
              <v-pagination v-model="page" :length="45" :total-visible="7" rounded="circle" />
            </div>
          </v-col>
        </v-row>
      </v-container>

      <ELawFooter />
    </v-main>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DocumentVersionCard from '../../components/shared/DocumentVersionCard.vue';
import ELawFooter from '../../components/shared/ELawFooter.vue';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import type { DocumentVersion } from '../../types/document-version';

const router = useRouter();
const route = useRoute();
const query = ref(typeof route.query.q === 'string' ? route.query.q : '');
const selectedTypes = ref<string[]>(readTypeArray(route.query.type));
const selectedGroups = ref<string[]>(readStringArray(route.query.group));
const selectedStatuses = ref<string[]>([]);
const selectedUseStatuses = ref<string[]>([]);
const yearFrom = ref('2560');
const yearTo = ref('2568');
const orgSearch = ref('');
const sortBy = ref('relevance');
const page = ref(1);

const breadcrumbs = [{ title: 'หน้าหลัก', disabled: false, to: '/' }, { title: 'ฐานข้อมูลกฎหมาย', disabled: true }];

const typeFilters = [
  { label: 'ทั้งหมด', value: 'all' },
  { label: 'พ.ร.บ.', value: 'phrb' },
  { label: 'ข้อบังคับ', value: 'kho-bangkhab' },
  { label: 'ระเบียบ', value: 'rabiap' },
  { label: 'ประกาศ', value: 'prakat' },
];

const groupFilters = [
  { label: 'ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร', value: 'academic' },
  { label: 'ด้านกิจการนิสิต', value: 'student-affairs' },
  { label: 'ด้านการวิจัย นวัตกรรม และการนำไปใช้ประโยชน์', value: 'research-innovation' },
  { label: 'ด้านบริการวิชาการ', value: 'academic-service' },
  { label: 'ด้านการทะนุบำรุงศิลปวัฒนธรรม', value: 'arts-culture' },
  { label: 'ด้านโครงสร้างองค์กรและระบบการบริหาร', value: 'organization-admin' },
  { label: 'ด้านการบริหารงานบุคคล สิทธิประโยชน์ วินัยและจรรยาบรรณ', value: 'hr-discipline' },
  { label: 'ด้านการเงินและทรัพย์สิน พัสดุ การตรวจสอบ และการบริหารความเสี่ยง', value: 'finance-assets-risk' },
  { label: 'ด้านการพัฒนารายได้', value: 'revenue-development' },
  { label: 'ด้านการรักษาพยาบาล', value: 'healthcare' },
  { label: 'ด้านการบริการเฉพาะด้าน เช่น ทันตกรรม', value: 'special-service' },
  { label: 'ด้านอื่น ๆ', value: 'other' },
];

const changeStatuses = [
  { label: 'กฎหมายใหม่', value: 'new', count: 124 },
  { label: 'ปรับปรุงรายมาตรา', value: 'amended', count: 356 },
  { label: 'ปรับปรุงทั้งฉบับ', value: 'amended-full', count: 428 },
  { label: 'ยกเลิกรายมาตรา', value: 'repealed-section', count: 86 },
  { label: 'ยกเลิกทั้งฉบับ', value: 'repealed', count: 42 },
];

const useStatuses = [
  { label: 'มีผลบังคับใช้', value: 'active', count: 2128 },
  { label: 'ยกเลิก', value: 'cancelled', count: 212 },
  { label: 'ร่าง', value: 'draft', count: 100 },
];

const years = Array.from({ length: 10 }, (_, index) => String(2560 + index));
const currentTypes = computed(() => selectedTypes.value.includes('all') ? [] : selectedTypes.value);
watch(
  () => route.query.type,
  (value) => {
    selectedTypes.value = readTypeArray(value);
  },
  { immediate: true },
);

watch(
  () => route.query.group,
  (value) => {
    selectedGroups.value = readStringArray(value);
  },
  { immediate: true },
);

const summaryStats = [
  { label: 'กฎหมายทั้งหมด', count: '2,440', color: '#1a2547' },
  { label: 'กฎหมายใหม่', count: '124', color: '#16a34a' },
  { label: 'ปรับปรุง', count: '356', color: '#2563eb' },
  { label: 'ยกเลิก', count: '42', color: '#dc2626' },
  { label: 'กฎหมายแม่', count: '98', color: '#7c3aed' },
  { label: 'กฎหมายลูก', count: '1,842', color: '#d97706' },
];

const sortOptions = [
  { label: 'ลำดับศักดิ์ความสำคัญ', value: 'relevance' },
  { label: 'ล่าสุด', value: 'newest' },
  { label: 'เก่าสุด', value: 'oldest' },
];

const results: DocumentVersion[] = [
  {
    _id: 'ver-001',
    documentId: 'doc-001',
    versionNo: 12,
    status: 'published',
    isCurrent: true,
    metadata: {
      title: 'พระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 (และที่แก้ไขเพิ่มเติมถึงฉบับปัจจุบัน)',
      documentType: 'phrb',
      documentGroupId: '1.6 โครงสร้างองค์กร',
      publicationScope: 'public',
      summary: 'กำหนดกลไกและมาตรการคุ้มครองข้อมูลส่วนบุคคลเพื่อเป็นมาตรฐาน ควบคุมผู้ควบคุมข้อมูลและผู้ประมวลผลข้อมูลไม่ให้สิทธิของ...',
      publishedDate: new Date('2020-05-28'),
      ownerAgencyId: 'รัฐบาลดิจิทัล',
      keywords: ['ข้อมูลส่วนบุคคล', 'PDPA'],
    },
    changeSummary: 'ปรับแก้ล่าสุดเพื่อให้สอดคล้องกับข้อกำหนดด้านการคุ้มครองข้อมูลภาครัฐ',
    publishedAt: new Date('2020-05-28'),
    createdAt: new Date('2020-05-28'),
    updatedAt: new Date('2024-05-18'),
  },
  {
    _id: 'ver-002',
    documentId: 'doc-002',
    versionNo: 3,
    status: 'published',
    isCurrent: true,
    metadata: {
      title: 'ข้อบังคับมหาวิทยาลัยบูรพา ว่าด้วยการบริหารงานบุคคล พ.ศ. 2563 (แก้ไขเพิ่มเติม ฉบับที่ 3)',
      documentType: 'kho-bangkhab',
      documentGroupId: '1.7 การบริหารงานบุคคล',
      publicationScope: 'organization',
      summary: 'แก้ไขเพิ่มเติมหลักเกณฑ์เกี่ยวกับการประเมินผลการปฏิบัติงาน และการเลื่อนระดับตำแหน่งของบุคลากร...',
      publishedDate: new Date('2021-01-31'),
      ownerAgencyId: 'มหาวิทยาลัยบูรพา',
      keywords: ['บุคลากร', 'เลื่อนระดับ'],
    },
    changeSummary: 'อัปเดตโครงสร้างตำแหน่งและหลักเกณฑ์การประเมินผลบุคลากร',
    publishedAt: new Date('2021-01-31'),
    createdAt: new Date('2021-01-31'),
    updatedAt: new Date('2024-04-10'),
  },
  {
    _id: 'ver-003',
    documentId: 'doc-003',
    versionNo: 1,
    status: 'published',
    isCurrent: true,
    metadata: {
      title: 'ระเบียบมหาวิทยาลัยบูรพา ว่าด้วยการเบิกจ่ายค่าใช้จ่ายในการเดินทางไปราชการ (ฉบับใหม่) พ.ศ. 2567',
      documentType: 'rabiap',
      documentGroupId: '1.8 การเงินและพัสดุ',
      publicationScope: 'public',
      summary: 'กำหนดหลักเกณฑ์และอัตราการเบิกค่าใช้จ่ายในการเดินทางไปราชการและการเบิกค่าใช้จ่ายในการฝึกอบรม...',
      publishedDate: new Date('2024-05-20'),
      ownerAgencyId: 'กองคลัง',
      keywords: ['เดินทางไปราชการ', 'เบิกจ่าย'],
    },
    changeSummary: 'ประกาศใช้อัตราใหม่และหลักฐานการเบิกจ่ายรูปแบบล่าสุด',
    publishedAt: new Date('2024-05-20'),
    createdAt: new Date('2024-05-20'),
    updatedAt: new Date('2024-05-20'),
  },
  {
    _id: 'ver-004',
    documentId: 'doc-004',
    versionNo: 18,
    status: 'published',
    isCurrent: false,
    metadata: {
      title: 'ประกาศมหาวิทยาลัยบูรพา เรื่อง หลักเกณฑ์และเงื่อนไขการสนับสนุนทุนวิจัยระดับนานาชาติ',
      documentType: 'prakat',
      documentGroupId: '1.3 การวิจัย นวัตกรรม',
      publicationScope: 'private',
      summary: 'กำหนดขั้นตอนและเงื่อนไขการสนับสนุนทุนวิจัยเพื่อการตีพิมพ์ผลงานในวารสารระดับนานาชาติ...',
      publishedDate: new Date('2024-03-10'),
      ownerAgencyId: 'สถาบันวิจัย',
      keywords: ['ทุนวิจัย', 'วารสารนานาชาติ'],
    },
    changeSummary: 'ฉบับนี้ถูกแทนที่ด้วยเกณฑ์ทุนฉบับใหม่',
    publishedAt: new Date('2024-03-10'),
    supersededBy: 'ver-005',
    createdAt: new Date('2024-03-10'),
    updatedAt: new Date('2024-06-01'),
  },
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

function readTypeArray(value: unknown): string[] {
  if (Array.isArray(value)) {
    const next = value.filter((item): item is string => typeof item === 'string' && item.length > 0);
    return next.length > 0 ? next : ['all'];
  }

  if (typeof value === 'string' && value.length > 0) {
    return [value];
  }

  return ['all'];
}

function readStringArray(value: unknown): string[] {
  if (Array.isArray(value)) {
    return value.filter((item): item is string => typeof item === 'string' && item.length > 0);
  }

  if (typeof value === 'string' && value.length > 0) {
    return [value];
  }

  return [];
}

function doSearch(): void {
  const selectedGroupValues = selectedGroups.value.length > 0 ? selectedGroups.value : undefined;
  const selectedTypeValues = currentTypes.value.length > 0 ? currentTypes.value : undefined;

  router.replace({
    path: '/database',
    query: {
      ...(query.value ? { q: query.value } : {}),
      ...(selectedTypeValues ? { type: selectedTypeValues } : {}),
      ...(selectedGroupValues ? { group: selectedGroupValues } : {}),
    },
  });
}

function clearFilters(): void {
  selectedTypes.value = ['all'];
  selectedGroups.value = [];
  selectedStatuses.value = [];
  selectedUseStatuses.value = [];
  yearFrom.value = '2560';
  yearTo.value = '2568';
  orgSearch.value = '';
}
</script>

<style scoped>
.elaw-db-header {
  background: linear-gradient(180deg, #f8f7f1 8%, #fff4b5 100%);
  border-bottom: 1px solid var(--elaw-border);
  padding: 28px 24px 34px;
}

.elaw-db-eyebrow {
  color: #ab7f29;
  border-color: rgba(171, 127, 41, 0.28);
  background: rgba(255, 255, 255, 0.56);
}

.elaw-search-card {
  background: rgba(255, 255, 255, 0.94);
  border: 1px solid rgba(171, 127, 41, 0.22);
  box-shadow: 0 18px 42px rgba(106, 77, 0, 0.08);
}

.elaw-search-input :deep(.v-field__input) {
  min-height: 58px;
}

.elaw-search-chip {
  font-weight: 700;
}

.elaw-filter-row {
  min-height: 54px;
}

.elaw-filter-row > p {
  line-height: 1;
}

.elaw-group-select :deep(.v-field__input) {
  min-height: 54px;
  align-items: center;
}

.elaw-group-select :deep(.v-chip) {
  margin-block: 2px;
}
</style>
