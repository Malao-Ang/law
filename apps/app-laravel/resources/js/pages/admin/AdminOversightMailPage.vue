<template>
  <AppShell
    :breadcrumbs="['เมนูหลัก', 'ติดตามกำกับดูแล', 'ข้อความแจ้งเตือน']"
    title="ข้อความและการส่งอีเมลแจ้งเตือน"
    subtitle="แยกข้อความใกล้ครบกำหนดกับต้องออกประกาศใหม่ หรือกดส่งเองได้โดยไม่ต้องรอรอบอัตโนมัติ"
  >
    <v-alert type="info" variant="tonal" class="mb-4" density="comfortable">
      ระบบส่งอัตโนมัติทุกวันเวลา 08:00 น. เมื่อเหลือ {{ reminderLabel }} วัน
      แต่ละจำนวนส่งครั้งเดียวต่อหนึ่งกำหนด โดยใช้เทมเพลตตามประเภทการแจ้งเตือน
    </v-alert>

    <v-alert v-if="loadError" type="error" variant="tonal" class="mb-4" density="compact">{{ loadError }}</v-alert>

    <v-row>
      <v-col cols="12" lg="7">
        <v-card border rounded="lg">
          <div class="pa-4 d-flex align-center flex-wrap ga-3">
            <span class="text-subtitle-1 font-weight-bold flex-grow-1">ข้อความอีเมล</span>
            <v-btn variant="text" class="text-none" :disabled="loading" @click="restoreDefaults">คืนค่าเริ่มต้น</v-btn>
            <v-btn color="admin-primary" class="text-none" :loading="saving" :disabled="!dirty" @click="save">
              บันทึก
            </v-btn>
          </div>
          <v-divider />
          <v-card-text>
            <v-alert v-if="formError" type="error" variant="tonal" class="mb-4" density="compact">{{ formError }}</v-alert>
            <div class="text-subtitle-2 font-weight-bold mb-2">แจ้งเตือนเมื่อเหลือกี่วัน (ใช้ร่วมกันทั้งสองประเภท)</div>
            <div class="d-flex flex-wrap ga-2 mb-3">
              <v-chip
                v-for="day in reminderDays"
                :key="day"
                closable
                color="admin-primary"
                variant="tonal"
                @click:close="removeDay(day)"
              >
                {{ day === 0 ? 'วันครบกำหนด' : `${day} วัน` }}
              </v-chip>
              <span v-if="reminderDays.length === 0" class="text-body-2 text-medium-emphasis">ยังไม่ระบุรอบแจ้งเตือน</span>
            </div>
            <div class="d-flex align-center ga-2 mb-6">
              <v-text-field
                v-model="dayDraft"
                type="number"
                min="0"
                max="365"
                label="จำนวนวัน"
                variant="outlined"
                density="comfortable"
                hide-details
                style="max-width: 180px"
                @keyup.enter="addDay"
              />
              <v-btn variant="tonal" color="admin-primary" class="text-none" @click="addDay">เพิ่มรอบ</v-btn>
            </div>

            <div class="text-subtitle-2 font-weight-bold mb-2">ประเภทข้อความ</div>
            <v-btn-toggle
              v-model="activeChannel"
              mandatory
              density="comfortable"
              divided
              variant="outlined"
              color="admin-primary"
              class="mb-6"
            >
              <v-btn
                v-for="channel in channelTabs"
                :key="channel.key"
                :value="channel.key"
                class="text-none px-4"
              >
                {{ channel.label }}
              </v-btn>
            </v-btn-toggle>

            <v-text-field
              ref="subjectField"
              v-model="activeForm.subject"
              label="หัวข้อ"
              variant="outlined"
              density="comfortable"
              counter="180"
              maxlength="180"
              hint="ใช้ {title} และ {days} ได้"
              persistent-hint
            />
            <div class="d-flex flex-wrap ga-2 mb-4">
              <v-chip
                v-for="token in subjectTokens"
                :key="token"
                size="small"
                variant="outlined"
                class="cursor-pointer"
                @click="insertToken('subject', token)"
              >
                {{ token }}
              </v-chip>
            </div>
            <v-textarea
              ref="bodyField"
              v-model="activeForm.body"
              label="ข้อความ"
              variant="outlined"
              rows="12"
              auto-grow
              counter="4000"
              maxlength="4000"
            />
            <div class="d-flex flex-wrap ga-2 mb-4">
              <v-chip
                v-for="item in settings.placeholders"
                :key="item.token"
                size="small"
                variant="tonal"
                color="admin-primary"
                class="cursor-pointer"
                @click="insertToken('body', item.token)"
              >
                {{ item.token }} {{ item.label }}
              </v-chip>
            </div>
            <v-text-field
              v-model="activeForm.link_label"
              label="ข้อความลิงก์เข้าระบบ"
              variant="outlined"
              density="comfortable"
              counter="120"
              maxlength="120"
              hint="แทนที่ {link} ในข้อความ"
              persistent-hint
            />
            <v-alert v-if="missingContext" type="warning" variant="tonal" density="compact">
              ข้อความนี้ไม่มี {title} หรือ {deadlines} ผู้รับอาจไม่เห็นว่าเป็นกฎหมายฉบับใด
            </v-alert>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" lg="5">
        <v-card border rounded="lg" class="h-100">
          <div class="pa-4">
            <div class="text-subtitle-1 font-weight-bold">ตัวอย่างที่ผู้รับจะเห็น</div>
            <div class="text-caption text-medium-emphasis">
              {{ channelLabel(activeChannel) }} —
              {{ previewItem ? previewItem.title : 'ตัวอย่าง เมื่อยังไม่มีรายการที่ส่งได้' }}
            </div>
          </div>
          <v-divider />
          <v-card-text>
            <div class="text-caption text-medium-emphasis mb-1">หัวข้อ</div>
            <div class="font-weight-bold mb-4">{{ preview.subject || '—' }}</div>
            <div class="text-caption text-medium-emphasis mb-1">ข้อความ</div>
            <div class="mail-preview">{{ preview.body }}</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-card border rounded="lg" class="mt-4">
      <div class="pa-4 d-flex align-center flex-wrap ga-3">
        <div class="flex-grow-1">
          <div class="text-subtitle-1 font-weight-bold">รายการที่ส่งได้</div>
          <div class="text-caption text-medium-emphasis">ถึงรอบวันนี้ {{ settings.pending_count }} ฉบับ</div>
        </div>
        <v-btn
          color="admin-primary"
          class="text-none"
          prepend-icon="mdi-send"
          :disabled="settings.pending_count === 0 || loading"
          :loading="sending && confirmTarget === null && confirmOpen === false && sendAllBusy"
          @click="askSend(null)"
        >
          ส่งทั้งหมดที่ถึงรอบ
        </v-btn>
      </div>
      <v-divider />
      <v-progress-linear v-if="loading" indeterminate color="admin-primary" />
      <v-table density="comfortable" class="admin-table">
        <thead>
          <tr>
            <th class="text-center">กฎหมาย</th>
            <th class="text-center">ประเภท</th>
            <th class="text-center">ผู้รับ</th>
            <th class="text-center">กำหนดที่จะแจ้ง</th>
            <th class="text-center">สถานะ</th>
            <th class="text-center" />
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && settings.items.length === 0">
            <td colspan="6" class="text-center text-medium-emphasis py-6">
              ไม่มีรายการที่ส่งได้ ต้องติดตามกฎหมาย มีอีเมลผู้รับ และมีกำหนดที่ยังไม่บันทึกวันแล้วเสร็จ
            </td>
          </tr>
          <tr
            v-for="item in settings.items"
            :key="`${item.document_id}:${item.channel}`"
            class="cursor-pointer"
            :class="{ 'bg-grey-lighten-4': previewItemKey === itemKey(item) }"
            @click="selectItem(item)"
          >
            <td class="font-weight-medium" :class="isEmptyTableValue(item.title) ? 'text-center' : 'text-start'">{{ tableValue(item.title) }}</td>
            <td class="text-center">
              <v-chip size="small" variant="tonal" :color="item.channel === 'proclamation' ? 'secondary' : 'admin-primary'">
                {{ channelLabel(item.channel) }}
              </v-chip>
            </td>
            <td :class="isEmptyTableValue(item.emails.join(', ')) ? 'text-center' : 'text-start'">{{ tableValue(item.emails.join(', ')) }}</td>
            <td class="text-center">
              <div v-for="hit in item.hits" :key="`${hit.date}-${hit.label}`">
                {{ hit.label }} {{ hit.display_date }} ({{ daysPhrase(hit.days_left) }})
              </div>
            </td>
            <td class="text-center">
              <v-chip size="small" variant="tonal" :color="statusColor(item)">{{ statusLabel(item) }}</v-chip>
            </td>
            <td class="text-center">
              <v-btn
                size="small"
                variant="tonal"
                color="admin-primary"
                class="text-none"
                @click.stop="askSend(item)"
              >
                ส่งฉบับนี้
              </v-btn>
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <v-dialog v-model="confirmOpen" max-width="560">
      <v-card>
        <v-card-title>ส่งอีเมลแจ้งเตือน</v-card-title>
        <v-card-text>
          <p class="mb-2">{{ confirmText }}</p>
          <p v-if="dirty" class="text-body-2 text-medium-emphasis mb-0">ระบบจะบันทึกข้อความที่แก้ไว้ก่อนส่ง</p>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" class="text-none" :disabled="sending" @click="confirmOpen = false">ยกเลิก</v-btn>
          <v-btn color="admin-primary" class="text-none" :loading="sending" @click="confirmSend">ส่ง</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </AppShell>
