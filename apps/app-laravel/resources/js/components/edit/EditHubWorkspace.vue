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
        <section class="edit-hub-hero">
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
        </section>

        <div class="edit-hub-grid">
          <main class="edit-hub-actions">
            <v-card
              v-for="action in actions"
              :key="action.key"
              flat
              border
              rounded="lg"
              class="edit-hub-action-card"
            >
              <div class="edit-hub-action-card__icon">
                <v-icon :icon="action.icon" size="24" />
              </div>
              <div class="edit-hub-action-card__body">
                <h2>{{ action.title }}</h2>
                <p>{{ action.description }}</p>
              </div>
              <v-btn
                color="admin-primary"
                variant="tonal"
                size="small"
                class="text-none edit-hub-action-card__button"
                append-icon="mdi-arrow-right"
                @click="router.push(action.to)"
              >
                เปิด
              </v-btn>
            </v-card>
          </main>

          <aside class="edit-hub-info">
            <v-card flat border rounded="lg" class="edit-hub-info-card">
              <h2 class="edit-hub-section-title">ข้อมูลเอกสาร</h2>
              <div class="edit-hub-kv">
                <span>รหัสกฎหมาย</span>
                <strong>{{ documentId }}</strong>
              </div>
              <div class="edit-hub-kv">
                <span>ประเภท</span>
                <strong>{{ meta.law_type || '-' }}</strong>
              </div>
              <div class="edit-hub-kv">
                <span>กลุ่มกฎหมาย</span>
                <strong>{{ lawGroupLabel }}</strong>
              </div>
              <div class="edit-hub-kv">
                <span>วันที่ประกาศ</span>
                <strong>{{ formatThaiDate(meta.promulgation_date) || '-' }}</strong>
              </div>
              <div class="edit-hub-kv">
                <span>วันที่มีผล</span>
                <strong>{{ formatThaiDate(meta.effective_date) || '-' }}</strong>
              </div>
              <div class="edit-hub-kv">
                <span>จำนวน{{ articleUnitLabel }}</span>
                <strong>{{ articleCount }}</strong>
              </div>
              <div class="edit-hub-kv">
                <span>หน่วยงาน</span>
                <strong>{{ agencyLabel }}</strong>
              </div>
              <div class="edit-hub-kv">
                <span>ผู้นำเข้า</span>
                <strong>{{ meta.imported_by || '-' }}</strong>
              </div>
              <div class="edit-hub-kv">
                <span>แก้ไขล่าสุด</span>
                <strong>{{ updatedAtLabel || '-' }}</strong>
              </div>
              <div v-if="signatoryLabel" class="edit-hub-kv">
                <span>ผู้ลงนาม</span>
                <strong>{{ signatoryLabel }}</strong>
              </div>
            </v-card>

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

        <v-expansion-panels class="edit-hub-document-panels">
          <v-expansion-panel>
            <v-expansion-panel-title>
              <div>
                <div class="font-weight-bold">แสดงเนื้อหาเอกสาร</div>
                <div class="text-caption text-medium-emphasis">อ่านตัวบทแบบ read-only</div>
              </div>
            </v-expansion-panel-title>
            <v-expansion-panel-text>
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
                class="edit-hub-document-card"
              >
                <div class="edit-hub-document-card__top">
                  <span
                    class="edit-hub-document-card__badge"
                    :class="{ 'edit-hub-document-card__badge--chapter': section.isChapter }"
                  >{{ section.badge }}</span>
                  <v-chip
                    v-if="!section.isChapter"
                    size="x-small"
                    color="success"
                    variant="tonal"
                  >มีผลบังคับ</v-chip>
                </div>

                <div class="edit-hub-document-card__body">
                  <BlockFlow
                    :block="section.headBlock"
                    :override-text="section.headBlock.meta?.reviewed_html ? null : (section.headBodyText || null)"
                  />
                  <BlockFlow
                    v-for="child in section.children"
                    :key="child.block_id"
                    :block="child"
                  />
                </div>
              </section>
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>
      </template>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AppShell from '../shared/AppShell.vue';
import BlockFlow from '../shared/BlockFlow.vue';
import VersionHistoryTimeline from '../law/VersionHistoryTimeline.vue';
import { useDocumentStore } from '../../stores/documentStore';
import { useVersionStore } from '../../stores/versionStore';
import { buildSections } from '../../composables/useLawSections';
import { documentFileUrl } from '../../api/client';
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

