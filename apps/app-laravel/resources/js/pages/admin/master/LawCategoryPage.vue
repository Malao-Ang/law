<template>
  <MasterDataPage
    kind="law_category"
    title="หมวดเอกสาร"
    subtitle="จัดการข้อมูลหมวดเอกสารที่ใช้ในระบบ"
    :breadcrumbs="['จัดการข้อมูลระบบ', 'หมวดเอกสาร']"
    add-label="เพิ่มหมวด"
    :stat-labels="statLabels"
    :columns="columns"
    search-placeholder="ค้นหาด้วยรหัส หรือชื่อหมวดเอกสาร..."
    :dialog-title="{ create: 'เพิ่มหมวดเอกสาร', edit: 'แก้ไขหมวดเอกสาร' }"
    dialog-subtitle="กำหนดรหัสและชื่อหมวดเอกสาร"
    :field-labels="{ code: 'รหัสหมวดเอกสาร', name: 'ชื่อหมวดเอกสาร' }"
  >
    <template #item.usage_count="{ item }">
      <v-chip
        size="small"
        color="admin-primary"
        variant="tonal"
        prepend-icon="mdi-file-document-outline"
        :to="`/admin/laws?category=${item.code}`"
      >
        {{ (item.usage_count ?? 0).toLocaleString('th-TH') }} รายการ
      </v-chip>
    </template>
  </MasterDataPage>
</template>

<script setup lang="ts">
import MasterDataPage from '../../../components/master/MasterDataPage.vue';

const statLabels = {
  total: 'หมวดเอกสารทั้งหมด',
  active: 'หมวดเอกสารที่ใช้งาน',
  inactive: 'หมวดเอกสารที่ปิดใช้งาน',
};

const columns = [
  { key: 'sort_order', title: 'ลำดับ', width: '60px', align: 'center' as const },
  { key: 'code', title: 'รหัสหมวดเอกสาร', width: '140px' },
  { key: 'name', title: 'ชื่อหมวดเอกสาร' },
  { key: 'usage_count', title: 'จำนวนเอกสาร', width: '160px', align: 'center' as const },
  { key: 'is_active', title: 'สถานะ', width: '100px', align: 'center' as const },
  { key: 'actions', title: 'จัดการ', width: '120px', align: 'center' as const },
];
</script>
