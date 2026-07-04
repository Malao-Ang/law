<template>
  <AppShell :breadcrumbs="['LAWSPACE', 'หน้าแรก']" title="ภาพรวมระบบ">
    <template #actions>
      <v-btn color="primary" prepend-icon="mdi-cloud-upload-outline" @click="router.push('/admin/upload')">
        นำเข้าเอกสาร
      </v-btn>
    </template>

    <v-row class="mb-6">
      <v-col
        v-for="stat in statCards"
        :key="stat.label"
        cols="12"
        sm="6"
        md="3"
      >
        <AdminStatCard
          :icon="stat.icon"
          :icon-color="stat.iconColor"
          :icon-bg="stat.iconBg"
          :number="stat.number"
          :label="stat.label"
        />
      </v-col>
    </v-row>

    <v-row class="mb-6">
      <v-col cols="12" md="6">
        <v-card flat border rounded="lg">
          <v-card-title class="text-subtitle-1 font-weight-bold">ความครบถ้วนของข้อมูล</v-card-title>
          <v-card-text>
            <div
              v-for="item in completeness"
              :key="item.label"
              class="d-flex align-center ga-3 mb-2"
            >
              <span class="text-body-2" style="width:80px;flex-shrink:0">{{ item.label }}</span>
              <v-progress-linear
                :model-value="item.pct"
                :color="item.color"
                height="8"
                rounded
                class="flex-grow-1"
              />
              <span class="text-caption">{{ item.pct }}%</span>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card flat border rounded="lg">
          <v-card-title class="text-subtitle-1 font-weight-bold">รายการเร่งด่วน</v-card-title>
          <v-card-text>
            <v-alert
              v-for="alert in urgentAlerts"
              :key="alert.id"
              :type="alert.level === 'error' ? 'error' : alert.level === 'warning' ? 'warning' : 'info'"
              variant="tonal"
              density="compact"
              :title="alert.title"
              :text="alert.sub"
              class="mb-2"
            />
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-card flat border rounded="lg">
      <v-card-title class="text-subtitle-1 font-weight-bold">เอกสารนำเข้าล่าสุด</v-card-title>
      <v-card-text>
        <v-table>
          <thead>
            <tr>
              <th>ชื่อเอกสาร</th>
              <th>ประเภท</th>
              <th>วันที่นำเข้า</th>
              <th>สถานะ</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="doc in recentImports" :key="doc.id">
              <td>{{ doc.title }}</td>
              <td>
                <v-chip size="x-small" :color="badgeColor(doc.docType)">{{ doc.typeLabel }}</v-chip>
              </td>
              <td>{{ doc.date }}</td>
              <td>
                <v-chip size="x-small" :color="statusChipColor(doc.status)">{{ statusLabel(doc.status) }}</v-chip>
              </td>
              <td>
                <v-btn size="x-small" variant="tonal" :to="`/documents/${doc.id}/compose`">
                  แก้ไข
                </v-btn>
              </td>
            </tr>
          </tbody>
        </v-table>
      </v-card-text>
    </v-card>
  </AppShell>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router';
import AdminStatCard from '../../components/admin/AdminStatCard.vue';
import AppShell from '../../components/shared/AppShell.vue';

const router = useRouter();

const statCards = [
  { icon: 'mdi-alert-circle-outline', iconColor: '#d74747', iconBg: '#fee2e2', number: 84, label: 'จุดเสี่ยงที่พบ' },
  { icon: 'mdi-clock-edit-outline', iconColor: '#ea580c', iconBg: '#ffedd5', number: 216, label: 'รอปรับปรุง' },
  { icon: 'mdi-text-recognition', iconColor: '#2563eb', iconBg: '#dbeafe', number: 12, label: 'คิว OCR' },
  { icon: 'mdi-graph-outline', iconColor: '#7c3aed', iconBg: '#ede9fe', number: 12402, label: 'ความสัมพันธ์' },
];

const completeness = [
  { label: 'ระเบียบ', pct: 87, color: '#16a34a' },
  { label: 'ประกาศ', pct: 74, color: '#ea580c' },
  { label: 'ข้อบังคับ', pct: 61, color: '#2563eb' },
  { label: 'กฎหมายหลัก', pct: 92, color: '#7c3aed' },
];

const urgentAlerts = [
  { id: 'a1', level: 'error', icon: 'mdi-alert-circle', title: 'ระเบียบ 12 ฉบับ หมดอายุภายใน 30 วัน', sub: 'ต้องปรับปรุงเนื้อหา' },
  { id: 'a2', level: 'warning', icon: 'mdi-clock-alert', title: 'OCR คิวคงค้าง 12 งาน', sub: 'เอกสาร scan กำลังรอประมวลผล' },
  { id: 'a3', level: 'info', icon: 'mdi-information', title: '5 เอกสารรอตรวจสอบ', sub: 'โดยเจ้าหน้าที่ภายใน 3 วัน' },
];

const recentImports = [
  { id: 'doc-001', title: 'ระเบียบการบริหารงานบุคคล 2566', docType: 'rabiap', typeLabel: 'ระเบียบ', date: '27 มิ.ย. 2567', status: 'done' },
  { id: 'doc-002', title: 'ประกาศค่าธรรมเนียมการศึกษา', docType: 'prakat', typeLabel: 'ประกาศ', date: '26 มิ.ย. 2567', status: 'processing' },
  { id: 'doc-003', title: 'ข้อบังคับการสอบ', docType: 'kho-bangkhab', typeLabel: 'ข้อบังคับ', date: '25 มิ.ย. 2567', status: 'done' },
];

function statusLabel(status: string): string {
  if (status === 'done') return 'เสร็จสิ้น';
  if (status === 'processing') return 'กำลังประมวลผล';
  if (status === 'queued') return 'รอดำเนินการ';
  return status;
}

function badgeColor(t: string): string {
  return ({ rabiap: 'success', prakat: 'warning', 'kho-bangkhab': 'info', 'kotmai-krung': 'purple' } as Record<string, string>)[t] ?? 'grey';
}

function statusChipColor(s: string): string {
  return ({ done: 'success', processing: 'warning', queued: 'info' } as Record<string, string>)[s] ?? 'grey';
}
</script>
