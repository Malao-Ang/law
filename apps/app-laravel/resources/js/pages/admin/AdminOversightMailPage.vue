<template>
  <AppShell
    :breadcrumbs="['เมนูหลัก', 'ติดตามกำกับดูแล', 'ข้อความแจ้งเตือน']"
    title="ข้อความและการส่งอีเมลแจ้งเตือน"
    subtitle="แก้ข้อความที่ผู้รับผิดชอบจะได้รับ หรือกดส่งเองได้โดยไม่ต้องรอรอบอัตโนมัติ"
  >
    <v-alert type="info" variant="tonal" class="mb-4" density="comfortable">
      ระบบส่งอัตโนมัติทุกวันเวลา 08:00 น. เมื่อเหลือ {{ reminderLabel }} วัน
      โดยใช้ข้อความที่บันทึกในหน้านี้ ส่งถึงผู้รับผิดชอบหลักและผู้ประสานงานสำรอง
    </v-alert>

    <v-alert v-if="loadError" type="error" variant="tonal" class="mb-4" density="compact">{{ loadError }}</v-alert>

    <v-row>
      <v-col cols="12" lg="7">
        <v-card border rounded="lg">
          <div class="pa-4 d-flex align-center flex-wrap ga-3">
            <span class="text-subtitle-1 font-weight-bold flex-grow-1">ข้อความอีเมล</span>
            <v-btn variant="text" class="text-none" :disabled="loading" @click="restoreDefaults">ใส่ข้อความเดิม</v-btn>
            <v-btn color="admin-primary" class="text-none" :loading="saving" :disabled="!dirty" @click="save">
              บันทึกข้อความ
            </v-btn>
          </div>
          <v-divider />
          <v-card-text>
            <v-alert v-if="formError" type="error" variant="tonal" class="mb-4" density="compact">{{ formError }}</v-alert>
            <v-text-field
              ref="subjectField"
              v-model="form.subject"
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
              v-model="form.body"
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
              v-model="form.link_label"
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
      <v-table density="comfortable">
        <thead>
          <tr>
            <th>กฎหมาย</th>
            <th>ผู้รับ</th>
            <th>กำหนดที่จะแจ้ง</th>
            <th>สถานะ</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && settings.items.length === 0">
            <td colspan="5" class="text-center text-medium-emphasis py-6">
              ไม่มีรายการที่ส่งได้ ต้องติดตามกฎหมาย มีอีเมลผู้รับ และมีกำหนดที่ยังไม่บันทึกวันแล้วเสร็จ
            </td>
          </tr>
          <tr
            v-for="item in settings.items"
            :key="item.document_id"
            class="cursor-pointer"
            :class="{ 'bg-grey-lighten-4': previewItem?.document_id === item.document_id }"
            @click="selectedId = item.document_id"
          >
            <td class="font-weight-medium">{{ item.title }}</td>
            <td>{{ item.emails.join(', ') }}</td>
            <td>
              <div v-for="hit in item.hits" :key="`${hit.date}-${hit.label}`">
                {{ hit.label }} {{ hit.display_date }} ({{ daysPhrase(hit.days_left) }})
              </div>
            </td>
            <td>
              <v-chip size="small" variant="tonal" :color="statusColor(item)">{{ statusLabel(item) }}</v-chip>
            </td>
            <td class="text-right">
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
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { fetchOversightMail, saveOversightMail, sendOversightMail } from '../../api/client';
import AppShell from '../../components/shared/AppShell.vue';
import { useSnackbarStore } from '../../stores/snackbarStore';
import type { OversightMailItem, OversightMailSettings, OversightMailTemplate } from '../../types/oversight';

const subjectTokens = ['{title}', '{days}'];
const snackbar = useSnackbarStore();

