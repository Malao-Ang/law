<template>
  <AppShell
    :breadcrumbs="['เมนูหลัก', 'จัดการตัวบทกฎหมาย', docTitle]"
    title=""
    full-height
    show-bell
  >
    <div class="edit-hub">
      <div class="edit-hub__toolbar">
        <v-btn
          variant="text"
          size="small"
          prepend-icon="mdi-arrow-left"
          class="text-none px-1"
          @click="router.back()"
        >ย้อนกลับ</v-btn>
      </div>

      <div v-if="documentStore.loading" class="edit-hub__loading">
        <v-progress-circular indeterminate color="admin-primary" />
        <span>กำลังโหลดเอกสาร...</span>
      </div>

      <v-alert v-else-if="documentStore.error" type="error" variant="tonal" density="compact">
        {{ documentStore.error }}
      </v-alert>

      <template v-else-if="documentStore.review">
        <section class="edit-hub-header-grid">
          <div class="edit-hub-hero">
            <div class="d-flex flex-wrap ga-2 mb-3">
              <v-chip size="small" color="doc-prakat" variant="flat" class="font-weight-bold">
                {{ meta.law_type || 'เอกสาร' }}
              </v-chip>
              <v-chip
                size="small"
                :color="isPublished ? 'success' : 'warning'"
                variant="flat"
                class="font-weight-bold"
              >
                {{ isPublished ? 'เผยแพร่แล้ว' : 'รอลงนาม' }}
              </v-chip>
              <v-chip size="small" variant="tonal" class="font-weight-medium">
                # {{ documentId }}
              </v-chip>
            </div>

            <h1 class="edit-hub-hero__title">{{ docTitle }}</h1>

            <div class="edit-hub-meta-grid">
              <div class="edit-hub-meta-cell">
                <v-icon icon="mdi-calendar-outline" size="18" color="admin-primary" />
                <div>
                  <div class="edit-hub-meta-cell__label">วันที่ประกาศ</div>
                  <div class="edit-hub-meta-cell__value">{{ formatThaiDate(meta.promulgation_date) || '-' }}</div>
                </div>
              </div>
              <div class="edit-hub-meta-cell">
                <v-icon icon="mdi-calendar-check-outline" size="18" color="admin-primary" />
                <div>
                  <div class="edit-hub-meta-cell__label">วันที่มีผล</div>
                  <div class="edit-hub-meta-cell__value">{{ formatThaiDate(meta.effective_date) || '-' }}</div>
                </div>
              </div>
              <div class="edit-hub-meta-cell">
                <v-icon icon="mdi-book-open-page-variant-outline" size="18" color="admin-primary" />
                <div>
                  <div class="edit-hub-meta-cell__label">ราชกิจจาฯ</div>
                  <div class="edit-hub-meta-cell__value">{{ meta.gazette_reference || '-' }}</div>
                </div>
              </div>
              <div class="edit-hub-meta-cell">
                <v-icon icon="mdi-office-building-outline" size="18" color="admin-primary" />
                <div>
                  <div class="edit-hub-meta-cell__label">หน่วยงาน</div>
                  <div class="edit-hub-meta-cell__value">{{ agencyLabel }}</div>
                </div>
              </div>
              <div class="edit-hub-meta-cell">
                <v-icon icon="mdi-folder-outline" size="18" color="admin-primary" />
                <div>
                  <div class="edit-hub-meta-cell__label">กลุ่มกฎหมาย</div>
                  <div class="edit-hub-meta-cell__value">{{ lawGroupLabel }}</div>
                </div>
              </div>
              <div class="edit-hub-meta-cell">
                <v-icon icon="mdi-format-list-numbered" size="18" color="admin-primary" />
                <div>
                  <div class="edit-hub-meta-cell__label">จำนวน{{ articleUnitLabel }}</div>
                  <div class="edit-hub-meta-cell__value">{{ articleCount }}</div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section class="edit-hub-pipeline" aria-label="ขั้นตอนการแก้ไข">
          <v-btn
            v-for="action in actions"
            :key="action.key"
            color="admin-primary"
            variant="tonal"
            rounded="lg"
            class="edit-hub-pipeline__button text-none"
            :prepend-icon="action.icon"
            @click="router.push(action.to)"
          >{{ action.title }}</v-btn>
        </section>

        <div class="edit-hub-grid">
          <main class="edit-hub-doc">
            <v-card flat border rounded="lg" class="edit-hub-doc-card">
              <div class="edit-hub-doc-card__header">
                <div>
                  <h2 class="edit-hub-section-title mb-1">เนื้อหาเอกสาร</h2>
                  <p class="text-caption text-medium-emphasis mb-0">อ่านตัวบทแบบ read-only</p>
                </div>
                <v-btn
                  v-if="!isOldDoc"
                  color="admin-primary"
                  variant="tonal"
                  size="small"
                  class="text-none"
                  @click="router.push(`/documents/${documentId}/review`)"
                >แก้ไขเนื้อหา</v-btn>
              </div>

              <iframe
                v-if="isOldDoc"
                :src="fileUrl"
                title="เอกสารต้นฉบับ"
                class="edit-hub-old-pdf-frame"
              />

              <section
                v-for="section in sections"
                v-else
                :id="`sec-${section.id}`"
                :key="section.id"
                class="edit-hub-document-section"
              >
                <div class="edit-hub-document-section__top">
                  <span
                    class="edit-hub-document-section__badge"
                    :class="{ 'edit-hub-document-section__badge--chapter': section.isChapter }"
                  >{{ section.badge }}</span>
                  <v-chip
                    v-if="!section.isChapter"
                    size="x-small"
                    color="success"
                    variant="tonal"
                  >มีผลบังคับ</v-chip>
                </div>

                <BlockFlow
                  :block="section.headBlock"
                  :override-text="section.headBlock.meta?.reviewed_html ? null : (section.headBodyText || null)"
                />
                <BlockFlow
                  v-for="child in section.children"
                  :key="child.block_id"
                  :block="child"
                />
              </section>
            </v-card>
          </main>

          <aside class="edit-hub-info">
            <v-card flat border rounded="lg" class="edit-hub-info-card">
              <div class="d-flex align-center justify-space-between ga-3 mb-3">
                <h2 class="edit-hub-section-title mb-0">ความสัมพันธ์กฎหมาย</h2>
                <v-chip size="small" color="admin-primary" variant="tonal">
                  {{ relations.length }} รายการ
                </v-chip>
              </div>
              <div v-if="relationTypeSummary.length" class="edit-hub-relation-list">
                <div v-for="item in relationTypeSummary" :key="item.type" class="edit-hub-relation-row">
                  <span>{{ relationTypeLabel(item.type) }}</span>
                  <strong>{{ item.count }}</strong>
                </div>
              </div>
              <div v-else class="text-body-2 text-medium-emphasis">
                ยังไม่มีความสัมพันธ์กฎหมาย
              </div>
            </v-card>

            <v-card flat border rounded="lg" class="edit-hub-info-card">
              <h2 class="edit-hub-section-title">ประวัติเวอร์ชัน</h2>
              <VersionHistoryTimeline
                v-if="versionStore.versions.length >= 2"
                :versions="versionStore.versions"
                :viewed-document-id="documentId"
              />
              <div v-else class="text-body-2 text-medium-emphasis">
                ยังไม่มีประวัติเวอร์ชัน
              </div>
            </v-card>

            <v-card flat border rounded="lg" class="edit-hub-info-card">
              <div class="edit-hub-publish">
                <div>
                  <h2 class="edit-hub-section-title mb-1">การเผยแพร่</h2>
                  <p class="text-caption text-medium-emphasis mb-0">
                    {{ isPublished ? formatThaiDate(meta.published_date) || 'เผยแพร่แล้ว' : 'ยังไม่เผยแพร่' }}
                  </p>
                </div>
                <v-switch
                  :model-value="isPublished"
                  :loading="publishToggleSaving"
                  :disabled="publishToggleSaving"
                  color="success"
                  density="compact"
                  hide-details
                  inset
                  :label="isPublished ? 'เผยแพร่' : 'ปิด'"
                  @update:model-value="togglePublished"
                />
              </div>
            </v-card>
          </aside>
        </div>

        <PublishConfirmDialog
          v-model="publishDialogOpen"
          :publishing="publishDialogNext ?? !isPublished"
          :loading="publishToggleSaving"
          @confirm="confirmPublishChange"
        />
      </template>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AppShell from '../shared/AppShell.vue';
