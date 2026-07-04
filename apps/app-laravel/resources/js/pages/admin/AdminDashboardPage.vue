<template>
  <AppShell :breadcrumbs="['LAWSPACE', 'หน้าแรก']" title="ภาพรวมระบบ">
    <template #actions>
      <v-btn color="primary" prepend-icon="mdi-cloud-upload-outline" @click="router.push('/admin/upload')">
        นำเข้าเอกสาร
      </v-btn>
    </template>

    <div class="admin-dash__stats">
      <AdminStatCard
        v-for="stat in statCards"
        :key="stat.label"
        :icon="stat.icon"
        :icon-color="stat.iconColor"
        :icon-bg="stat.iconBg"
        :number="stat.number"
        :label="stat.label"
      />
    </div>

    <div class="admin-dash__two-col">
      <div class="admin-dash__card">
        <h3 class="admin-dash__card-title">ความครบถ้วนของข้อมูล</h3>
        <div class="admin-dash__completeness">
          <div v-for="item in completeness" :key="item.label" class="admin-dash__comp-row">
            <span class="admin-dash__comp-label">{{ item.label }}</span>
            <div class="admin-dash__comp-track">
              <div class="admin-dash__comp-fill" :style="{ width: `${item.pct}%`, background: item.color }"></div>
            </div>
            <span class="admin-dash__comp-pct">{{ item.pct }}%</span>
          </div>
        </div>
      </div>

      <div class="admin-dash__card">
        <h3 class="admin-dash__card-title">รายการเร่งด่วน</h3>
        <div class="admin-dash__alerts">
          <div v-for="alert in urgentAlerts" :key="alert.id" class="admin-dash__alert" :class="`admin-dash__alert--${alert.level}`">
            <span class="mdi" :class="alert.icon"></span>
            <div>
              <div class="admin-dash__alert-title">{{ alert.title }}</div>
              <div class="admin-dash__alert-sub">{{ alert.sub }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="admin-dash__card">
      <h3 class="admin-dash__card-title">เอกสารนำเข้าล่าสุด</h3>
      <table class="admin-dash__table">
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
            <td class="admin-dash__table-title">{{ doc.title }}</td>
            <td>
              <span class="elaw-badge" :class="`elaw-badge--${doc.docType}`">{{ doc.typeLabel }}</span>
            </td>
            <td class="admin-dash__table-date">{{ doc.date }}</td>
            <td>
              <span class="admin-dash__status-chip" :class="`admin-dash__status-chip--${doc.status}`">
                {{ statusLabel(doc.status) }}
              </span>
            </td>
            <td>
              <v-btn size="x-small" variant="tonal" :to="`/documents/${doc.id}/compose`">
                แก้ไข
              </v-btn>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
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
</script>

<style scoped>
.admin-dash__stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 24px;
}

.admin-dash__two-col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  margin-bottom: 24px;
}

.admin-dash__card {
  background: #fff;
  border: 1px solid var(--law-border);
  border-radius: 10px;
  padding: 20px 24px;
  box-shadow: 0 1px 3px rgba(16, 43, 63, 0.04);
}

.admin-dash__card-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--elaw-navy);
  margin: 0 0 16px;
}

.admin-dash__completeness {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.admin-dash__comp-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.admin-dash__comp-label {
  font-size: 13px;
  width: 80px;
  flex-shrink: 0;
  color: var(--elaw-text);
}

.admin-dash__comp-track {
  flex: 1;
  height: 8px;
  background: var(--law-surface);
  border-radius: 4px;
  overflow: hidden;
}

.admin-dash__comp-fill {
  height: 100%;
  border-radius: 4px;
}

.admin-dash__comp-pct {
  font-size: 12px;
  font-weight: 700;
  width: 36px;
  text-align: right;
  color: var(--elaw-muted);
}

.admin-dash__alerts {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.admin-dash__alert {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 13px;
}

.admin-dash__alert .mdi {
  font-size: 18px;
  flex-shrink: 0;
  margin-top: 1px;
}

.admin-dash__alert--error {
  background: #fef2f2;
  color: #b91c1c;
}

.admin-dash__alert--warning {
  background: #fffbeb;
  color: #92400e;
}

.admin-dash__alert--info {
  background: var(--law-primary-soft);
  color: var(--law-primary);
}

.admin-dash__alert-title {
  font-weight: 600;
}

.admin-dash__alert-sub {
  font-size: 12px;
  opacity: 0.8;
  margin-top: 2px;
}

.admin-dash__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.admin-dash__table th {
  text-align: left;
  padding: 8px 12px;
  border-bottom: 2px solid var(--law-border);
  font-weight: 700;
  color: var(--elaw-muted);
  font-size: 11px;
  text-transform: uppercase;
}

.admin-dash__table td {
  padding: 10px 12px;
  border-bottom: 1px solid var(--elaw-border);
  color: var(--elaw-text);
}

.admin-dash__table-title {
  font-weight: 500;
  max-width: 280px;
}

.admin-dash__table-date {
  color: var(--elaw-muted);
  white-space: nowrap;
}

.admin-dash__status-chip {
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 10px;
}

.admin-dash__status-chip--done {
  color: #15803d;
  background: #dcfce7;
}

.admin-dash__status-chip--processing {
  color: #92400e;
  background: #fffbeb;
}

.admin-dash__status-chip--queued {
  color: #1d4ed8;
  background: #dbeafe;
}

@media (max-width: 960px) {
  .admin-dash__stats {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 720px) {
  .admin-dash__two-col {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 600px) {
  .admin-dash__stats {
    grid-template-columns: 1fr;
  }
}
</style>
