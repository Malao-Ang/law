<template>
  <AppShell
    :breadcrumbs="['เมนูหลัก', 'ติดตามกำกับดูแล']"
    title="ติดตามการกำกับดูแลและแจ้งเตือน"
    subtitle="ปรับสถานะ วันแล้วเสร็จ และผู้รับอีเมลของกฎหมายลำดับรอง"
  >
    <v-alert type="info" variant="tonal" class="mb-4" density="comfortable">
      ระบบส่งอีเมลถึงผู้รับผิดชอบหลักและผู้ประสานงานสำรองเมื่อเหลือ
      {{ reminderLabel }} วัน ก่อนกำหนดเวลาออกประกาศ หรือก่อนกำหนดแล้วเสร็จของขั้นตอนที่ยังไม่บันทึกวันแล้วเสร็จ
    </v-alert>

    <v-card border rounded="lg">
      <div class="pa-4 d-flex align-center flex-wrap ga-3">
        <span class="text-subtitle-1 font-weight-bold flex-grow-1">กฎหมายทั้งหมด</span>
        <v-btn-toggle v-model="listFilter" mandatory density="compact" divided>
          <v-btn value="all" class="text-none">ทั้งหมด</v-btn>
          <v-btn value="overdue" class="text-none">เกินกำหนด</v-btn>
          <v-btn value="next" class="text-none">เดือนถัดไป</v-btn>
        </v-btn-toggle>
      </div>
      <v-divider />
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" />
      <v-table density="comfortable">
        <thead>
          <tr>
            <th>ชื่อกฎหมาย</th>
            <th>สถานะ</th>
            <th>กำหนดออกประกาศ</th>
            <th>ผู้รับผิดชอบหลัก</th>
            <th>ผู้ประสานงานสำรอง</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && visibleItems.length === 0">
            <td colspan="6" class="text-center text-medium-emphasis py-6">ไม่พบรายการ</td>
          </tr>
          <tr v-for="item in visibleItems" :key="item.document_id">
            <td>
              <div class="font-weight-medium">{{ item.title }}</div>
              <div class="text-caption text-medium-emphasis">{{ item.law_type || 'ไม่ระบุประเภท' }}</div>
            </td>
            <td>
              <v-chip size="small" :color="statusColor(item.status)" variant="tonal">
                {{ item.status || 'ยังไม่ติดตาม' }}
              </v-chip>
            </td>
            <td>
              <div :class="item.overdue ? 'text-error font-weight-bold' : ''">{{ formatThaiDate(item.publish_due_date) }}</div>
              <div class="text-caption text-medium-emphasis">{{ daysLabel(item.days_left) }}</div>
            </td>
            <td>{{ item.primary.name || item.primary.email || '—' }}</td>
            <td>{{ item.backup.name || item.backup.email || '—' }}</td>
            <td class="text-right">
              <v-btn size="small" variant="tonal" color="admin-primary" class="text-none" @click="openEditor(item)">
                ปรับปรุง
              </v-btn>
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="920">
      <v-card>
        <v-card-title class="text-wrap">{{ form.title }}</v-card-title>
        <v-card-text>
          <v-alert v-if="formError" type="error" variant="tonal" class="mb-4" density="compact">{{ formError }}</v-alert>
          <v-row>
            <v-col cols="12" md="6">
              <v-select
                v-model="form.status"
                :items="summary.statuses"
                item-title="label"
                item-value="label"
                label="สถานะการดำเนินการ"
                variant="outlined"
                density="comfortable"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="form.publish_due_date"
                type="date"
                label="กำหนดเวลาออกประกาศ"
                variant="outlined"
                density="comfortable"
              />
            </v-col>
          </v-row>

          <div class="text-subtitle-2 font-weight-bold mb-2">วันแล้วเสร็จของแต่ละขั้นตอน</div>
          <v-table density="compact" class="mb-4">
            <thead>
              <tr>
                <th>ขั้นตอน</th>
                <th>กำหนดแล้วเสร็จ</th>
                <th>วันแล้วเสร็จ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="step in form.steps" :key="step.key">
                <td>{{ step.label }}</td>
                <td>
                  <v-text-field v-model="step.due_date" type="date" density="compact" variant="outlined" hide-details />
                </td>
                <td>
                  <v-text-field v-model="step.completed_on" type="date" density="compact" variant="outlined" hide-details />
                </td>
              </tr>
            </tbody>
          </v-table>

          <v-row>
            <v-col cols="12" md="6">
              <div class="text-subtitle-2 font-weight-bold mb-2">ผู้รับผิดชอบหลัก</div>
              <v-text-field v-model="form.primary.name" label="ชื่อ" variant="outlined" density="comfortable" />
              <v-text-field v-model="form.primary.email" label="อีเมลรับแจ้งเตือน" type="email" variant="outlined" density="comfortable" />
            </v-col>
            <v-col cols="12" md="6">
              <div class="text-subtitle-2 font-weight-bold mb-2">ผู้ประสานงานสำรอง</div>
              <v-text-field v-model="form.backup.name" label="ชื่อ" variant="outlined" density="comfortable" />
              <v-text-field v-model="form.backup.email" label="อีเมลรับแจ้งเตือน" type="email" variant="outlined" density="comfortable" />
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" class="text-none" @click="dialog = false">ยกเลิก</v-btn>
          <v-btn color="admin-primary" class="text-none" :loading="saving" @click="save">บันทึก</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { fetchOversightSummary, saveOversight } from '../../api/client';