import BlockFlow from '../shared/BlockFlow.vue';
import PublishConfirmDialog from '../shared/PublishConfirmDialog.vue';
import VersionHistoryTimeline from '../law/VersionHistoryTimeline.vue';
import { useDocumentStore } from '../../stores/documentStore';
import { useVersionStore } from '../../stores/versionStore';
import { buildSections } from '../../composables/useLawSections';
import { documentFileUrl, fetchStatus } from '../../api/client';
import Swal from 'sweetalert2';
import type { LawMeta, LawRelation, RelationType } from '../../types/document';
import { formatThaiDate } from '../../utils/thaiDate';

const props = defineProps<{ documentId: string }>();

const router = useRouter();
const documentStore = useDocumentStore();
const versionStore = useVersionStore();

const EMPTY_META: LawMeta = {
  status: '',
  law_type: '',
  law_group: '',
  change_status: null,
  law_groups: [],
  agency: '',
  signer_group: null,
  agencies: [],
  keywords: [],
  promulgation_date: '',
  effective_date: '',
  published_date: '',
  expiry_date: null,
  section_count: null,
  title: '',
  gazette_reference: '',
  royal_command: '',
  repealed_laws: [],
  imported_by: '',
  parent_document_id: null,
  parent_document_ids: [],
  access_scope: 'public',
  permission_group_ids: [],
};

