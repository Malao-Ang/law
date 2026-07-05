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
        md="2"
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
            <tr v-for="doc in docs.slice(0, 10)" :key="doc.document_id">
              <td>{{ doc.title }}</td>
              <td>
                <v-chip size="x-small" color="grey">เอกสาร</v-chip>
              </td>
              <td>{{ doc.updated_at ? new Date(doc.updated_at).toLocaleDateString('th-TH') : '-' }}</td>
              <td>
                <v-chip size="x-small" :color="statusChipColor(doc.status)">{{ statusLabel(doc.status) }}</v-chip>
              </td>
              <td>
                <v-btn size="x-small" variant="tonal" :to="`/documents/${doc.document_id}/review`">
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
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { listDocuments } from '../../api/client';
import type { DocumentListItem } from '../../types/document';
import AdminStatCard from '../../components/admin/AdminStatCard.vue';
import AppShell from '../../components/shared/AppShell.vue';

const router = useRouter();

// ── API state ──────────────────────────────────────────────
const docs = ref<DocumentListItem[]>([]);
const loading = ref(false);

onMounted(async () => {
  loading.value = true;
  try {
    const res = await listDocuments();
    docs.value = res.documents ?? [];
  } finally {
    loading.value = false;
  }
});

// ── Status card counts ─────────────────────────────────────
const PUBLISHED = ['done', 'exported', 'ingested'];
const PROCESSING = ['queued', 'processing', 'ingesting'];

const statCards = computed(() => [
  {
    icon: 'mdi-file-document-multiple-outline',
    iconColor: '#2563eb',
    iconBg: '#dbeafe',
    number: docs.value.length,
    label: 'เอกสารทั้งหมด',
  },
  {
    icon: 'mdi-check-circle-outline',
    iconColor: '#16a34a',
    iconBg: '#dcfce7',
    number: docs.value.filter(d => PUBLISHED.includes(d.status)).length,
    label: 'เผยแพร่แล้ว',
  },
  {
    icon: 'mdi-file-edit-outline',
    iconColor: '#64748b',
    iconBg: '#f1f5f9',
    number: docs.value.filter(d => d.status === 'failed').length,
    label: 'ร่างเอกสาร',
  },
  {
    icon: 'mdi-clock-outline',
    iconColor: '#d97706',
    iconBg: '#fef3c7',
    number: docs.value.filter(d => PROCESSING.includes(d.status)).length,
    label: 'รอตรวจสอบ',
  },
  {
    icon: 'mdi-draw-pen',
    iconColor: '#7c3aed',
    iconBg: '#ede9fe',
    number: 0,  // ponytail: eSign not in current API, show 0 until workflow added
    label: 'รอลงนาม (eSign)',
  },
]);

// ── Completeness bars (keep as-is) ─────────────────────────
const completeness = [
  { label: 'ระเบียบ', pct: 87, color: '#16a34a' },
  { label: 'ประกาศ', pct: 74, color: '#ea580c' },
  { label: 'ข้อบังคับ', pct: 61, color: '#2563eb' },
  { label: 'กฎหมายหลัก', pct: 92, color: '#7c3aed' },
];

// ── Urgent alerts (keep as-is) ──────────────────────────────
const urgentAlerts = [
  { id: 'a1', level: 'error', title: 'ระเบียบ 12 ฉบับ หมดอายุภายใน 30 วัน', sub: 'ต้องปรับปรุงเนื้อหา' },
  { id: 'a2', level: 'warning', title: 'OCR คิวคงค้าง 12 งาน', sub: 'เอกสาร scan กำลังรอประมวลผล' },
  { id: 'a3', level: 'info', title: '5 เอกสารรอตรวจสอบ', sub: 'โดยเจ้าหน้าที่ภายใน 3 วัน' },
];

// ── Helpers ────────────────────────────────────────────────
function badgeColor(t: string): string {
  return ({ rabiap: 'success', prakat: 'warning', 'kho-bangkhab': 'info', 'kotmai-krung': 'deep-purple' } as Record<string, string>)[t] ?? 'grey';
}
function statusChipColor(s: string): string {
  return ({ done: 'success', exported: 'success', ingested: 'success', processing: 'warning', ingesting: 'warning', queued: 'info', failed: 'error' } as Record<string, string>)[s] ?? 'grey';
}
function statusLabel(s: string): string {
  const m: Record<string, string> = { done: 'เสร็จสิ้น', exported: 'ส่งออกแล้ว', ingested: 'นำเข้าแล้ว', processing: 'กำลังประมวลผล', ingesting: 'กำลังนำเข้า', queued: 'รอดำเนินการ', failed: 'ล้มเหลว' };
  return m[s] ?? s;
}
</script>
