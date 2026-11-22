<template>
  <AppShell
    :breadcrumbs="['จัดการข้อมูลระบบ', 'จัดการความคิดเห็น', 'รายละเอียดความคิดเห็น']"
    :title="`รายละเอียดความคิดเห็น · ${comment?.code || commentId}`"
    subtitle="ตรวจสอบความคิดเห็น คำตอบ ประวัติการแก้ไข และการดำเนินการในหน้าเดียว"
  >
    <template #title-actions>
      <div class="d-flex align-center ga-2 flex-wrap">
        <v-chip v-if="comment" size="small" :color="statusColor(comment.status)" variant="tonal">{{ comment.status_label }}</v-chip>
        <v-chip
          v-if="comment"
          size="small"
          :color="comment.visibility === 'public' ? 'success' : 'grey'"
          :variant="comment.visibility === 'public' ? 'tonal' : 'outlined'"
        >
          {{ comment.visibility === 'public' ? 'Public' : 'Private' }}
        </v-chip>
      </div>
    </template>

    <v-btn
      variant="text"
      color="admin-primary"
      prepend-icon="mdi-arrow-left"
      class="text-none mb-4 px-0"
      to="/admin/comments"
    >
      กลับไปจัดการความคิดเห็น
    </v-btn>

    <div style="height: 3px"><v-progress-linear v-if="loading" indeterminate color="admin-primary" height="3" /></div>
    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" density="compact">{{ error }}</v-alert>

    <template v-if="comment">
      <v-row>
        <v-col cols="12" md="8">
          <v-card flat border rounded="lg" class="mb-4">
            <v-card-title class="text-subtitle-1 font-weight-bold">ข้อมูลความคิดเห็น</v-card-title>
            <v-divider />
            <v-card-text>
              <div class="info-grid">
                <div><span class="info-label">รหัสความคิดเห็น</span><strong>{{ comment.code }}</strong></div>
                <div><span class="info-label">ผู้แสดงความคิดเห็น</span><strong>{{ comment.author_name }}</strong></div>
                <div>
                  <span class="info-label">การเปิดเผยชื่อ</span>
                  <strong>{{ comment.reveal_name ? 'เปิดเผยชื่อ' : 'ไม่เปิดเผยชื่อ' }}</strong>
                </div>
                <div><span class="info-label">ประเภทความคิดเห็น</span><strong>{{ comment.type_label }}</strong></div>
                <div><span class="info-label">วันที่ส่ง</span><strong>{{ formatThaiDateTime(comment.created_at) }}</strong></div>
                <div><span class="info-label">เอกสารที่เกี่ยวข้อง</span><strong>{{ comment.document_title }}</strong></div>
                <div><span class="info-label">มาตราอ้างอิง</span><strong>{{ comment.section_ref || ' ' }}</strong></div>
                <div><span class="info-label">หน่วยงานรับผิดชอบ</span><strong>{{ comment.unit_name || comment.agency }}</strong></div>
                <div><span class="info-label">การแจ้งเตือน</span><strong>{{ comment.notify ? 'เปิด' : 'ปิด' }}</strong></div>
                <div><span class="info-label">สถานะปัจจุบัน</span><strong>{{ comment.status_label }}</strong></div>
              </div>
            </v-card-text>
          </v-card>

          <v-card flat border rounded="lg">
            <v-card-title class="d-flex align-center justify-space-between">
              <span class="text-subtitle-1 font-weight-bold">ความคิดเห็นและประวัติคำตอบ</span>
              <v-chip size="small" variant="tonal">{{ replyCount }} เวอร์ชัน</v-chip>
            </v-card-title>
            <v-divider />
            <v-card-text>
              <div class="original-bubble mb-4">
                <div class="d-flex flex-wrap align-center ga-2 mb-2">
                  <strong>{{ comment.author_name }}</strong>
                  <span class="text-caption text-medium-emphasis">{{ formatThaiDateTime(comment.created_at) }}</span>
                  <v-chip size="x-small" color="admin-primary" variant="tonal">{{ comment.type_label }}</v-chip>
                </div>
                <p class="mb-2">{{ comment.body }}</p>
                <div v-if="comment.attachments.length" class="d-flex flex-wrap ga-2">
                  <v-chip
                    v-for="file in comment.attachments"
                    :key="file.id"
                    size="small"
                    variant="outlined"
                    prepend-icon="mdi-paperclip"
                    :href="adminCommentFileUrl(comment.id, file.id)"
                    target="_blank"
                    rel="noopener"
                  >
                    {{ file.name }}
                  </v-chip>
                </div>
              </div>

              <div v-if="!comment.replies?.length" class="text-medium-emphasis text-center py-6">
                <v-icon icon="mdi-inbox-outline" size="36" class="mb-2" />
                <div>ยังไม่มีประวัติคำตอบ</div>
              </div>
              <div v-else class="d-flex flex-column ga-3">
                <div v-for="reply in comment.replies" :key="reply.id" class="reply-version">
                  <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-2">
                    <div class="d-flex align-center ga-2">
                      <strong>เวอร์ชัน {{ reply.version }}</strong>
                      <v-chip v-if="reply.is_latest" size="x-small" color="admin-primary" variant="tonal">ล่าสุด</v-chip>
                      <v-chip
                        size="x-small"
                        :color="reply.status === 'pending_review' ? 'warning' : 'success'"
                        variant="tonal"
                      >
                        {{ reply.status === 'pending_review' ? 'รอตรวจสอบ' : 'เผยแพร่แล้ว' }}
                      </v-chip>
                    </div>
                    <span class="text-caption text-medium-emphasis">{{ formatThaiDateTime(reply.created_at) }}</span>
                  </div>
                  <div class="text-caption text-medium-emphasis mb-1">โดย {{ reply.author_name }}</div>
                  <p class="mb-2">{{ reply.body }}</p>
                  <div v-if="reply.attachments.length" class="d-flex flex-wrap ga-2">
                    <v-chip
                      v-for="file in reply.attachments"
                      :key="file.id"
                      size="small"
                      variant="outlined"
                      prepend-icon="mdi-paperclip"
                      :href="adminCommentFileUrl(comment.id, file.id)"
                      target="_blank"
                      rel="noopener"
                    >
                      {{ file.name }}
                    </v-chip>
                  </div>
                </div>
              </div>
            </v-card-text>
          </v-card>
        </v-col>

        <v-col cols="12" md="4">
          <v-card flat border rounded="lg" class="mb-4">
            <v-card-title class="text-subtitle-1 font-weight-bold">การจัดการ</v-card-title>
            <v-divider />
            <v-card-text class="d-flex flex-column ga-2">
              <v-btn
                v-if="comment.status !== 'archived'"
                color="admin-primary"
                class="text-none"
                prepend-icon="mdi-reply"
                block
                @click="openReply()"
              >
                {{ comment.status === 'answered' ? 'เพิ่มคำตอบ' : 'ตอบกลับความคิดเห็น' }}
              </v-btn>
              <v-btn
                v-if="comment.status === 'answered'"
                variant="outlined"
                color="admin-primary"
                class="text-none"
                prepend-icon="mdi-eye-outline"
                block
                @click="visibilityOpen = true"
              >
                จัดการการเผยแพร่
              </v-btn>
              <v-btn
                v-if="comment.status === 'answered'"
                variant="outlined"
                color="admin-primary"
                class="text-none"
                prepend-icon="mdi-pencil-outline"
                block
                @click="openReply(true)"
              >
                แก้ไขคำตอบ
              </v-btn>
              <v-btn
                v-if="comment.status !== 'archived'"
                variant="outlined"
                color="grey-darken-1"
                class="text-none"
                prepend-icon="mdi-archive-outline"
                block
                @click="archiveOpen = true"
              >
                จัดเก็บเข้าคลัง
              </v-btn>
              <v-alert type="info" variant="tonal" density="compact" class="mt-2">
                คำตอบใหม่จะถูกบันทึกเป็นเวอร์ชัน และส่งตรวจสอบก่อนเผยแพร่ได้
                การตั้งค่าผู้ส่ง/การมองเห็นเป็นสิทธิ์แยกต่างหาก
              </v-alert>
            </v-card-text>
          </v-card>

          <v-card v-if="comment.status === 'pending'" flat border rounded="lg">
            <v-card-title class="d-flex align-center justify-space-between">
              <span class="text-subtitle-1 font-weight-bold">พื้นที่ดำเนินการตอบกลับ</span>
              <v-chip size="x-small" color="warning" variant="tonal">รอพิจารณา</v-chip>
            </v-card-title>
            <v-divider />
            <v-card-text>
              <p class="text-body-2 text-medium-emphasis mb-3">
                บันทึกคำตอบเพื่อส่งตรวจสอบก่อน ยังไม่ต้องเลือกการเผยแพร่ Public / Private
              </p>
              <v-textarea
                v-model="quickReply"
                label="คำตอบจากเจ้าหน้าที่ *"
                placeholder="พิมพ์คำตอบจากเจ้าหน้าที่..."
                variant="outlined"
                rows="5"
                hide-details="auto"
              />
              <v-btn
                class="mt-3 text-none"
                color="admin-primary"
                block
                :disabled="!quickReply.trim()"
                @click="openReplyFromQuick"
              >
                เปิดแบบฟอร์มตอบกลับ
              </v-btn>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>
    </template>

    <!-- Reply dialog -->
    <v-dialog v-model="replyOpen" max-width="1100" scrollable>
      <v-card v-if="comment" rounded="lg">
        <v-card-title class="d-flex align-center justify-space-between">
          <span>ตอบกลับความคิดเห็น · {{ comment.code }}</span>
          <v-btn icon="mdi-close" variant="text" :disabled="saving" @click="replyOpen = false" />
        </v-card-title>
        <v-divider />
        <v-card-text>
          <v-row>
            <v-col cols="12" md="5">
              <div class="text-subtitle-2 d-flex align-center ga-2 mb-3">
                <v-icon icon="mdi-comment-text-outline" size="18" />
                ความคิดเห็นต้นฉบับ
              </div>
              <div class="info-grid info-grid--compact mb-3">
                <div><span class="info-label">ผู้ส่ง</span><strong>{{ comment.author_name }}</strong></div>
                <div><span class="info-label">ประเภท</span><strong>{{ comment.type_label }}</strong></div>
                <div><span class="info-label">ส่งเมื่อ</span><strong>{{ formatThaiDateTime(comment.created_at) }}</strong></div>
                <div><span class="info-label">มาตราอ้างอิง</span><strong>{{ comment.section_ref || ' ' }}</strong></div>
              </div>
              <div class="text-caption text-medium-emphasis mb-1">เอกสารอ้างอิง</div>
              <div class="font-weight-medium mb-3">{{ comment.document_title }}</div>
              <div class="original-bubble mb-3">{{ comment.body }}</div>
              <div v-if="comment.attachments.length" class="d-flex flex-wrap ga-2 mb-4">
                <v-chip
                  v-for="file in comment.attachments"
                  :key="file.id"
                  size="small"
                  variant="outlined"
                  prepend-icon="mdi-paperclip"
                >
                  {{ file.name }}
                </v-chip>
              </div>
              <div class="text-subtitle-2 mb-2">ประวัติคำตอบของเจ้าหน้าที่</div>
              <div v-if="!comment.replies?.length" class="text-medium-emphasis text-center py-4">
                <v-icon icon="mdi-package-variant" size="28" class="mb-1" />
                <div class="text-caption">ยังไม่มีประวัติคำตอบ สามารถเริ่มพิมพ์คำตอบในพื้นที่ด้านขวา</div>
              </div>
              <div v-else class="d-flex flex-column ga-2">
                <div v-for="reply in comment.replies" :key="`hist-${reply.id}`" class="reply-version reply-version--compact">
                  <div class="d-flex justify-space-between">
                    <strong>เวอร์ชัน {{ reply.version }}{{ reply.is_latest ? ' (ล่าสุด)' : '' }}</strong>
                    <span class="text-caption">{{ formatThaiDateTime(reply.created_at) }}</span>
                  </div>
                </div>
              </div>
            </v-col>
            <v-col cols="12" md="7">
              <v-textarea
                v-model="replyForm.body"
                label="คำตอบจากเจ้าหน้าที่ *"
                placeholder="พิมพ์คำตอบจากเจ้าหน้าที่..."
                variant="outlined"
                rows="8"
                counter
                hide-details="auto"
                class="mb-3"
              />
              <div class="text-body-2 text-medium-emphasis mb-2">
                ไฟล์แนบคำตอบ (PDF, JPG, JPEG, PNG สูงสุด 5 ไฟล์ / ไฟล์ละ 10 MB)
              </div>
              <button type="button" class="upload-drop mb-2" @click="pickFiles">
                <v-icon icon="mdi-cloud-upload-outline" size="28" />
                <span>ลากไฟล์มาวาง หรือคลิกเพื่อเลือกไฟล์</span>
              </button>
              <input
                ref="fileInput"
                class="d-none"
                type="file"
                multiple
                accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                @change="onPickFiles"
              />
              <div v-if="replyForm.files.length" class="d-flex flex-column ga-1 mb-3">
                <div v-for="(file, index) in replyForm.files" :key="`${file.name}-${index}`" class="d-flex align-center justify-space-between">
                  <span class="text-body-2">{{ file.name }}</span>
                  <v-btn icon="mdi-close" size="x-small" variant="text" @click="replyForm.files.splice(index, 1)" />
                </div>
              </div>
              <v-checkbox
                v-model="replyForm.forwardForReview"
                hide-details
                density="compact"
                label="ส่งต่อให้ผู้รับผิดชอบตรวจสอบก่อนเผยแพร่"
                class="mb-1"
              />
              <div class="text-caption text-medium-emphasis mb-3 ms-8">
                หากไม่เลือก เจ้าหน้าที่สามารถยืนยันและเผยแพร่เองได้
              </div>
              <template v-if="!replyForm.forwardForReview">
                <v-select
                  v-model="replyForm.visibility"
                  :items="visibilityOptions"
                  item-title="title"
                  item-value="value"
                  label="การเผยแพร่เมื่อบันทึกคำตอบเอง"
                  variant="outlined"
                  density="comfortable"
                  hide-details="auto"
                  class="mb-2"
                />
                <div class="text-caption text-medium-emphasis">
                  ระบบจะแสดงตัวอย่างก่อนเผยแพร่ และจะไม่เปิดเผยชื่อผู้ส่งหากผู้ส่งเลือกไม่ระบุชื่อ
                </div>
              </template>
              <v-alert v-if="replyError" type="error" variant="tonal" density="compact" class="mt-3">{{ replyError }}</v-alert>
            </v-col>
          </v-row>
        </v-card-text>
        <v-divider />
        <v-card-actions class="pa-4">
          <v-btn variant="text" class="text-none" :disabled="saving" @click="replyOpen = false">ยกเลิก</v-btn>
          <v-spacer />
          <v-btn
            variant="outlined"
            color="admin-primary"
            class="text-none"
            prepend-icon="mdi-content-save-outline"
            :loading="saving && savingMode === 'draft'"
            :disabled="saving || !replyForm.body.trim()"
            @click="submitReply(true)"
          >
            บันทึกร่าง
          </v-btn>
          <v-btn
            color="admin-primary"
            class="text-none"
            prepend-icon="mdi-eye-outline"
            :loading="saving && savingMode === 'confirm'"
            :disabled="saving || !replyForm.body.trim()"
            @click="submitReply(false)"
          >
            ตรวจสอบและยืนยัน
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Visibility dialog -->
    <v-dialog v-model="visibilityOpen" max-width="560">
      <v-card v-if="comment" rounded="lg">
        <v-card-title class="d-flex align-center justify-space-between">
          <span>เปลี่ยนการมองเห็น · {{ comment.code }}</span>
          <v-btn icon="mdi-close" variant="text" :disabled="saving" @click="visibilityOpen = false" />
        </v-card-title>
        <v-divider />
        <v-card-text>
          <div class="text-caption text-medium-emphasis mb-1">ความคิดเห็น</div>
          <p class="mb-4">{{ comment.body }}</p>
          <div class="d-flex align-center ga-4 mb-4">
            <div>
              <div class="text-caption text-medium-emphasis">การเผยแพร่ปัจจุบัน</div>
              <v-chip size="small" :color="comment.visibility === 'public' ? 'success' : 'grey'" variant="tonal">
                {{ comment.visibility === 'public' ? 'Public' : 'Private' }}
              </v-chip>
            </div>
            <v-icon icon="mdi-arrow-right" />
            <div>
              <div class="text-caption text-medium-emphasis">การเผยแพร่ใหม่</div>
              <v-chip size="small" :color="visibilityForm === 'public' ? 'success' : 'grey'" variant="tonal">
                {{ visibilityForm === 'public' ? 'Public' : 'Private' }}
              </v-chip>
            </div>
          </div>
          <v-radio-group v-model="visibilityForm" hide-details class="mb-4">
            <v-sheet
              border
              rounded="lg"
              class="pa-3 mb-2"
              :class="{ 'visibility-option--active': visibilityForm === 'public' }"
              @click="visibilityForm = 'public'"
            >
              <v-radio value="public" label="Public" hide-details />
              <div class="text-caption text-medium-emphasis ms-8">แสดงต่อผู้ใช้งานอื่นที่เข้าสู่ระบบ</div>
            </v-sheet>
            <v-sheet
              border
              rounded="lg"
              class="pa-3"
              :class="{ 'visibility-option--active': visibilityForm === 'private' }"
              @click="visibilityForm = 'private'"
            >
              <v-radio value="private" label="Private" hide-details />
              <div class="text-caption text-medium-emphasis ms-8">เฉพาะเจ้าของความคิดเห็นและเจ้าหน้าที่</div>
            </v-sheet>
          </v-radio-group>
          <v-textarea
            v-model="visibilityReason"
            label="เหตุผลในการเปลี่ยนแปลง *"
            placeholder="ระบุเหตุผลเพื่อบันทึกใน Audit Log"
            variant="outlined"
            rows="3"
            hide-details="auto"
            class="mb-3"
          />
          <v-alert type="info" variant="tonal" density="compact">
            ตัวเลือกการเปิดเผยชื่อที่ผู้ส่งกำหนดจะไม่ถูกเปลี่ยน ไม่ว่าคุณจะเลือก Public หรือ Private
          </v-alert>
          <v-alert v-if="visibilityError" type="error" variant="tonal" density="compact" class="mt-3">{{ visibilityError }}</v-alert>
        </v-card-text>
        <v-divider />
        <v-card-actions class="pa-4">
          <v-spacer />
          <v-btn variant="outlined" class="text-none" :disabled="saving" @click="visibilityOpen = false">ยกเลิก</v-btn>
          <v-btn
            color="admin-primary"
            class="text-none"
            prepend-icon="mdi-shield-check-outline"
            :loading="saving"
            :disabled="!visibilityReason.trim()"
            @click="submitVisibility"
          >
            ยืนยันเปลี่ยนการมองเห็น
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Archive dialog -->
    <v-dialog v-model="archiveOpen" max-width="520">
      <v-card v-if="comment" rounded="lg">
        <v-card-title class="d-flex align-center justify-space-between">
          <span>จัดเก็บเข้าคลัง · {{ comment.code }}</span>
          <v-btn icon="mdi-close" variant="text" :disabled="saving" @click="archiveOpen = false" />
        </v-card-title>
        <v-divider />
        <v-card-text>
          <v-alert type="warning" variant="tonal" class="mb-4" density="comfortable">
            <div class="d-flex align-center ga-3">
              <v-icon icon="mdi-archive-arrow-down-outline" />
              <span>รายการนี้จะย้ายไปสถานะจัดเก็บเข้าคลัง แต่ประวัติและคำตอบยังคงอยู่</span>
            </div>
          </v-alert>
          <v-textarea
            v-model="archiveReason"
            label="เหตุผล *"
            placeholder="ระบุเหตุผล"
            variant="outlined"
            rows="4"
            hide-details="auto"
          />
          <v-alert v-if="archiveError" type="error" variant="tonal" density="compact" class="mt-3">{{ archiveError }}</v-alert>
        </v-card-text>
        <v-divider />
        <v-card-actions class="pa-4">
          <v-spacer />
          <v-btn variant="outlined" class="text-none" :disabled="saving" @click="archiveOpen = false">ยกเลิก</v-btn>
          <v-btn
            color="admin-primary"
            class="text-none"
            :loading="saving"
            :disabled="!archiveReason.trim()"
            @click="submitArchive"
          >
            ยืนยัน
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import {
  adminCommentFileUrl,
  archiveAdminComment,
  fetchAdminComment,
  replyAdminComment,
  updateAdminCommentVisibility,
} from '../../api/lawComments';
import AppShell from '../../components/shared/AppShell.vue';
import type { AdminLawComment, LawCommentStatus, LawCommentVisibility } from '../../types/lawComment';
import { COMMENT_STATUS_META } from '../../types/lawComment';
import { formatThaiDateTime } from '../../utils/thaiDate';