const meta = computed<LawMeta>(() => documentStore.review?.law_meta ?? EMPTY_META);
const isOldDoc = computed(() => documentStore.review?.law_meta?.document_type === 'old');
const fileUrl = computed(() => documentFileUrl(props.documentId));
const isPublished = computed(() => !!meta.value.published_date);
const publishToggleSaving = ref(false);
const publishDialogOpen = ref(false);
const publishDialogNext = ref<boolean | null>(null);
const sections = computed(() => buildSections(documentStore.review));
const relations = computed<LawRelation[]>(() => documentStore.review?.relations ?? []);

const docTitle = computed(() =>
  meta.value.title || documentStore.review?.source_file || props.documentId,
);

const articleCount = computed(() =>
  sections.value.filter((s) => s.badge.startsWith('มาตรา') || s.badge.startsWith('ข้อ')).length,
);

const articleUnitLabel = computed(() => {
  const hasClause = sections.value.some((s) => s.badge.startsWith('ข้อ'));
  const hasArticle = sections.value.some((s) => s.badge.startsWith('มาตรา'));
  if (hasClause && hasArticle) return 'ข้อ';
  if (hasClause) return 'ข้อ';
  if (hasArticle) return 'ข้อ';
  return 'ข้อ';
});

const agencyLabel = computed(() => {
  if (meta.value.agencies?.length) return meta.value.agencies.join(', ');
  return meta.value.agency || '-';
});

const lawGroupLabel = computed(() => {
  if (meta.value.law_groups?.length) return meta.value.law_groups.join(', ');
  return meta.value.law_group || '-';
});

const updatedAtLabel = computed(() => {
  const iso = documentStore.review?.document_review?.updated_at;
  if (!iso) return '';
  return formatThaiDate(iso);
});

const signatoryLabel = computed(() => {
  const md = documentStore.review?.compose_state?.metadata;
  if (!md?.signatory_name) return '';
  return md.signatory_position
    ? `${md.signatory_name} (${md.signatory_position})`
    : md.signatory_name;
});

const relationTypeSummary = computed(() => {
  const counts = new Map<RelationType, number>();
  for (const relation of relations.value) {
    counts.set(relation.type, (counts.get(relation.type) ?? 0) + 1);
  }
  return Array.from(counts.entries()).map(([type, count]) => ({ type, count }));
});

const actions = computed(() => {
  const list = [];

  // เอกสารเก่าไม่มี review/rag (ไม่ผ่าน extraction)
  if (!isOldDoc.value) {
    list.push({
      key: 'review',
      icon: 'mdi-file-document-edit-outline',
      title: 'แก้ไขเนื้อหา',
      description: 'ตรวจทานและแก้ไขบล็อกตัวบทก่อนเผยแพร่',
      to: `/documents/${props.documentId}/review`,
    });
  }

  list.push({
    key: 'law-info',
    icon: 'mdi-card-text-outline',
    title: 'แก้ไขข้อมูลกฎหมาย',
    description: 'จัดการ metadata วันที่ กลุ่มกฎหมาย และข้อมูลประกาศ',
    to: `/documents/${props.documentId}/law-info?mode=edit`,
  });

  list.push({
    key: 'relations',
    icon: 'mdi-graph-outline',
    title: 'ความสัมพันธ์',
    description: 'เพิ่มหรือแก้ไขฉบับที่เกี่ยวข้องและผลทางกฎหมาย',
    to: `/documents/${props.documentId}/relations`,
  });

  if (!isOldDoc.value) {
    list.push({
      key: 'rag',
      icon: 'mdi-sort-variant',
      title: 'จัดลำดับ RAG',
      description: 'จัดกลุ่ม เลือก และเรียงบล็อกก่อนบันทึกเข้าคลัง RAG',
      to: `/documents/${props.documentId}/rag`,
    });
  }

  list.push({
    key: 'permissions',
    icon: 'mdi-shield-lock-outline',
    title: 'กำหนดสิทธิ์',
    description: 'ตั้งค่าการเข้าถึงแบบ public หรือ private',
    to: `/documents/${props.documentId}/permissions`,
  });

  // เอกสารเก่า (source=external) ไม่มี preview/esign
  if (!isOldDoc.value) {
    list.push({
      key: 'preview',
      icon: 'mdi-eye-outline',
      title: 'ดูตัวอย่าง',
      description: 'ตรวจรูปแบบเอกสารก่อนเข้าสู่ขั้นตอน e-Sign',
      to: `/documents/${props.documentId}/esign/preview`,
    });

    list.push({
      key: 'esign',
      icon: 'mdi-send-outline',
      title: 'ส่งลงนาม e-Sign',
      description: 'เปิดหน้าลงนามอิเล็กทรอนิกส์และติดตามสถานะ',
      to: `/documents/${props.documentId}/esign`,
    });
  }

  return list;
});