const emptyTemplate = (): OversightMailTemplate => ({ subject: '', body: '', link_label: '' });
const settings = ref<OversightMailSettings>({
  template: emptyTemplate(),
  defaults: emptyTemplate(),
  placeholders: [],
  reminder_days: [30, 14, 7],
  pending_count: 0,
  items: [],
});
const saved = ref<OversightMailTemplate>(emptyTemplate());
const form = reactive<OversightMailTemplate>(emptyTemplate());
const loading = ref(false);
const saving = ref(false);
const sending = ref(false);
const sendAllBusy = ref(false);
const loadError = ref('');
const formError = ref('');
const selectedId = ref('');
const confirmOpen = ref(false);
const confirmTarget = ref<OversightMailItem | null>(null);
const subjectField = ref<{ $el?: HTMLElement } | null>(null);
const bodyField = ref<{ $el?: HTMLElement } | null>(null);

const reminderLabel = computed(() => settings.value.reminder_days.join(' / '));
const dirty = computed(() => JSON.stringify(formSnapshot()) !== JSON.stringify(saved.value));
const missingContext = computed(() => !form.body.includes('{title}') && !form.body.includes('{deadlines}'));
const previewItem = computed(() => {
  const selected = settings.value.items.find((item) => item.document_id === selectedId.value);
  if (selected) return selected;
  return settings.value.items.find((item) => item.due_now) ?? settings.value.items[0] ?? null;
});
const preview = computed(() => renderPreview(previewItem.value));
const confirmText = computed(() => {
  const target = confirmTarget.value;
  if (!target) {
    return `ส่งอีเมลตามข้อความนี้ให้กฎหมายที่ถึงรอบวันนี้ ${settings.value.pending_count} ฉบับ`;
  }
  return `ส่งถึง ${target.emails.join(', ')} สำหรับ «${target.title}»`;
});

onMounted(() => {
  void load();
});

function formSnapshot(): OversightMailTemplate {
  return { subject: form.subject, body: form.body, link_label: form.link_label };
}

function applyTemplate(template: OversightMailTemplate): void {
  form.subject = template.subject;
  form.body = template.body;
  form.link_label = template.link_label;
  saved.value = { ...template };
}

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = '';
  try {
    settings.value = await fetchOversightMail();
    applyTemplate(settings.value.template);
    if (!selectedId.value && settings.value.items[0]) {
      selectedId.value = (settings.value.items.find((item) => item.due_now) ?? settings.value.items[0]).document_id;
    }
  } catch (error) {
    loadError.value = error instanceof Error ? error.message : 'โหลดข้อมูลไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

async function save(): Promise<boolean> {
  formError.value = '';
  saving.value = true;
  try {
    const result = await saveOversightMail(formSnapshot());
    applyTemplate(result.template);
    snackbar.success('บันทึกข้อความอีเมลแล้ว');
    return true;
  } catch (error) {
    formError.value = error instanceof Error ? error.message : 'บันทึกไม่สำเร็จ';
    return false;
  } finally {
    saving.value = false;
  }
}

function restoreDefaults(): void {
  form.subject = settings.value.defaults.subject;
  form.body = settings.value.defaults.body;
  form.link_label = settings.value.defaults.link_label;
  formError.value = '';
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
      const stored = await saveOversightMail(formSnapshot());
      applyTemplate(stored.template);
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
  const current = form[field];
  const el = target?.$el?.querySelector('textarea, input') as HTMLTextAreaElement | HTMLInputElement | null;
  const start = el?.selectionStart ?? current.length;
  const end = el?.selectionEnd ?? start;
  form[field] = current.slice(0, start) + token + current.slice(end);
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
    : [{ label: 'กำหนดเวลาออกประกาศ', display_date: '06/11/2569', days_left: 14, date: '' }];
  const days = [...new Set(hits.map((hit) => hit.days_left))].sort((a, b) => a - b).join('/');
  const deadlines = hits
    .map((hit) => `• ${hit.label} วันที่ ${hit.display_date} (${daysPhrase(hit.days_left)})`)
    .join('\n');
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