const props = defineProps<{ commentId?: string }>();
const route = useRoute();

const commentId = computed(() => props.commentId || String(route.params.commentId || ''));
const comment = ref<AdminLawComment | null>(null);
const loading = ref(false);
const error = ref('');
const saving = ref(false);
const savingMode = ref<'draft' | 'confirm' | ''>('');

const replyOpen = ref(false);
const visibilityOpen = ref(false);
const archiveOpen = ref(false);
const replyError = ref('');
const visibilityError = ref('');
const archiveError = ref('');
const quickReply = ref('');
const visibilityForm = ref<LawCommentVisibility>('public');
const visibilityReason = ref('');
const archiveReason = ref('');
const fileInput = ref<HTMLInputElement | null>(null);

const replyForm = reactive({
  body: '',
  visibility: 'public' as LawCommentVisibility,
  forwardForReview: false,
  files: [] as File[],
});

const visibilityOptions = [
  { title: 'Public   เผยแพร่ให้ผู้ใช้ที่เข้าสู่ระบบอ่านได้', value: 'public' },
  { title: 'Private   เฉพาะเจ้าของความคิดเห็นและเจ้าหน้าที่', value: 'private' },
];

const replyCount = computed(() => comment.value?.replies?.length ?? 0);

function statusColor(status: string): string {
  return COMMENT_STATUS_META[status as LawCommentStatus]?.color ?? 'grey';
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const result = await fetchAdminComment(commentId.value);
    comment.value = result.comment;
    visibilityForm.value = result.comment.visibility;
    if (result.comment.reply_draft?.body) {
      quickReply.value = result.comment.reply_draft.body;
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'โหลดรายละเอียดไม่สำเร็จ';
    comment.value = null;
  } finally {
    loading.value = false;
  }
}

