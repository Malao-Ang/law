<template>
  <AppShell
    :breadcrumbs="['เมนูหลัก', 'ติดตามกำกับดูแล']"
    title="ติดตามการกำกับดูแลและแจ้งเตือน"
    subtitle="ดูวันต้องทำภายในจากความสัมพันธ์ และกำหนดผู้รับอีเมลแจ้งเตือน"
  >
    <template #title-actions>
      <v-btn
        to="/admin/oversight-mail"
        color="admin-primary"
        variant="tonal"
        class="text-none"
        prepend-icon="mdi-email-edit-outline"
      >
        ข้อความและการส่งอีเมล
      </v-btn>
    </template>
    <v-alert type="info" variant="tonal" class="mb-4" density="comfortable">
      ระบบส่งอัตโนมัติเมื่อเหลือ {{ reminderLabel }} วันก่อนกำหนดต้องออกประกาศฉบับใหม่
      หรือกด «ส่งอีเมล» ที่รายการเพื่อส่งทันทีโดยไม่ต้องรอรอบ
    </v-alert>

    <v-card border rounded="lg">
      <div class="pa-4 d-flex align-center flex-wrap ga-3">
        <span class="text-subtitle-1 font-weight-bold flex-grow-1">กฎหมายทั้งหมด</span>
        <v-btn-toggle v-model="listFilter" mandatory density="comfortable" divided variant="outlined" color="admin-primary" class="oversight-filter">
          <v-btn value="all" class="text-none">ทั้งหมด</v-btn>
          <v-btn value="overdue" class="text-none">เกินกำหนด</v-btn>
          <v-btn value="next" class="text-none">เดือนถัดไป</v-btn>
        </v-btn-toggle>
      </div>
      <v-divider />
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" />
      <v-table density="comfortable" class="oversight-table">
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
            <td :class="isEmptyTableValue(item.title) ? 'text-center' : 'text-start'">
              <div class="font-weight-medium">{{ tableValue(item.title) }}</div>
              <div class="text-caption text-medium-emphasis">{{ tableValue(item.law_type) }}</div>
            </td>
            <td class="text-center">
              <v-chip v-if="item.status" size="small" :color="statusColor(item.status)" variant="tonal">
                {{ item.status }}
              </v-chip>
              <span v-else class="text-medium-emphasis"> </span>
            </td>
            <td class="text-center">
              <div :class="item.overdue ? 'text-error font-weight-bold' : ''">{{ tableValue(formatThaiDate(item.due_date)) }}</div>
              <div class="text-caption text-medium-emphasis">{{ daysLabel(item.days_left) }}</div>
            </td>
            <td :class="isEmptyTableValue(item.primary.name || item.primary.email || '-') ? 'text-center' : 'text-start'">{{ tableValue(item.primary.name || item.primary.email || '-') }}</td>
            <td :class="isEmptyTableValue(item.backup.name || item.backup.email || '-') ? 'text-center' : 'text-start'">{{ tableValue(item.backup.name || item.backup.email || '-') }}</td>
            <td class="text-center">
              <div class="d-flex justify-center flex-wrap ga-2">
                <v-btn
                  size="small"
                  variant="tonal"
                  color="admin-primary"
                  class="text-none"
                  prepend-icon="mdi-send"
                  :disabled="!canSendNow(item)"
                  :loading="sendingId === item.document_id"
                  @click="askSend(item)"
                >
                  ส่งอีเมล
                </v-btn>
                <v-btn size="small" variant="tonal" color="admin-primary" class="text-none" @click="openEditor(item)">
                  ปรับปรุง
                </v-btn>
              </div>
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <v-dialog v-model="sendConfirmOpen" max-width="560">
      <v-card>
        <v-card-title>ส่งอีเมลแจ้งเตือนทันที</v-card-title>
        <v-card-text>
          <p class="mb-0">
            ส่งถึงผู้รับของ «{{ sendTarget?.title || '' }}» ตอนนี้ โดยไม่ต้องรอส่งอัตโนมัติ
          </p>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" class="text-none" :disabled="sendingId !== null" @click="sendConfirmOpen = false">ยกเลิก</v-btn>
          <v-btn color="admin-primary" class="text-none" :loading="sendingId !== null" @click="confirmSend">ส่งทันที</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="dialog" max-width="720" scrollable>
      <v-card rounded="xl" class="oversight-dialog">
        <div class="oversight-dialog__header">
          <div class="min-width-0">
            <h2 class="oversight-dialog__title">ปรับปรุงการกำกับดูแล</h2>
            <p class="oversight-dialog__subtitle">{{ form.title }}</p>
          </div>
          <v-btn icon="mdi-close" variant="text" size="small" aria-label="ปิด" :disabled="saving" @click="dialog = false" />
        </div>
        <v-divider />

        <v-card-text class="oversight-dialog__body">
          <v-alert v-if="formError" type="error" variant="tonal" class="mb-1" density="compact">{{ formError }}</v-alert>

          <section class="oversight-dialog__section">
            <div class="oversight-dialog__section-title">
              <v-icon icon="mdi-calendar-clock-outline" size="18" color="admin-primary" />
              วันต้องทำภายใน
            </div>
            <v-card variant="outlined" class="oversight-dialog__dues">
              <div v-if="form.due_dates.length === 0" class="text-medium-emphasis pa-4 text-center">
                ยังไม่มีกำหนดจากความสัมพันธ์
              </div>
              <v-list v-else density="comfortable" class="py-1">
                <v-list-item v-for="(entry, index) in form.due_dates" :key="`${entry.date}-${index}`">
                  <v-list-item-title>{{ entry.label }}</v-list-item-title>
                  <v-list-item-subtitle>{{ entry.display_date || formatThaiDate(entry.date) }}</v-list-item-subtitle>
                </v-list-item>
              </v-list>
            </v-card>
          </section>

          <section class="oversight-dialog__section">
            <div class="oversight-dialog__section-title">
              <v-icon icon="mdi-account-multiple-outline" size="18" color="admin-primary" />
              ผู้รับอีเมลแจ้งเตือน
            </div>
            <v-row dense>
              <v-col cols="12" md="6">
                <v-card variant="outlined" class="oversight-dialog__contact">
                  <div class="oversight-dialog__contact-label">ผู้รับผิดชอบหลัก</div>
                  <v-text-field v-model="form.primary.name" label="ชื่อ" variant="outlined" density="comfortable" hide-details="auto" class="mb-3" />
                  <v-text-field v-model="form.primary.email" label="อีเมลรับแจ้งเตือน" type="email" variant="outlined" density="comfortable" hide-details="auto" />
                </v-card>
              </v-col>
              <v-col cols="12" md="6">
                <v-card variant="outlined" class="oversight-dialog__contact">
                  <div class="oversight-dialog__contact-label">ผู้ประสานงานสำรอง</div>
                  <v-text-field v-model="form.backup.name" label="ชื่อ" variant="outlined" density="comfortable" hide-details="auto" class="mb-3" />
                  <v-text-field v-model="form.backup.email" label="อีเมลรับแจ้งเตือน" type="email" variant="outlined" density="comfortable" hide-details="auto" />
                </v-card>
              </v-col>
            </v-row>
          </section>
        </v-card-text>

        <v-divider />
        <v-card-actions class="oversight-dialog__footer">
          <v-btn variant="outlined" class="text-none" :disabled="saving" @click="dialog = false">ยกเลิก</v-btn>
          <v-btn
            color="admin-primary"
            variant="flat"
            prepend-icon="mdi-content-save-outline"
            class="text-none"
            :loading="saving"
            @click="save"
          >
            บันทึก
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </AppShell>
</template>