</template>

<script setup lang="ts">
import { tableValue, isEmptyTableValue } from '../../utils/tableValue';
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { fetchOversightMail, saveOversightMail, sendOversightMail } from '../../api/client';
import AppShell from '../../components/shared/AppShell.vue';
import { useSnackbarStore } from '../../stores/snackbarStore';
import type {
  OversightMailChannel,
  OversightMailItem,
  OversightMailSettings,
  OversightMailTemplate,
} from '../../types/oversight';

const subjectTokens = ['{title}', '{days}'];
const snackbar = useSnackbarStore();

const emptyTemplate = (channel: OversightMailChannel): OversightMailTemplate => ({
  subject: '',
  body: '',
  link_label: '',
  reminder_days: [30, 14, 7],
  channel,
});

const settings = ref<OversightMailSettings>({
  templates: {
    deadline: emptyTemplate('deadline'),
    proclamation: emptyTemplate('proclamation'),
  },
  defaults: {
    deadline: emptyTemplate('deadline'),
    proclamation: emptyTemplate('proclamation'),
  },
  channels: [
    { key: 'deadline', label: 'ใกล้ครบกำหนด' },
    { key: 'proclamation', label: 'ออกประกาศใหม่' },
  ],
  placeholders: [],
  reminder_days: [30, 14, 7],
  pending_count: 0,
  items: [],
});

