<template>
  <div>
    <v-dialog v-model="loginOpen" max-width="440">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="d-flex align-center justify-space-between">
          <span class="text-h6">เข้าสู่ระบบ</span>
          <v-btn icon="mdi-close" variant="text" @click="loginOpen = false" />
        </v-card-title>
        <v-card-text>
          <div class="d-flex align-center ga-3 mb-4">
            <v-avatar color="primary" size="40"><v-icon icon="mdi-scale-balance" /></v-avatar>
            <div>
              <div class="text-subtitle-2 font-weight-bold">e-Law</div>
              <div class="text-caption text-medium-emphasis">ระบบแสดงความคิดเห็นต่อกฎหมาย ต้องเข้าสู่ระบบก่อน</div>
            </div>
          </div>
          <v-alert v-if="loginError" type="error" variant="tonal" density="compact" class="mb-3">{{ loginError }}</v-alert>
          <v-form @submit.prevent="submitLogin">
            <v-text-field
              v-model="username"
              label="ชื่อผู้ใช้ / รหัสพนักงาน"
              placeholder="user@organization.ac.th"
              variant="outlined"
              density="comfortable"
              autocomplete="username"
              hide-details="auto"
              class="mb-3"
            />
            <v-text-field
              v-model="password"
              label="รหัสผ่าน"
              type="password"
              variant="outlined"
              density="comfortable"
              autocomplete="current-password"
              hide-details="auto"
              class="mb-4"
            />
            <v-btn type="submit" block color="primary" size="large" :loading="loggingIn" :disabled="!username.trim() || !password">
              เข้าสู่ระบบ
            </v-btn>
          </v-form>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-dialog v-model="formOpen" max-width="720" scrollable>
      <v-card rounded="xl">
        <v-card-title class="d-flex align-center justify-space-between">
          <span>แสดงความคิดเห็น / สอบถาม</span>
          <v-btn icon="mdi-close" variant="text" @click="formOpen = false" />
        </v-card-title>
        <v-card-text>
          <div class="composer-ref mb-4">
            <div class="text-caption text-medium-emphasis">เอกสารอ้างอิง</div>
            <div class="font-weight-bold">{{ documentTitle || 'กฎหมาย' }}</div>
            <div class="text-caption text-medium-emphasis">แสดงความคิดเห็นได้เฉพาะกฎหมายฉบับนี้</div>
          </div>

          <div class="text-subtitle-2 mb-2">ประเภทความคิดเห็น <span class="text-error">*</span></div>
          <v-radio-group v-model="draft.type" inline hide-details class="mb-4">
            <v-radio
              v-for="option in COMMENT_TYPES"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </v-radio-group>

          <v-textarea
            v-model="draft.body"
            label="รายละเอียดความคิดเห็น *"
            placeholder="ระบุคำถามหรือข้อเสนอแนะ พร้อมบริบทที่เกี่ยวข้อง..."
            variant="outlined"
            rows="4"
            counter="5000"
            maxlength="5000"
            hide-details="auto"
            class="mb-3"
          />
          <v-text-field
            v-model="draft.sectionRef"
            label="อ้างอิงมาตรา"
            placeholder="เช่น มาตรา 15 หรือข้อ 4"
            variant="outlined"
            density="comfortable"
            hide-details="auto"
            class="mb-3"
          />
          <v-switch
            v-model="isPublic"
            color="primary"
            hide-details
            inset
            label="เปิดเผยต่อสาธารณะ"
            class="mb-2"
          />

          <div class="text-subtitle-2 mb-2">ไฟล์แนบ (PDF, JPG, PNG, DOCX สูงสุด 5 ไฟล์ / ไฟล์ละ 10 MB)</div>
          <button type="button" class="composer-drop" @click="pickFiles">
            <v-icon icon="mdi-cloud-upload-outline" size="28" />
            <span>ลากไฟล์มาวาง หรือคลิกเพื่อเลือกไฟล์</span>
            <span class="text-caption text-medium-emphasis">ยังไม่แนบไฟล์ก็ได้</span>
          </button>
          <input ref="fileInput" class="d-none" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.docx,application/pdf,image/jpeg,image/png" @change="onPick" />
          <div v-if="draft.files.length" class="d-flex flex-column ga-1 mt-2">
            <div v-for="(file, index) in draft.files" :key="`${file.name}-${index}`" class="d-flex align-center justify-space-between">
              <span class="text-body-2">{{ file.name }}</span>
              <v-btn icon="mdi-close" size="x-small" variant="text" @click="draft.files.splice(index, 1)" />
            </div>
          </div>
          <v-alert v-if="formError" type="error" variant="tonal" density="compact" class="mt-3">{{ formError }}</v-alert>
        </v-card-text>
        <v-card-actions class="px-6 pb-5">
          <v-spacer />
          <v-btn variant="text" @click="formOpen = false">ยกเลิก</v-btn>
          <v-btn color="primary" variant="flat" append-icon="mdi-arrow-right" @click="openConfirm">ตรวจสอบข้อมูลก่อนส่ง</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="confirmOpen" max-width="560">
      <v-card rounded="xl">
        <v-card-title class="d-flex align-center justify-space-between">
          <span>ตรวจสอบข้อมูลก่อนส่ง</span>
          <v-btn icon="mdi-close" variant="text" :disabled="submitting" @click="confirmOpen = false" />
        </v-card-title>
        <v-card-text>
          <div class="confirm-grid">
            <span>ประเภท</span><strong>{{ typeLabel }}</strong>
            <span>มาตราอ้างอิง</span><strong>{{ draft.sectionRef.trim() || '—' }}</strong>
            <span>การเปิดเผย</span><strong>{{ isPublic ? 'เปิด' : 'เฉพาะฉัน' }}</strong>
            <span>ไฟล์แนบ</span><strong>{{ draft.files.length ? `${draft.files.length} ไฟล์` : 'ไม่มี' }}</strong>
          </div>
          <div class="text-subtitle-2 mt-4 mb-1">ความคิดเห็นของคุณ</div>
          <p class="confirm-body">{{ draft.body.trim() }}</p>
          <v-alert type="warning" variant="tonal" density="compact" class="mt-4">
            ความคิดเห็นที่เปิดเผยจะปรากฏต่อผู้ใช้คนอื่นทันที และเจ้าหน้าที่จะพิจารณาตอบกลับ หากไม่ผ่านเกณฑ์อาจถูกจำกัดการแสดงผล
          </v-alert>
          <v-alert v-if="submitError" type="error" variant="tonal" density="compact" class="mt-3">{{ submitError }}</v-alert>
        </v-card-text>
        <v-card-actions class="px-6 pb-5">
          <v-btn variant="outlined" :disabled="submitting" @click="confirmOpen = false">ย้อนกลับแก้ไข</v-btn>
          <v-spacer />
          <v-btn color="primary" variant="flat" :loading="submitting" @click="submit">ยืนยันส่งความคิดเห็น</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { createLawComment } from '../../api/lawComments';