<script setup lang="ts">
import { tableValue, isEmptyTableValue } from '../../utils/tableValue';
import { computed, onMounted, reactive, ref } from 'vue';
import { fetchOversightSummary, saveOversight, sendOversightMail } from '../../api/client';
import AppShell from '../../components/shared/AppShell.vue';
import { useSnackbarStore } from '../../stores/snackbarStore';
import type { OversightDueDate, OversightItem, OversightSummary } from '../../types/oversight';

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

const snackbar = useSnackbarStore();
const summary = ref<OversightSummary>(emptySummary());
const loading = ref(false);
const dialog = ref(false);
const saving = ref(false);
const formError = ref('');
const listFilter = ref<'all' | 'overdue' | 'next'>('all');
const sendConfirmOpen = ref(false);
const sendTarget = ref<OversightItem | null>(null);
const sendingId = ref<string | null>(null);
const form = reactive({
  document_id: '',
  title: '',
  due_dates: [] as OversightDueDate[],
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
  form.due_dates = (item.due_dates || []).map((entry) => ({ ...entry }));
  form.primary = { name: item.primary.name, email: item.primary.email };
  form.backup = { name: item.backup.name, email: item.backup.email };
  dialog.value = true;
}

function canSendNow(item: OversightItem): boolean {
  if (item.closed) return false;
  if (!item.due_date) return false;
  const emails = [item.primary.email, item.backup.email].map((value) => value.trim()).filter(Boolean);
  return emails.length > 0;
}

function askSend(item: OversightItem): void {
  if (!canSendNow(item)) return;
  sendTarget.value = item;
  sendConfirmOpen.value = true;
}

async function confirmSend(): Promise<void> {
  const target = sendTarget.value;
  if (!target) return;
  sendingId.value = target.document_id;
  try {
    const result = await sendOversightMail(target.document_id);
    sendConfirmOpen.value = false;
    sendTarget.value = null;
    if (result.sent > 0) {
      snackbar.success(`ส่งอีเมลแล้ว ${result.sent.toLocaleString('th-TH')} ฉบับ`);
    } else {
      snackbar.info('ไม่มีกำหนดที่ส่งได้สำหรับกฎหมายนี้');
    }
  } catch (error) {
    snackbar.error(error instanceof Error ? error.message : 'ส่งไม่สำเร็จ');
  } finally {
    sendingId.value = null;
  }
}

async function save(): Promise<void> {
  formError.value = '';
  saving.value = true;
  try {
    await saveOversight(form.document_id, {
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
  if (!value) return ' ';
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

<style scoped>
.oversight-filter { height: 40px; border-radius: 8px; }
.oversight-filter :deep(.v-btn) { min-width: 104px; font-weight: 500; }
.oversight-table :deep(tbody td) { padding-block: 12px; }
.oversight-table :deep(tbody tr) { height: 68px; }

.oversight-dialog {
  display: flex;
  flex-direction: column;
  max-height: 90dvh;
  overflow: hidden;
}

.oversight-dialog__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 24px 14px;
}

.oversight-dialog__title {
  margin: 0;
  color: #1e3a8a;
  font-size: 1.125rem;
  font-weight: 700;
  line-height: 1.35;
}

.oversight-dialog__subtitle {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 0.8125rem;
  line-height: 1.45;
  overflow-wrap: anywhere;
}

.oversight-dialog__body {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 18px 24px 22px;
}

.oversight-dialog__section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.oversight-dialog__section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #1f2933;
  font-size: 0.875rem;
  font-weight: 700;
}

.oversight-dialog__dues {
  border-radius: 12px;
  overflow: hidden;
  background: #f8fafc;
}

.oversight-dialog__contact {
  border-radius: 12px;
  padding: 14px 16px;
  height: 100%;
  background: #f8fafc;
}

.oversight-dialog__contact-label {
  font-size: 0.8125rem;
  font-weight: 700;
  color: #334155;
  margin-bottom: 12px;
}

.oversight-dialog__footer {
  justify-content: flex-end;
  gap: 8px;
  background: #f8fafc;
  padding: 14px 24px;
}

.min-width-0 {
  min-width: 0;
}
</style>