function relationTypeLabel(type: RelationType): string {
  const labels: Record<RelationType, string> = {
    related: 'เกี่ยวข้อง',
    repeals: 'ยกเลิก',
    amends: 'แก้ไข',
    issued_under: 'ออกตาม',
    supersedes: 'แทนที่',
  };
  return labels[type] ?? type;
}

async function togglePublished(next: boolean | null): Promise<void> {
  if (!!next) {
    const status = await fetchStatus(props.documentId);
    if (status?.rag_skipped) {
      const result = await Swal.fire({
        icon: 'warning',
        title: 'ยังไม่ได้จัดลำดับ RAG',
        html: 'เอกสารนี้เคยข้ามขั้นตอน RAG ไว้ ต้องกลับไปจัดลำดับเนื้อหาให้เสร็จก่อนเผยแพร่',
        showCancelButton: true,
        confirmButtonText: 'ไปจัดลำดับ RAG',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#1a3673',
        cancelButtonColor: '#64748b',
      });
      if (result.isConfirmed) {
        router.push(`/documents/${props.documentId}/rag`);
      }
      return;
    }
  }
  // Draft status check — after RAG check passes
  if (!!next && (!meta.value.status || meta.value.status === 'ร่าง')) {
    const result = await Swal.fire({
      title: 'เอกสารยังเป็นร่าง',
      html: 'สถานะบังคับใช้ยังเป็น <strong>ร่าง</strong><br>หากเผยแพร่ สถานะจะเปลี่ยนเป็น <strong>มีผลบังคับใช้</strong> โดยอัตโนมัติ',
      showCancelButton: true,
      showDenyButton: true,
      confirmButtonText: 'เผยแพร่และเปลี่ยนสถานะ',
      cancelButtonText: 'ยกเลิก',
      denyButtonText: 'ไปแก้ไขสถานะเอง',
      denyButtonColor: '#6b7280',
      confirmButtonColor: '#1a3673',
    });

    if (result.isConfirmed) {
      await documentStore.saveLawMeta({ status: 'มีผลบังคับใช้' });
    } else if (result.isDenied) {
      router.push(`/documents/${props.documentId}/law-info`);
      return;
    } else {
      return;
    }
  }
  publishDialogNext.value = !!next;
  publishDialogOpen.value = true;
}

async function confirmPublishChange(): Promise<void> {
  if (publishDialogNext.value === null) return;
  const next = publishDialogNext.value;
  publishToggleSaving.value = true;
  try {
    await documentStore.saveLawMeta({
      published_date: next ? new Date().toISOString().slice(0, 10) : '',
    });
  } finally {
    publishToggleSaving.value = false;
    publishDialogOpen.value = false;
    publishDialogNext.value = null;
  }
}

onMounted(() => {
  if (documentStore.documentId !== props.documentId || !documentStore.review) {
    void documentStore.fetch(props.documentId);
  }
  void versionStore.fetch(props.documentId);
});

watch(() => props.documentId, (id) => {
  void documentStore.fetch(id);
  void versionStore.fetch(id);
});

onBeforeUnmount(() => {
  documentStore.reset();
});
</script>

<style scoped>
.edit-hub {
  background: #f8f7f5;
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  gap: 16px;
  min-height: 0;
  overflow: auto;
  padding: 2px;
}

.edit-hub__toolbar {
  align-items: center;
  display: flex;
  flex-shrink: 0;
}

.edit-hub__loading {
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.62);
  display: flex;
  gap: 12px;
  justify-content: center;
  padding: 64px 16px;
}