import { useAuthStore } from '../../stores/authStore';
import { COMMENT_TYPES, type LawCommentType } from '../../types/lawComment';

const props = defineProps<{
  documentId: string;
  documentTitle: string;
}>();

const emit = defineEmits<{ submitted: [] }>();

const auth = useAuthStore();
const loginOpen = ref(false);
const formOpen = ref(false);
const confirmOpen = ref(false);
const loggingIn = ref(false);
const submitting = ref(false);
const loginError = ref('');
const formError = ref('');
const submitError = ref('');
const username = ref('');
const password = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
const isPublic = ref(true);

const draft = reactive({
  type: 'question' as LawCommentType,
  body: '',
  sectionRef: '',
  files: [] as File[],
});

const typeLabel = computed(() => COMMENT_TYPES.find((item) => item.value === draft.type)?.label ?? draft.type);

const ALLOWED = new Set(['pdf', 'jpg', 'jpeg', 'png', 'docx']);
const MAX_BYTES = 10 * 1024 * 1024;

function resetDraft(): void {
  draft.type = 'question';
  draft.body = '';
  draft.sectionRef = '';
  draft.files = [];
  isPublic.value = true;
  formError.value = '';
  submitError.value = '';
}

function open(): void {
  if (!auth.isAuthenticated) {
    loginError.value = '';
    loginOpen.value = true;
    return;
  }
  resetDraft();
  formOpen.value = true;
}

defineExpose({ open });

async function submitLogin(): Promise<void> {
  loggingIn.value = true;
  loginError.value = '';
  try {
    await auth.login(username.value.trim(), password.value);
    password.value = '';
    loginOpen.value = false;
    resetDraft();
    formOpen.value = true;
  } catch (error) {
    loginError.value = error instanceof Error ? error.message : 'เข้าสู่ระบบไม่สำเร็จ';
  } finally {
    loggingIn.value = false;
  }
}

function pickFiles(): void {
  fileInput.value?.click();
}

function addFiles(list: FileList | File[]): void {
  const next = [...draft.files];
  for (const file of Array.from(list)) {
    if (next.length >= 5) break;
    next.push(file);
  }
  draft.files = next;
}

function onPick(event: Event): void {
  const input = event.target as HTMLInputElement;
  if (input.files) addFiles(input.files);
  input.value = '';
}

function openConfirm(): void {
  formError.value = '';
  const body = draft.body.trim();
  if (!body) {
    formError.value = 'กรุณาระบุรายละเอียดความคิดเห็น';
    return;
  }
  if (body.length > 5000) {
    formError.value = 'รายละเอียดยาวเกิน 5,000 ตัวอักษร';
    return;
  }
  if (draft.files.length > 5) {
    formError.value = 'แนบไฟล์ได้ไม่เกิน 5 ไฟล์';
    return;
  }
  for (const file of draft.files) {
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (!ALLOWED.has(ext)) {
      formError.value = `ไฟล์ ${file.name} ไม่รองรับ`;
      return;
    }
    if (file.size > MAX_BYTES) {
      formError.value = `ไฟล์ ${file.name} ใหญ่เกิน 10 MB`;
      return;
    }
  }
  submitError.value = '';
  confirmOpen.value = true;
}

async function submit(): Promise<void> {
  submitting.value = true;
  submitError.value = '';
  try {
    await createLawComment(props.documentId, {
      type: draft.type,
      body: draft.body.trim(),
      sectionRef: draft.sectionRef,
      visibility: isPublic.value ? 'public' : 'private',
      files: draft.files,
    });
    confirmOpen.value = false;
    formOpen.value = false;
    emit('submitted');
  } catch (error) {
    submitError.value = error instanceof Error ? error.message : 'ส่งความคิดเห็นไม่สำเร็จ';
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.composer-ref {
  background: #f4f7fb;
  border-radius: 12px;
  padding: 12px 14px;
}

.composer-drop {
  width: 100%;
  border: 1px dashed #cbd5e1;
  border-radius: 12px;
  background: #fafafa;
  min-height: 120px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  color: #475569;
  cursor: pointer;
}

.confirm-grid {
  display: grid;
  grid-template-columns: 120px 1fr;
  gap: 8px 12px;
  font-size: 14px;
}

.confirm-grid span { color: #64748b; }

.confirm-body {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  background: #f8fafc;
  border-radius: 12px;
  padding: 12px;
}
</style>
