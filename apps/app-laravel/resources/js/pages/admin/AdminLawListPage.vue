<template>
  <AppShell
    :breadcrumbs="['เมนูหลัก', 'จัดการตัวบทกฎหมาย']"
    title="จัดการตัวบทกฎหมาย"
    subtitle="จัดการกฎหมายทั้งหมด ค้นหา แก้ไข ความสัมพันธ์ และเวอร์ชัน"
  >

    <v-row class="mb-5">
      <v-col v-for="stat in typeStats" :key="stat.value" cols="6" sm="3">
        <v-card
          flat
          rounded="lg"
          class="type-tab pa-4"
          :class="{ 'type-tab--active': filterType === stat.value }"
          :style="{ '--accent': `var(--v-theme-${stat.color})` }"
          role="button"
          @click="toggleType(stat.value)"
        >
          <div class="d-flex align-center justify-space-between mb-3">
            <div class="d-flex align-center ga-2">
              <v-avatar size="32" rounded="lg" class="type-tab__icon">
                <v-icon :icon="stat.icon" size="18" />
              </v-avatar>
              <span class="text-body-2 font-weight-bold type-tab__label">{{ stat.label }}</span>
            </div>
            <span class="text-caption text-success font-weight-medium">+{{ stat.delta }} เดือนนี้</span>
          </div>
          <div class="d-flex align-end ga-1">
            <span class="text-h5 font-weight-black">{{ stat.count.toLocaleString('th-TH') }}</span>
            <span class="text-caption text-medium-emphasis mb-1">ฉบับ</span>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <div class="d-flex flex-wrap ga-3 mb-4 align-center">
      <v-text-field
        v-model="search"
        placeholder="ค้นหาชื่อกฎหมาย / พ.ศ. / หน่วยงาน / เลขอ้างอิง"
        prepend-inner-icon="mdi-magnify"
        variant="outlined"
        density="compact"
        hide-details
        style="max-width: 500px; flex: 1 1 300px"
      />
      <v-select
        v-model="filterType"
        :items="typeOptions"
        item-title="label"
        item-value="value"
        label="ประเภทกฎหมาย"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        style="max-width: 180px"
      />
      <v-select
        v-model="filterStatus"
        :items="statusOptions"
        item-title="label"
        item-value="value"
        label="สถานะ"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        style="max-width: 160px"
      />
      <v-select
        v-model="sortOrder"
        :items="sortOptions"
        item-title="label"
        item-value="value"
        label="เรียงลำดับ"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        style="max-width: 160px"
      />
    </div>

    <v-card flat border rounded="lg">
      <v-table density="comfortable">
        <thead>
          <tr>
            <th>#</th>
            <th>ชื่อกฎหมาย / เอกสารสาระบบ</th>
            <th>ประเภท</th>
            <th>สถานะ</th>
            <th>แก้ไขล่าสุด</th>
            <th>จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="filteredLaws.length === 0">
            <td colspan="6" class="text-center pa-6 text-medium-emphasis">ไม่พบกฎหมายที่ตรงกับเงื่อนไข</td>
          </tr>
          <tr v-for="(law, idx) in filteredLaws" :key="law.id">
            <td class="text-caption text-medium-emphasis">{{ idx + 1 }}</td>
            <td class="py-3" style="max-width: 400px">
              <div class="d-flex align-center ga-2 flex-wrap mb-1">
                <span class="text-body-2 font-weight-bold">{{ law.title }}</span>
                <v-chip v-if="law.isParent" size="x-small" color="deep-purple" variant="tonal" rounded="pill">
                  <v-icon start icon="mdi-link-variant" size="11" />
                  กฎหมายแม่บท
                </v-chip>
              </div>
              <div class="d-flex flex-wrap ga-3 text-caption text-medium-emphasis">
                <span v-if="law.childCount">
                  <v-icon size="11" icon="mdi-sitemap" />
                  มีกฎหมายลูก {{ law.childCount }} ฉบับ
                </span>
                <span v-if="law.amendCount">
                  <v-icon size="11" icon="mdi-refresh" color="warning" />
                  แก้ไขแล้ว {{ law.amendCount }} ครั้ง
                </span>
                <span><v-icon size="11" icon="mdi-domain" /> {{ law.org }}</span>
                <span><v-icon size="11" icon="mdi-tag" /> {{ law.group }}</span>
                <span><v-icon size="11" icon="mdi-file-multiple" /> {{ law.pages }} หน้า / {{ law.sections }} ข้อ</span>
              </div>
            </td>
            <td>
              <v-chip size="x-small" :color="typeColor(law.lawType)" rounded="pill">{{ law.lawType }}</v-chip>
            </td>
            <td>
              <v-chip size="x-small" :color="statusColor(law.status)" rounded="pill">
                <v-icon start icon="mdi-circle" size="8" />
                {{ law.status }}
              </v-chip>
            </td>
            <td class="text-caption">
              <p class="mb-0">{{ law.editedAt }}</p>
              <p class="text-medium-emphasis mb-0">โดย {{ law.editedBy }}</p>
            </td>
            <td>
              <div class="d-flex ga-1">
                <v-btn icon="mdi-pencil-outline" size="x-small" variant="text" color="primary" />
                <v-btn icon="mdi-eye-outline" size="x-small" variant="text" color="grey" />
                <v-btn icon="mdi-dots-vertical" size="x-small" variant="text" color="grey" />
              </div>
            </td>
          </tr>
        </tbody>
      </v-table>

      <v-divider />
      <div class="d-flex justify-space-between align-center pa-3">
        <span class="text-caption text-medium-emphasis">กำลังแสดงผล {{ filteredLaws.length }} จากทั้งหมด 12,402 รายการ</span>
        <v-pagination v-model="page" :length="45" :total-visible="5" rounded="circle" density="compact" />
      </div>
    </v-card>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import AppShell from '../../components/shared/AppShell.vue';