.edit-hub-header-grid {
  align-items: stretch;
  display: grid;
  gap: 16px;
  grid-template-columns: 1fr;
}

.edit-hub-hero,
.edit-hub-info-card {
  background: #fff;
  border: 1px solid #e3ded6;
  border-radius: 14px;
}

.edit-hub-hero {
  padding: 22px;
}

.edit-hub-hero__title {
  color: #1f2933;
  font-size: 24px;
  font-weight: 800;
  line-height: 1.35;
  margin: 0 0 16px;
}

.edit-hub-meta-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.edit-hub-meta-cell {
  align-items: flex-start;
  background: #fbfaf8;
  border: 1px solid #eee9e1;
  border-radius: 10px;
  display: flex;
  gap: 10px;
  padding: 10px 12px;
}

.edit-hub-meta-cell__label {
  color: #6b7280;
  font-size: 11px;
}

.edit-hub-meta-cell__value {
  color: #202938;
  font-size: 13px;
  font-weight: 700;
  word-break: break-word;
}

.edit-hub-pipeline {
  align-items: center;
  background: #fff;
  border: 1px solid #e3ded6;
  border-radius: 14px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 12px;
}

.edit-hub-pipeline__button {
  flex: 0 0 auto;
}

.edit-hub-grid {
  align-items: stretch;
  display: grid;
  gap: 16px;
  grid-template-columns: minmax(0, 1fr) 360px;
  height: calc(100vh - 340px);
  min-height: 400px;
  overflow: hidden;
}

.edit-hub-doc,
.edit-hub-info {
  min-height: 0;
  max-height: 100%;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.edit-hub-info {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
  padding-right: 2px;
}

.edit-hub-doc-card {
  background: #fff;
  border: 1px solid #e3ded6;
  border-radius: 14px;
  min-height: 100%;
  padding: 16px 18px;
}

.edit-hub-doc-card__header {
  align-items: center;
  display: flex;
  gap: 12px;
  justify-content: space-between;
  margin-bottom: 14px;
}

.edit-hub-document-section {
  border-top: 1px solid #eee9e1;
  padding: 16px 0;
}

.edit-hub-document-section:first-of-type {
  border-top: 0;
  padding-top: 0;
}

.edit-hub-document-section__top {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

.edit-hub-document-section__badge {
  background: #eaf7ef;
  border-radius: 8px;
  color: #047857;
  display: inline-block;
  font-size: 13px;
  font-weight: 700;
  padding: 4px 10px;
}

.edit-hub-document-section__badge--chapter {
  background: #eef2ff;
  color: #4338ca;
}

.edit-hub-old-pdf-frame {
  border: none;
  border-radius: 12px;
  box-shadow: 0 2px 16px rgba(0, 0, 0, 0.12);
  height: calc(100vh - 360px);
  width: 100%;
}

.edit-hub-section-title {
  color: #1f2933;
  font-size: 15px;
  font-weight: 800;
  line-height: 1.3;
  margin: 0 0 4px;
}

.edit-hub-info-card {
  flex: 0 0 auto;
  min-width: 0;
  padding: 16px;
  width: 100%;
}

.edit-hub-kv,
.edit-hub-relation-row {
  align-items: stretch;
  border-bottom: 1px solid #f0eee9;
  display: flex;
  flex-direction: column;
  font-size: 13px;
  gap: 4px;
  padding: 10px 0;
}

.edit-hub-kv:last-child,
.edit-hub-relation-row:last-child {
  border-bottom: none;
}

.edit-hub-kv span,
.edit-hub-relation-row span {
  color: #667085;
}

.edit-hub-kv strong,
.edit-hub-relation-row strong {
  color: #1f2933;
  font-weight: 700;
  overflow-wrap: anywhere;
  text-align: left;
  white-space: normal;
  word-break: break-word;
}

.edit-hub-relation-list {
  display: flex;
  flex-direction: column;
}

.edit-hub-publish {
  align-items: center;
  display: flex;
  gap: 12px;
  justify-content: space-between;
}

@media (max-width: 900px) {
  .edit-hub-header-grid,
  .edit-hub-grid {
    grid-template-columns: 1fr;
    overflow: visible;
    height: auto;
    min-height: 0;
  }

  .edit-hub-doc,
  .edit-hub-info {
    max-height: none;
    overflow: visible;
  }

  .edit-hub-pipeline__button {
    flex: 1 1 180px;
  }

  .edit-hub-meta-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .edit-hub-hero {
    padding: 18px;
  }

  .edit-hub-meta-grid {
    grid-template-columns: 1fr;
  }

}
</style>