function openReply(editLatest = false): void {
  replyError.value = '';
  const draft = comment.value?.reply_draft;
  const latest = comment.value?.replies?.find((item) => item.is_latest);
  replyForm.body = draft?.body || (editLatest ? latest?.body || '' : quickReply.value) || '';
  replyForm.visibility = draft?.visibility || comment.value?.visibility || 'public';
  replyForm.forwardForReview = draft?.forward_for_review ?? false;
  replyForm.files = [];
  replyOpen.value = true;
}

function openReplyFromQuick(): void {
  openReply(false);
}

function pickFiles(): void {
  fileInput.value?.click();
}

function onPickFiles(event: Event): void {
  const input = event.target as HTMLInputElement;
  const picked = Array.from(input.files ?? []);
  input.value = '';
  const allowed = new Set(['pdf', 'jpg', 'jpeg', 'png']);
  for (const file of picked) {
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (!allowed.has(ext) || file.size > 10 * 1024 * 1024) continue;
    if (replyForm.files.length >= 5) break;
    replyForm.files.push(file);
  }
}

async function submitReply(asDraft: boolean): Promise<void> {
  if (!comment.value || !replyForm.body.trim()) return;
  saving.value = true;
  savingMode.value = asDraft ? 'draft' : 'confirm';
  replyError.value = '';
  try {
    const result = await replyAdminComment(comment.value.id, {
      body: replyForm.body.trim(),
      visibility: replyForm.visibility,
      forwardForReview: replyForm.forwardForReview,
      asDraft,
      files: replyForm.files,
    });
    comment.value = result.comment;
    replyOpen.value = false;
    quickReply.value = '';
  } catch (err) {
    replyError.value = err instanceof Error ? err.message : 'บันทึกคำตอบไม่สำเร็จ';
  } finally {
    saving.value = false;
    savingMode.value = '';
  }
}