const search = ref('');
const filterType = ref<string | null>(null);
const filterStatus = ref<string | null>(null);
const sortOrder = ref('newest');
const page = ref(1);

const typeStats = [
  { label: 'พระราชบัญญัติ', value: 'phrb', count: 124, delta: 4, color: 'doc-phrb', icon: 'mdi-bank-outline' },
  { label: 'ข้อบังคับ', value: 'kho-bangkhab', count: 356, delta: 12, color: 'doc-kho-bangkhab', icon: 'mdi-scale-balance' },
  { label: 'ระเบียบ', value: 'rabiap', count: 742, delta: 8, color: 'doc-rabiap', icon: 'mdi-folder-outline' },
  { label: 'ประกาศ', value: 'prakat', count: 1218, delta: 24, color: 'doc-prakat', icon: 'mdi-bullhorn-variant-outline' },
];

// Cards double as type filter tabs; clicking the active one clears the filter.
const typeLabelByValue: Record<string, string> = {
  phrb: 'พระราชบัญญัติ',
  'kho-bangkhab': 'ข้อบังคับ',
  rabiap: 'ระเบียบ',
  prakat: 'ประกาศ',
};

function toggleType(value: string): void {
  filterType.value = filterType.value === value ? null : value;
}

const filteredLaws = computed(() =>
  laws.filter((law) => {
    if (filterType.value && law.lawType !== typeLabelByValue[filterType.value]) return false;
    if (filterStatus.value === 'active' && law.status !== 'มีผลบังคับใช้') return false;
    if (search.value && !law.title.toLowerCase().includes(search.value.toLowerCase())) return false;
    return true;
  }),
);

const typeOptions = [
  { label: 'ทุกประเภท', value: null },
  { label: 'พระราชบัญญัติ', value: 'phrb' },
  { label: 'ข้อบังคับ', value: 'kho-bangkhab' },
  { label: 'ระเบียบ', value: 'rabiap' },
  { label: 'ประกาศ', value: 'prakat' },
];