const actions = computed(() => [
  {
    key: 'review',
    icon: 'mdi-file-document-edit-outline',
    title: 'แก้ไขเนื้อหา',
    description: 'ตรวจทานและแก้ไขบล็อกตัวบทก่อนเผยแพร่',
    to: `/documents/${props.documentId}/review`,
  },
  {
    key: 'law-info',
    icon: 'mdi-card-text-outline',
    title: 'แก้ไขข้อมูลกฎหมาย',
    description: 'จัดการ metadata วันที่ กลุ่มกฎหมาย และข้อมูลประกาศ',
    to: `/documents/${props.documentId}/law-info?mode=edit`,
  },
  {
    key: 'relations',
    icon: 'mdi-graph-outline',
    title: 'ความสัมพันธ์',
    description: 'เพิ่มหรือแก้ไขฉบับที่เกี่ยวข้องและผลทางกฎหมาย',
    to: `/documents/${props.documentId}/relations`,
  },
  {
    key: 'permissions',
    icon: 'mdi-shield-lock-outline',
    title: 'กำหนดสิทธิ์',
    description: 'ตั้งค่าการเข้าถึงแบบ public หรือ private',
    to: `/documents/${props.documentId}/permissions`,
  },
  {
    key: 'preview',
    icon: 'mdi-eye-outline',
    title: 'ดูตัวอย่าง',
    description: 'ตรวจรูปแบบเอกสารก่อนเข้าสู่ขั้นตอน e-Sign',
    to: `/documents/${props.documentId}/esign/preview`,
  },
  {
    key: 'esign',
    icon: 'mdi-send-outline',
    title: 'ส่งลงนาม e-Sign',
    description: 'เปิดหน้าลงนามอิเล็กทรอนิกส์และติดตามสถานะ',
    to: `/documents/${props.documentId}/esign`,
  },
]);

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
  publishToggleSaving.value = true;
  try {
    await documentStore.saveLawMeta({
      published_date: next ? new Date().toISOString().slice(0, 10) : '',
    });
  } finally {
    publishToggleSaving.value = false;
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

.edit-hub-grid {
  align-items: start;
  display: grid;
  gap: 20px;
  grid-template-columns: 1fr 380px;
}

.edit-hub-actions {
  display: grid;
  gap: 12px;
}

.edit-hub-action-card {
  align-items: flex-start;
  display: grid;
  gap: 14px;
  grid-template-columns: auto minmax(0, 1fr) auto;
  padding: 20px;
}

.edit-hub-action-card__icon {
  align-items: center;
  background: #eef4f8;
  border-radius: 10px;
  color: rgb(var(--v-theme-admin-primary));
  display: flex;
  height: 44px;
  justify-content: center;
  width: 44px;
}

.edit-hub-action-card__body {
  min-width: 0;
}

.edit-hub-action-card__body h2,
.edit-hub-section-title {
  color: #1f2933;
  font-size: 15px;
  font-weight: 800;
  line-height: 1.3;
  margin: 0 0 4px;
}

.edit-hub-action-card__body p {
  color: #667085;
  font-size: 13px;
  line-height: 1.45;
  margin: 0;
}

.edit-hub-action-card__button {
  align-self: end;
  justify-self: end;
}

.edit-hub-info {
  display: flex;
  flex-direction: column;
  gap: 12px;
  position: sticky;
  top: 12px;
}

.edit-hub-info-card {
  padding: 16px;
}

.edit-hub-kv,
.edit-hub-relation-row {
  align-items: flex-start;
  border-bottom: 1px solid #f0eee9;
  display: flex;
  font-size: 13px;
  gap: 12px;
  justify-content: space-between;
  padding: 8px 0;
}

.edit-hub-kv:last-child,
.edit-hub-relation-row:last-child {
  border-bottom: none;
}

.edit-hub-kv span,
.edit-hub-relation-row span {
  color: #667085;
  flex-shrink: 0;
}

.edit-hub-kv strong,
.edit-hub-relation-row strong {
  color: #1f2933;
  font-weight: 700;
  text-align: right;
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

.edit-hub-document-panels {
  flex-shrink: 0;
}

.edit-hub-document-panels :deep(.v-expansion-panel) {
  border: 1px solid #e3ded6;
  border-radius: 14px;
}

.edit-hub-document-panels :deep(.v-expansion-panel-text__wrapper) {
  background: #fbfaf8;
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
}

.edit-hub-document-card {
  background: #fff;
  border: 1px solid #e7e2da;
  border-radius: 12px;
  padding: 16px 18px 8px;
}

.edit-hub-document-card__top {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

.edit-hub-document-card__badge {
  background: #eaf7ef;
  border-radius: 8px;
  color: #047857;
  display: inline-block;
  font-size: 13px;
  font-weight: 700;
  padding: 4px 10px;
}

.edit-hub-document-card__badge--chapter {
  background: #eef2ff;
  color: #4338ca;
}

.edit-hub-document-card__body {
  min-width: 0;
}

.edit-hub-old-pdf-frame {
  border: none;
  border-radius: 12px;
  box-shadow: 0 2px 16px rgba(0, 0, 0, 0.12);
  height: calc(100vh - 220px);
  width: 100%;
}

@media (max-width: 900px) {
  .edit-hub-grid {
    grid-template-columns: 1fr;
  }

  .edit-hub-info {
    position: static;
  }

  .edit-hub-meta-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .edit-hub-hero {
    padding: 18px;
  }

  .edit-hub-meta-grid,
  .edit-hub-action-card {
    grid-template-columns: 1fr;
  }

  .edit-hub-action-card__button {
    justify-self: stretch;
  }
}
</style>