async function submitVisibility(): Promise<void> {
  if (!comment.value || !visibilityReason.value.trim()) return;
  saving.value = true;
  visibilityError.value = '';
  try {
    const result = await updateAdminCommentVisibility(comment.value.id, visibilityForm.value, visibilityReason.value.trim());
    comment.value = result.comment;
    visibilityOpen.value = false;
    visibilityReason.value = '';
  } catch (err) {
    visibilityError.value = err instanceof Error ? err.message : 'เปลี่ยนการมองเห็นไม่สำเร็จ';
  } finally {
    saving.value = false;
  }
}

async function submitArchive(): Promise<void> {
  if (!comment.value || !archiveReason.value.trim()) return;
  saving.value = true;
  archiveError.value = '';
  try {
    const result = await archiveAdminComment(comment.value.id, archiveReason.value.trim());
    comment.value = result.comment;
    archiveOpen.value = false;
    archiveReason.value = '';
  } catch (err) {
    archiveError.value = err instanceof Error ? err.message : 'จัดเก็บเข้าคลังไม่สำเร็จ';
  } finally {
    saving.value = false;
  }
}

watch(commentId, () => {
  void load();
});

onMounted(() => {
  void load();
});
</script>

<style scoped>
.info-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px 24px;
}
.info-grid--compact {
  gap: 10px 16px;
}
.info-label {
  display: block;
  color: rgba(0, 0, 0, 0.55);
  font-size: 0.75rem;
  margin-bottom: 2px;
}
.original-bubble {
  background: #eff6ff;
  border: 1px solid #dbeafe;
  border-radius: 12px;
  padding: 14px 16px;
  white-space: pre-wrap;
}
.reply-version {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 12px 14px;
  background: #fff;
}
.reply-version--compact {
  padding: 10px 12px;
}
.upload-drop {
  width: 100%;
  border: 1px dashed #94a3b8;
  border-radius: 12px;
  background: #f8fafc;
  padding: 24px 16px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  color: #475569;
  cursor: pointer;
}
.visibility-option--active {
  border-color: #1e3a8a !important;
  background: #eff6ff;
}
@media (max-width: 960px) {
  .info-grid {
    grid-template-columns: 1fr;
  }
}
</style>