const forms = reactive<Record<OversightMailChannel, OversightMailTemplate>>({
  deadline: emptyTemplate('deadline'),
  proclamation: emptyTemplate('proclamation'),
});
const saved = ref<Record<OversightMailChannel, OversightMailTemplate>>({
  deadline: emptyTemplate('deadline'),
  proclamation: emptyTemplate('proclamation'),
});
const reminderDays = ref<number[]>([30, 14, 7]);
const savedReminderDays = ref<number[]>([30, 14, 7]);
const activeChannel = ref<OversightMailChannel>('deadline');
const loading = ref(false);
const saving = ref(false);
const sending = ref(false);
const sendAllBusy = ref(false);
const loadError = ref('');
const formError = ref('');
const selectedKey = ref('');
const confirmOpen = ref(false);
const confirmTarget = ref<OversightMailItem | null>(null);
const subjectField = ref<{ $el?: HTMLElement } | null>(null);
const bodyField = ref<{ $el?: HTMLElement } | null>(null);
const dayDraft = ref('');

const channelTabs = computed(() => settings.value.channels.length
  ? settings.value.channels
  : [
      { key: 'deadline' as const, label: 'ใกล้ครบกำหนด' },
      { key: 'proclamation' as const, label: 'ออกประกาศใหม่' },
    ]);
const activeForm = computed(() => forms[activeChannel.value]);
const reminderLabel = computed(() => reminderDays.value.join(' / ') || '—');
const dirty = computed(() => JSON.stringify(formSnapshot()) !== JSON.stringify({
  reminder_days: savedReminderDays.value,
  deadline: channelSnapshot('deadline', saved.value.deadline),
  proclamation: channelSnapshot('proclamation', saved.value.proclamation),
}));
const missingContext = computed(() => !activeForm.value.body.includes('{title}') && !activeForm.value.body.includes('{deadlines}'));
const channelItems = computed(() => settings.value.items.filter((item) => item.channel === activeChannel.value));
const previewItem = computed(() => {
  const selected = settings.value.items.find((item) => itemKey(item) === selectedKey.value);
  if (selected) return selected;
  return channelItems.value.find((item) => item.due_now)
    ?? channelItems.value[0]
    ?? settings.value.items.find((item) => item.due_now)
    ?? settings.value.items[0]
    ?? null;
});
const previewItemKey = computed(() => (previewItem.value ? itemKey(previewItem.value) : ''));
const preview = computed(() => renderPreview(previewItem.value));
const confirmText = computed(() => {
  const target = confirmTarget.value;
  if (!target) {
    return `ส่งอีเมลตามข้อความนี้ให้รายการที่ถึงรอบวันนี้ ${settings.value.pending_count} ฉบับ (แยกตามประเภทเทมเพลต)`;
  }
  return `ส่งถึง ${target.emails.join(', ')} สำหรับ «${target.title}» (${channelLabel(target.channel)}) — ระบบจะส่งทุกประเภทของกฎหมายฉบับนี้ที่มีกำหนดเปิดอยู่`;
});

