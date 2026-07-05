<template>
  <AppShell
    :breadcrumbs="['เมนูหลัก', 'จัดการตัวบทกฎหมาย']"
    title="จัดการตัวบทกฎหมาย"
    subtitle="จัดการกฎหมายทั้งหมด ค้นหา แก้ไข ความสัมพันธ์ และเวอร์ชัน"
  >
    <template #actions>
      <v-btn color="admin-navy" prepend-icon="mdi-plus" to="/admin/upload">
        + เพิ่มกฎหมายใหม่
      </v-btn>
    </template>

    <v-row class="mb-5">
      <v-col v-for="stat in typeStats" :key="stat.label" cols="6" sm="3">
        <v-card
          flat
          border
          rounded="lg"
          class="pa-3 cursor-pointer"
          :class="{ 'border-primary': activeType === stat.value }"
          @click="activeType = stat.value"
        >
          <div class="d-flex align-center ga-2 mb-1">
            <v-chip size="x-small" :color="stat.color" rounded="pill">{{ stat.label }}</v-chip>
            <v-chip size="x-small" color="success" variant="tonal" rounded="pill">
              +{{ stat.delta }} เดือนนี้
            </v-chip>
          </div>
          <p class="text-h6 font-weight-black mb-0">{{ stat.count }}</p>
          <p class="text-caption text-medium-emphasis">ฉบับ</p>
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
          <tr v-for="(law, idx) in laws" :key="law.id">
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
        <span class="text-caption text-medium-emphasis">กำลังแสดงผล 1 - 4 จากทั้งหมด 12,402 รายการ</span>
        <v-pagination v-model="page" :length="45" :total-visible="5" rounded="circle" density="compact" />
      </div>
    </v-card>
  </AppShell>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import AppShell from '../../components/shared/AppShell.vue';

const search = ref('');
const filterType = ref<string | null>(null);
const filterStatus = ref<string | null>(null);
const sortOrder = ref('newest');
const page = ref(1);
const activeType = ref('all');

const typeStats = [
  { label: 'พระราชบัญญัติ', value: 'phrb', count: 124, delta: 4, color: 'deep-purple' },
  { label: 'ข้อบังคับ', value: 'kho-bangkhab', count: 356, delta: 12, color: 'info' },
  { label: 'ระเบียบ', value: 'rabiap', count: 742, delta: 8, color: 'success' },
  { label: 'ประกาศ', value: 'prakat', count: 1218, delta: 24, color: 'warning' },
];

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
    พระราชบัญญัติ: 'deep-purple',
    ระเบียบ: 'success',
    ข้อบังคับ: 'info',
    ประกาศ: 'warning',
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
