<template>
  <MasterDataPage
    kind="enforcement_status"
    title="สถานะการบังคับใช้"
    subtitle="จัดการสถานะการมีผลบังคับใช้ของกฎหมาย"
    add-label="เพิ่มสถานะ"
    :stat-labels="statLabels"
    :columns="columns"
    search-placeholder="ค้นหาด้วยรหัส หรือชื่อสถานะ..."
    :dialog-title="dialogTitle"
    dialog-subtitle="กำหนดข้อมูลสถานะสำหรับใช้เป็นตัวเลือกในระบบจัดการเอกสารกฎหมาย"
    :field-labels="fieldLabels"
  >
    <template #item.name="{ item }">
      <v-chip :color="item.attrs.color || 'grey'" variant="tonal" size="small" class="font-weight-medium">
        {{ item.name }}
      </v-chip>
    </template>

    <template #item.description="{ item }">
      <span class="text-body-2 text-medium-emphasis">{{ item.description || '-' }}</span>
    </template>

    <template #attrs="{ form }">
      <label for="enforcement-color" class="text-body-2 font-weight-bold">สีป้ายสถานะ</label>
      <v-select
        id="enforcement-color"
        v-model="form.attrs.color"
        :items="colorOptions"
        item-title="title"
        item-value="value"
        variant="outlined"
        density="compact"
      >
        <template #selection="{ item }">
          <v-chip :color="item.value" size="small" variant="tonal">{{ item.title }}</v-chip>
        </template>
        <template #item="{ props, item }">
          <v-list-item v-bind="props">
            <template #prepend>
              <v-chip :color="item.value" size="x-small" variant="tonal">{{ item.title }}</v-chip>
            </template>
          </v-list-item>
        </template>
      </v-select>
    </template>
  </MasterDataPage>
</template>

<script setup lang="ts">
import MasterDataPage from '../../../components/master/MasterDataPage.vue';

const statLabels = {
  total: 'สถานะทั้งหมด',
  active: 'รายการที่ใช้งาน',
  inactive: 'รายการที่ปิดใช้งาน',
};

const columns = [
  { key: 'name', title: 'ชื่อสถานะ', width: '220px' },
  { key: 'description', title: 'คำอธิบาย' },
];

const dialogTitle = {
  create: 'เพิ่มสถานะการบังคับใช้',
  edit: 'แก้ไขสถานะการบังคับใช้',
};

const fieldLabels = {
  code: 'รหัสสถานะ',
  name: 'ชื่อสถานะ',
};

const colorOptions = [
  { title: 'เขียว', value: 'success' },
  { title: 'แดง', value: 'error' },
  { title: 'เทา', value: 'grey' },
  { title: 'น้ำเงิน', value: 'info' },
  { title: 'ส้ม', value: 'warning' },
];
</script>