import AppShell from '../../components/shared/AppShell.vue';
import type { OversightItem, OversightSummary } from '../../types/oversight';

interface FormStep {
  key: string;
  label: string;
  due_date: string;
  completed_on: string;
}

const emptySummary = (): OversightSummary => ({
  as_of: '',
  next_month_label: '',
  reminder_days: [30, 14, 7],
  statuses: [],
  totals: { tracked: 0, overdue_publication: 0, on_time: 0, late: 0, compliance_rate: null },
  by_status: [],
  next_month: [],
  items: [],
});

const summary = ref<OversightSummary>(emptySummary());
const loading = ref(false);
const dialog = ref(false);
const saving = ref(false);
const formError = ref('');
const listFilter = ref<'all' | 'overdue' | 'next'>('all');
const form = reactive({
  document_id: '',
  title: '',
  status: '',
  publish_due_date: '',
  steps: [] as FormStep[],
  primary: { name: '', email: '' },
  backup: { name: '', email: '' },
});

const reminderLabel = computed(() => summary.value.reminder_days.join(' / '));
const visibleItems = computed(() => {
  if (listFilter.value === 'overdue') return summary.value.items.filter((item) => item.overdue);
  if (listFilter.value === 'next') return summary.value.items.filter((item) => item.in_next_month);
  return summary.value.items;
});

onMounted(() => {
  void load();
});

async function load(): Promise<void> {
  loading.value = true;
  try {
    summary.value = await fetchOversightSummary();
  } finally {
    loading.value = false;
  }
}

function openEditor(item: OversightItem): void {
  formError.value = '';
  form.document_id = item.document_id;
  form.title = item.title;
  form.status = item.status || summary.value.statuses[0]?.label || '';
  form.publish_due_date = item.publish_due_date || '';
  form.steps = item.steps.map((step) => ({
    key: step.key,
    label: step.label,
    due_date: step.due_date || '',
    completed_on: step.completed_on || '',
  }));
  form.primary = { name: item.primary.name, email: item.primary.email };
  form.backup = { name: item.backup.name, email: item.backup.email };
  dialog.value = true;
}

async function save(): Promise<void> {
  formError.value = '';
  saving.value = true;
  try {
    await saveOversight(form.document_id, {
      status: form.status,
      publish_due_date: form.publish_due_date || null,
      steps: form.steps.map((step) => ({
        key: step.key,
        due_date: step.due_date || null,
        completed_on: step.completed_on || null,
      })),
      primary: { name: form.primary.name.trim(), email: form.primary.email.trim() },
      backup: { name: form.backup.name.trim(), email: form.backup.email.trim() },
    });
    dialog.value = false;
    await load();
  } catch (error) {
    formError.value = error instanceof Error ? error.message : 'บันทึกไม่สำเร็จ';
  } finally {
    saving.value = false;
  }
}

function formatThaiDate(value: string | null): string {
  if (!value) return '—';
  const [year, month, day] = value.split('-');
  if (!year || !month || !day) return value;
  return `${day}/${month}/${Number(year) + 543}`;
}

function daysLabel(days: number | null): string {
  if (days === null) return '';
  if (days < 0) return `เกิน ${Math.abs(days).toLocaleString('th-TH')} วัน`;
  if (days === 0) return 'ครบกำหนดวันนี้';
  return `เหลือ ${days.toLocaleString('th-TH')} วัน`;
}

function statusColor(status: string | null): string {
  if (status === 'ประกาศแล้ว') return 'success';
  if (status === 'ยกเลิก') return 'error';
  if (status === 'อนุมัติแล้ว/รอประกาศ') return 'primary';
  if (status === 'เสนอเพื่อพิจารณา') return 'warning';
  if (status === 'อยู่ระหว่างการยกร่าง') return 'info';
  return 'grey';
}
</script>