const statusOptions = [
  { label: 'ทุกสถานะ', value: null },
  { label: 'มีผลบังคับใช้', value: 'active' },
  { label: 'ยกเลิก', value: 'cancelled' },
  { label: 'ร่าง', value: 'draft' },
];

const sortOptions = [
  { label: 'ล่าสุด', value: 'newest' },
  { label: 'เก่าสุด', value: 'oldest' },
  { label: 'ชื่อ A-Z', value: 'name' },
];

const laws = [
  {
    id: '1',
    title: 'พระราชบัญญัติมหาวิทยาลัยบูรพา พ.ศ. 2550',
    lawType: 'พระราชบัญญัติ',
    status: 'มีผลบังคับใช้',
    isParent: true,
    childCount: 14,
    amendCount: 3,
    org: 'รัฐบาลดิจิทัล',
    group: 'ดิจิทัลเพื่อเศรษฐกิจ',
    pages: 20,
    sections: 86,
    editedAt: '14 มี.ค. 2566',
    editedBy: 'Admin User',
  },
  {
    id: '2',
    title: 'ระเบียบคณะกรรมการคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562',
    lawType: 'ระเบียบ',
    status: 'มีผลบังคับใช้',
    isParent: false,
    childCount: 0,
    amendCount: 0,
    org: 'มหาวิทยาลัยบูรพา',
    group: '1.6 โครงสร้างองค์กรฯ',
    pages: 6,
    sections: 14,
    editedAt: '14 มี.ค. 2566',
    editedBy: 'Admin User',
  },
  {
    id: '3',
    title: 'ข้อบังคับมหาวิทยาลัยบูรพา ว่าด้วยการบริหารงานบุคคล พ.ศ. 2563',
    lawType: 'ข้อบังคับ',
    status: 'มีผลบังคับใช้',
    isParent: false,
    childCount: 0,
    amendCount: 0,
    org: 'มหาวิทยาลัยบูรพา',
    group: '1.1 การเงินและงบประมาณ',
    pages: 5,
    sections: 10,
    editedAt: '14 มี.ค. 2566',
    editedBy: 'Admin User',
  },
  {
    id: '4',
    title: 'ประกาศมหาวิทยาลัยบูรพา เรื่อง หลักเกณฑ์การขอตำแหน่งทางวิชาการ พ.ศ. 2568',
    lawType: 'ประกาศ',
    status: 'มีผลบังคับใช้',
    isParent: false,
    childCount: 0,
    amendCount: 0,
    org: 'มหาวิทยาลัยบูรพา',
    group: '1.1 ด้านวิชาการ',
    pages: 1,
    sections: 0,
    editedAt: '14 มี.ค. 2566',
    editedBy: 'Admin User',
  },
];

function typeColor(type: string): string {
  return ({
    พระราชบัญญัติ: 'doc-phrb',
    ระเบียบ: 'doc-rabiap',
    ข้อบังคับ: 'doc-kho-bangkhab',
    ประกาศ: 'doc-prakat',
  } as Record<string, string>)[type] ?? 'grey';
}

function statusColor(status: string): string {
  return ({
    'มีผลบังคับใช้': 'success',
    ยกเลิก: 'error',
    ร่าง: 'grey',
  } as Record<string, string>)[status] ?? 'grey';
}
</script>

<style scoped>
/* Each card is a filter tab tinted by its document-type color (--accent = doc-* rgb triplet). */
.type-tab {
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-top: 3px solid rgb(var(--accent));
  cursor: pointer;
  transition: box-shadow 0.15s ease, background 0.15s ease;
}

.type-tab:hover {
  box-shadow: 0 6px 18px rgba(15, 23, 42, 0.1);
}

.type-tab--active {
  background: rgba(var(--accent), 0.06);
  border: 1px solid rgb(var(--accent));
  border-top: 3px solid rgb(var(--accent));
}

.type-tab__icon {
  background: rgba(var(--accent), 0.14);
  color: rgb(var(--accent));
}

.type-tab__label {
  color: rgb(var(--accent));
}
</style>