onMounted(() => {
  void load();
});

function itemKey(item: OversightMailItem): string {
  return `${item.document_id}:${item.channel}`;
}

function channelLabel(channel: OversightMailChannel): string {
  return channelTabs.value.find((item) => item.key === channel)?.label
    ?? (channel === 'proclamation' ? 'ออกประกาศใหม่' : 'ใกล้ครบกำหนด');
}

function normalizeDays(days: number[]): number[] {
  return [...new Set(days.filter((day) => Number.isInteger(day) && day >= 0 && day <= 365))].sort((a, b) => b - a);
}

function channelSnapshot(channel: OversightMailChannel, source: OversightMailTemplate) {
  return {
    subject: source.subject,
    body: source.body,
    link_label: source.link_label,
  };
}

function formSnapshot() {
  return {
    reminder_days: normalizeDays(reminderDays.value),
    deadline: channelSnapshot('deadline', forms.deadline),
    proclamation: channelSnapshot('proclamation', forms.proclamation),
  };
}

function applyTemplates(payload: OversightMailSettings): void {
  const days = normalizeDays(payload.reminder_days ?? payload.templates.deadline.reminder_days ?? [30, 14, 7]);
  reminderDays.value = days;
  savedReminderDays.value = [...days];
  (['deadline', 'proclamation'] as OversightMailChannel[]).forEach((channel) => {
    const template = payload.templates[channel] ?? emptyTemplate(channel);
    forms[channel].subject = template.subject;
    forms[channel].body = template.body;
    forms[channel].link_label = template.link_label;
    forms[channel].reminder_days = days;
    forms[channel].channel = channel;
    saved.value[channel] = {
      subject: template.subject,
      body: template.body,
      link_label: template.link_label,
      reminder_days: days,
      channel,
    };
  });
}

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = '';
  try {
    settings.value = await fetchOversightMail();
    applyTemplates(settings.value);
    if (!selectedKey.value && settings.value.items[0]) {
      const preferred = settings.value.items.find((item) => item.due_now && item.channel === activeChannel.value)
        ?? settings.value.items.find((item) => item.due_now)
        ?? settings.value.items[0];
      selectItem(preferred);
    }
  } catch (error) {
    loadError.value = error instanceof Error ? error.message : 'โหลดข้อมูลไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

function selectItem(item: OversightMailItem): void {
  selectedKey.value = itemKey(item);
  activeChannel.value = item.channel;
}

function validateDays(): boolean {
  if (normalizeDays(reminderDays.value).length === 0) {
    formError.value = 'ต้องมีอย่างน้อย 1 รอบแจ้งเตือน';
    return false;
  }
  return true;
}

async function save(): Promise<boolean> {
  formError.value = '';
  if (!validateDays()) return false;
  saving.value = true;
  try {
    await saveOversightMail(formSnapshot());
    snackbar.success('บันทึกการแจ้งเตือนแล้ว');
    await load();
    return true;
  } catch (error) {
    formError.value = error instanceof Error ? error.message : 'บันทึกไม่สำเร็จ';
    return false;
  } finally {
    saving.value = false;
  }
}

function restoreDefaults(): void {
  const channel = activeChannel.value;
  const defaults = settings.value.defaults[channel] ?? emptyTemplate(channel);
  forms[channel].subject = defaults.subject;
  forms[channel].body = defaults.body;
  forms[channel].link_label = defaults.link_label;
  reminderDays.value = normalizeDays(settings.value.defaults.deadline.reminder_days ?? [30, 14, 7]);
  formError.value = '';
}

function addDay(): void {
  const raw = String(dayDraft.value).trim();
  if (!/^\d+$/.test(raw)) {
    formError.value = 'จำนวนวันต้องเป็นจำนวนเต็ม 0 ถึง 365';
    return;
  }
  const day = Number(raw);
  if (day > 365) {
    formError.value = 'จำนวนวันต้องเป็นจำนวนเต็ม 0 ถึง 365';
    return;
  }
  if (reminderDays.value.includes(day)) {
    formError.value = 'มีจำนวนวันนี้อยู่แล้ว';
    return;
  }
  if (reminderDays.value.length >= 8) {
    formError.value = 'เพิ่มรอบแจ้งเตือนได้ไม่เกิน 8 รอบ';
    return;
  }
  reminderDays.value = normalizeDays([...reminderDays.value, day]);
  dayDraft.value = '';
  formError.value = '';
}

function removeDay(day: number): void {
  reminderDays.value = reminderDays.value.filter((item) => item !== day);
}

function askSend(item: OversightMailItem | null): void {
  confirmTarget.value = item;
  sendAllBusy.value = item === null;
  confirmOpen.value = true;
}

async function confirmSend(): Promise<void> {
  sending.value = true;
  formError.value = '';
  try {
    if (dirty.value) {
      if (!validateDays()) return;
      const stored = await saveOversightMail(formSnapshot());
      settings.value.templates = stored.templates;
      applyTemplates({
        ...settings.value,
        templates: stored.templates,
        reminder_days: stored.reminder_days,
      });
    }
    const result = await sendOversightMail(confirmTarget.value?.document_id);
    confirmOpen.value = false;
    if (result.failed.length > 0 && result.sent === 0) {
      snackbar.error(result.failed[0]?.message || 'ส่งไม่สำเร็จ');
    } else if (result.failed.length > 0) {
      snackbar.error(`ส่งสำเร็จ ${result.sent} ฉบับ และส่งไม่สำเร็จ ${result.failed.length} ฉบับ`);
    } else if (result.sent === 0) {
      snackbar.info('ไม่มีรายการที่ถึงรอบส่ง');
    } else {
      snackbar.success(`ส่งอีเมลแล้ว ${result.sent.toLocaleString('th-TH')} ฉบับ`);
    }
    await load();
  } catch (error) {
    formError.value = error instanceof Error ? error.message : 'ส่งไม่สำเร็จ';
    confirmOpen.value = false;
  } finally {
    sending.value = false;
    sendAllBusy.value = false;
  }
}

function insertToken(field: 'subject' | 'body', token: string): void {
  const target = field === 'subject' ? subjectField.value : bodyField.value;
  const current = activeForm.value[field];
  const el = target?.$el?.querySelector('textarea, input') as HTMLTextAreaElement | HTMLInputElement | null;
  const start = el?.selectionStart ?? current.length;
  const end = el?.selectionEnd ?? start;
  activeForm.value[field] = current.slice(0, start) + token + current.slice(end);
  void nextTick(() => {
    el?.focus();
    const pos = start + token.length;
    el?.setSelectionRange(pos, pos);
  });
}

function renderPreview(item: OversightMailItem | null): { subject: string; body: string } {
  const title = item?.title ?? 'ตัวอย่างกฎหมาย';
  const hits = item?.hits?.length
    ? item.hits
    : [{
        label: activeChannel.value === 'proclamation' ? 'ออกประกาศฉบับใหม่ทั้งฉบับ' : 'กำหนดเวลาออกประกาศ',
        display_date: '06/11/2569',
        days_left: 14,
        date: '',
      }];
  const days = [...new Set(hits.map((hit) => hit.days_left))].sort((a, b) => a - b).join('/');
  const deadlines = hits
    .map((hit) => `• ${hit.label} วันที่ ${hit.display_date} (${daysPhrase(hit.days_left)})`)
    .join('\n');
  const form = activeForm.value;
  const replace = (text: string) => text
    .replaceAll('{title}', title)
    .replaceAll('{days}', days)
    .replaceAll('{deadlines}', deadlines)
    .replaceAll('{link}', form.link_label || 'ระบบติดตามการกำกับดูแล');
  return {
    subject: replace(form.subject).replace(/\s+/g, ' ').trim(),
    body: replace(form.body),
  };
}

function daysPhrase(days: number): string {
  if (days < 0) return `เกิน ${Math.abs(days).toLocaleString('th-TH')} วัน`;
  if (days === 0) return 'ครบกำหนดวันนี้';
  return `เหลือ ${days.toLocaleString('th-TH')} วัน`;
}

function statusLabel(item: OversightMailItem): string {
  if (item.due_now) return 'ถึงรอบส่ง';
  if (item.already_sent) return 'ส่งรอบนี้แล้ว';
  return 'ยังไม่ถึงรอบ';
}

function statusColor(item: OversightMailItem): string {
  if (item.due_now) return 'warning';
  if (item.already_sent) return 'success';
  return 'info';
}
</script>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}

.mail-preview {
  white-space: pre-wrap;
  line-height: 1.6;
}
</style>
