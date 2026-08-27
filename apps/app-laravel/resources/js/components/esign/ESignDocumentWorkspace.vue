<template>
  <AppShell
    :breadcrumbs="['เมนูหลัก', 'จัดการตัวบทกฎหมาย', docTitle]"
    title=""
    full-height
    show-bell
  >
    <template #actions>
      <v-btn
        variant="outlined"
        size="small"
        prepend-icon="mdi-eye-outline"
        class="text-none"
        @click="router.push(`/documents/${documentId}/esign/preview`)"
      >ดูตัวอย่าง</v-btn>
      <v-btn
        color="admin-primary"
        size="small"
        prepend-icon="mdi-file-document-edit-outline"
        class="text-none"
        @click="router.push(`/documents/${documentId}/law-info`)"
      >แก้ไขข้อมูล</v-btn>
    </template>

    <div class="esign-page">
      <div class="esign-toolbar mb-3">
        <v-btn
          variant="text"
          size="small"
          prepend-icon="mdi-arrow-left"
          class="text-none px-1"
          @click="router.push('/admin/laws')"
        >ย้อนกลับ</v-btn>
      </div>

      <div v-if="documentStore.loading" class="d-flex align-center justify-center ga-3 pa-16 text-medium-emphasis flex-grow-1">
        <v-progress-circular indeterminate color="admin-primary" />
        <span>กำลังโหลดเอกสาร...</span>
      </div>
      <v-alert v-else-if="documentStore.error" type="error" variant="tonal" density="compact" class="ma-2">
        {{ documentStore.error }}
      </v-alert>

      <div v-else-if="documentStore.review" class="esign-grid">
      <!-- Left: TOC -->
      <aside class="esign-toc">
        <div class="esign-toc__head">
          <div>
            <div class="text-subtitle-2 font-weight-bold">สารบัญ</div>
            <div class="text-caption text-medium-emphasis">{{ articleCount }} {{ articleUnitLabel }}</div>
          </div>
        </div>

        <v-text-field
          v-model="tocQuery"
          density="compact"
          variant="outlined"
          hide-details
          placeholder="ค้นหาในสารบัญ..."
          prepend-inner-icon="mdi-magnify"
          class="mb-3"
        />

        <div v-for="group in filteredTocGroups" :key="group.label" class="esign-toc__group">
          <button type="button" class="esign-toc__group-btn" @click="toggleGroup(group.label)">
            <span class="text-truncate">{{ group.label }}</span>
            <v-icon
              :icon="collapsed.has(group.label) ? 'mdi-chevron-down' : 'mdi-chevron-up'"
              size="16"
            />
          </button>
          <div v-show="!collapsed.has(group.label)" class="esign-toc__items">
            <button
              v-for="sid in group.sectionIds"
              :key="sid"
              type="button"
              class="esign-toc__item"
              :class="{ 'is-active': activeId === sid }"
              @click="scrollTo(sid)"
            >
              <span class="esign-toc__dot is-active-status" />
              {{ badgeOf(sid) }}
            </button>
          </div>
        </div>

        <div class="esign-toc__stats">
          <div class="text-caption font-weight-bold mb-2">สถิติสถานะ</div>
          <div class="esign-toc__stat-row">
            <span><span class="esign-toc__dot is-active-status" /> มีผลบังคับ</span>
            <strong>{{ articleCount }}</strong>
          </div>
          <div class="esign-toc__stat-row">
            <span><span class="esign-toc__dot is-cancelled" /> ยกเลิก</span>
            <strong>0</strong>
          </div>
          <div class="esign-toc__stat-row">
            <span><span class="esign-toc__dot is-amended" /> แก้ไขล่าสุด</span>
            <strong>0</strong>
          </div>
          <div class="esign-toc__stat-row">
            <span><span class="esign-toc__dot is-pending" /> รอวินิจฉัย</span>
            <strong>0</strong>
          </div>
        </div>
      </aside>

      <!-- Center: document -->
      <main ref="docScrollEl" class="esign-doc">
        <section class="esign-headcard">
          <div class="d-flex flex-wrap ga-2 mb-3">
            <v-chip size="small" color="doc-prakat" variant="flat" class="font-weight-bold">
              {{ meta.law_type || 'เอกสาร' }}
            </v-chip>
            <v-chip size="small" color="warning" variant="flat" class="font-weight-bold">
              รอลงนาม
            </v-chip>
            <v-chip size="small" variant="tonal" class="font-weight-medium">
              # {{ documentId }}
            </v-chip>
          </div>

          <h1 class="esign-headcard__title">{{ docTitle }}</h1>

          <div class="esign-meta-grid">
            <div class="esign-meta-cell">
              <v-icon icon="mdi-calendar-outline" size="18" color="admin-primary" />
              <div>
                <div class="esign-meta-cell__label">วันที่ประกาศ</div>
                <div class="esign-meta-cell__value">{{ formatThaiDate(meta.promulgation_date) || '—' }}</div>
              </div>
            </div>
            <div class="esign-meta-cell">
              <v-icon icon="mdi-calendar-check-outline" size="18" color="admin-primary" />
              <div>
                <div class="esign-meta-cell__label">วันที่มีผล</div>
                <div class="esign-meta-cell__value">{{ formatThaiDate(meta.effective_date) || '—' }}</div>
              </div>
            </div>
            <div class="esign-meta-cell">
              <v-icon icon="mdi-book-open-page-variant-outline" size="18" color="admin-primary" />
              <div>
                <div class="esign-meta-cell__label">ราชกิจจาฯ</div>
                <div class="esign-meta-cell__value">{{ meta.gazette_reference || '—' }}</div>
              </div>
            </div>
            <div class="esign-meta-cell">
              <v-icon icon="mdi-office-building-outline" size="18" color="admin-primary" />
              <div>
                <div class="esign-meta-cell__label">หน่วยงาน</div>
                <div class="esign-meta-cell__value">{{ agencyLabel }}</div>
              </div>
            </div>
            <div class="esign-meta-cell">
              <v-icon icon="mdi-folder-outline" size="18" color="admin-primary" />
              <div>
                <div class="esign-meta-cell__label">กลุ่มกฎหมาย</div>
                <div class="esign-meta-cell__value">{{ lawGroupLabel }}</div>
              </div>
            </div>
            <div class="esign-meta-cell">
              <v-icon icon="mdi-format-list-numbered" size="18" color="admin-primary" />
              <div>
                <div class="esign-meta-cell__label">จำนวน{{ articleUnitLabel }}</div>
                <div class="esign-meta-cell__value">{{ articleCount }}</div>
              </div>
            </div>
          </div>
        </section>

        <iframe
          v-if="isOldDoc"
          :src="fileUrl"
          title="เอกสารต้นฉบับ"
          class="old-pdf-frame"
        />

        <section
          v-for="section in sections"
          :id="`sec-${section.id}`"
          :key="section.id"
          :ref="(el) => setSectionEl(el, section.id)"
          :data-sid="section.id"
          class="esign-card"
        >
          <div class="esign-card__top">
            <div class="d-flex align-center ga-2 flex-wrap">
              <span
                class="esign-card__badge"
                :class="{ 'esign-card__badge--chapter': section.isChapter }"
              >{{ section.badge }}</span>
              <v-chip
                v-if="!section.isChapter"
                size="x-small"
                color="success"
                variant="tonal"
              >มีผลบังคับ</v-chip>
            </div>
          </div>

          <div class="esign-card__body">
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

          <div v-if="!section.isChapter" class="esign-card__actions">
            <v-btn
              variant="text"
              size="small"
              prepend-icon="mdi-pencil-outline"
              class="text-none"
              @click="router.push(`/documents/${documentId}/review`)"
            >แก้ไขมาตรา</v-btn>
            <v-btn variant="text" size="small" prepend-icon="mdi-history" class="text-none" disabled>ประวัติ</v-btn>
            <v-btn
              variant="text"
              size="small"
              prepend-icon="mdi-link-variant"
              class="text-none"
              @click="router.push(`/documents/${documentId}/relations`)"
            >ความสัมพันธ์</v-btn>
            <v-btn
              variant="text"
              size="small"
              prepend-icon="mdi-content-copy"
              class="text-none"
              @click="copySection(section.id)"
            >คัดลอก</v-btn>
          </div>
        </section>
      </main>

      <!-- Right: info / e-sign panel -->
      <aside class="esign-side">
        <v-tabs v-model="sideTab" density="compact" color="admin-primary" class="mb-3">
          <v-tab value="info" class="text-none">ข้อมูล</v-tab>
          <v-tab value="timeline" class="text-none">Timeline</v-tab>
          <v-tab value="actions" class="text-none">ดำเนินการ</v-tab>
        </v-tabs>

        <div v-show="sideTab === 'info'" class="d-flex flex-column ga-3">
          <div v-if="!isEdit" class="esign-wait-box">
            <div class="d-flex align-start ga-2 mb-2">
              <v-icon icon="mdi-clock-outline" color="warning" />
              <div>
                <div class="text-subtitle-2 font-weight-bold">รอลงนามอิเล็กทรอนิกส์</div>
                <div class="text-caption text-medium-emphasis">
                  Document Soft Final
                  <span v-if="updatedAtLabel"> — ตั้งแต่ {{ updatedAtLabel }}</span>
                </div>
              </div>
            </div>
            <v-btn
              block
              color="warning"
              class="text-none font-weight-bold"
              prepend-icon="mdi-send-outline"
              :loading="confirming"
              @click="confirmSign"
            >ส่งลงนามทันที</v-btn>
            <v-btn
              block
              variant="text"
              size="small"
              class="text-none mt-1"
              @click="router.push(`/documents/${documentId}/esign/status`)"
            >ดูสถานะการลงนาม</v-btn>
          </div>

          <v-card flat border rounded="lg" class="pa-4">
            <div class="esign-kv">
              <span>รหัสกฎหมาย</span>
              <strong>{{ documentId }}</strong>
            </div>
            <div class="esign-kv">
              <span>ประเภท</span>
              <strong>{{ meta.law_type || '—' }}</strong>
            </div>
            <div class="esign-kv">
              <span>กลุ่มกฎหมาย</span>
              <strong>{{ lawGroupLabel }}</strong>
            </div>
            <div class="esign-kv">
              <span>วันที่ประกาศ</span>
              <strong>{{ formatThaiDate(meta.promulgation_date) || '—' }}</strong>
            </div>
            <div class="esign-kv">
              <span>วันที่มีผล</span>
              <strong>{{ formatThaiDate(meta.effective_date) || '—' }}</strong>
            </div>
            <div class="esign-kv">
              <span>วันที่เผยแพร่</span>
              <strong class="text-warning">รอลงนาม</strong>
            </div>
            <div class="esign-kv">
              <span>จำนวน{{ articleUnitLabel }}</span>
              <strong>{{ articleCount }}</strong>
            </div>
            <div class="esign-kv">
              <span>หน่วยงาน</span>
              <strong>{{ agencyLabel }}</strong>
            </div>
            <div class="esign-kv">
              <span>ผู้นำเข้า</span>
              <strong>{{ meta.imported_by || '—' }}</strong>
            </div>
            <div class="esign-kv">
              <span>แก้ไขล่าสุด</span>
              <strong>{{ updatedAtLabel || '—' }}</strong>
            </div>
            <div v-if="signatoryLabel" class="esign-kv">
              <span>ผู้ลงนาม</span>
              <strong>{{ signatoryLabel }}</strong>
            </div>
          </v-card>
        </div>

        <div v-show="sideTab === 'timeline'" class="pa-2">
          <v-timeline density="compact" side="end" truncate-line="both">
            <v-timeline-item dot-color="success" size="x-small">
              <div class="text-body-2 font-weight-medium">กำหนดสิทธิ์แล้ว</div>
              <div class="text-caption text-medium-emphasis">พร้อมส่งลงนามอิเล็กทรอนิกส์</div>
            </v-timeline-item>
            <v-timeline-item dot-color="warning" size="x-small">
              <div class="text-body-2 font-weight-medium">รอลงนาม</div>
              <div class="text-caption text-medium-emphasis">สถานะปัจจุบัน</div>
            </v-timeline-item>
            <v-timeline-item dot-color="grey" size="x-small">
              <div class="text-body-2 font-weight-medium text-medium-emphasis">เผยแพร่สาธารณะ</div>
              <div class="text-caption text-medium-emphasis">หลังยืนยันลงนาม</div>
            </v-timeline-item>
          </v-timeline>
        </div>

        <div v-show="sideTab === 'actions'" class="d-flex flex-column ga-2">
          <v-btn
            variant="outlined"
            prepend-icon="mdi-eye-outline"
            class="justify-start text-none"
            @click="router.push(`/documents/${documentId}/esign/preview`)"
          >ดูตัวอย่างเอกสาร</v-btn>
          <v-btn
            variant="outlined"
            prepend-icon="mdi-pencil-outline"
            class="justify-start text-none"
            @click="router.push(`/documents/${documentId}/law-info`)"
          >แก้ไขข้อมูลเอกสาร</v-btn>
          <v-btn
            variant="outlined"
            prepend-icon="mdi-graph-outline"
            class="justify-start text-none"
            @click="router.push(`/documents/${documentId}/relations`)"
          >ความสัมพันธ์กฎหมาย</v-btn>
          <v-btn
            v-if="isEdit"
            variant="outlined"
            prepend-icon="mdi-file-document-edit-outline"
            class="justify-start text-none"
            @click="router.push(`/documents/${documentId}/review`)"
          >แก้ไขเนื้อหา</v-btn>
          <v-btn
            v-else
            variant="outlined"
            prepend-icon="mdi-shield-lock-outline"
            class="justify-start text-none"
            @click="router.push(`/documents/${documentId}/permissions`)"
          >กลับไปกำหนดสิทธิ์</v-btn>
        </div>
      </aside>
    </div>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AppShell from '../shared/AppShell.vue';
import BlockFlow from '../shared/BlockFlow.vue';
import { useDocumentStore } from '../../stores/documentStore';
import { buildSections, buildTocGroups } from '../../composables/useLawSections';
import { writeStage } from '../../data/documentPipeline';
import type { LawMeta } from '../../types/document';
import { formatThaiDate } from '../../utils/thaiDate';
import { documentFileUrl } from '../../api/client';

const props = withDefaults(defineProps<{ documentId: string; mode?: 'esign' | 'edit' }>(), {
  mode: 'esign',
});
const isEdit = computed(() => props.mode === 'edit');
const isOldDoc = computed(() => documentStore.review?.law_meta?.document_type === 'old');
const fileUrl = computed(() => documentFileUrl(props.documentId));
const router = useRouter();
const documentStore = useDocumentStore();

const tocQuery = ref('');
const sideTab = ref('info');
const confirming = ref(false);
const collapsed = ref<Set<string>>(new Set());
const activeId = ref('');
const sectionEls = ref<Record<string, HTMLElement>>({});
const docScrollEl = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

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
const sections = computed(() => buildSections(documentStore.review));
const tocGroups = computed(() => buildTocGroups(sections.value));

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
  return meta.value.agency || '—';
});

const lawGroupLabel = computed(() => {
  if (meta.value.law_groups?.length) return meta.value.law_groups.join(', ');
  return meta.value.law_group || '—';
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

const filteredTocGroups = computed(() => {
  const needle = tocQuery.value.trim().toLowerCase();
  if (!needle) return tocGroups.value;

  return tocGroups.value
    .map((group) => ({
      ...group,
      sectionIds: group.sectionIds.filter((sid) => {
        const badge = badgeOf(sid).toLowerCase();
        const section = sections.value.find((s) => s.id === sid);
        const body = (section?.headBodyText ?? '').toLowerCase();
        return badge.includes(needle) || body.includes(needle) || group.label.toLowerCase().includes(needle);
      }),
    }))
    .filter((group) => group.sectionIds.length > 0 || group.label.toLowerCase().includes(needle));
});

function badgeOf(sectionId: string): string {
  return sections.value.find((section) => section.id === sectionId)?.badge ?? '';
}

function toggleGroup(label: string): void {
  const next = new Set(collapsed.value);
  if (next.has(label)) next.delete(label);
  else next.add(label);
  collapsed.value = next;
}

function scrollTo(sectionId: string): void {
  const target = document.getElementById(`sec-${sectionId}`);
  if (!target) return;
  const root = docScrollEl.value;
  if (!root) {
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return;
  }
  const rootTop = root.getBoundingClientRect().top;
  const targetTop = target.getBoundingClientRect().top;
  root.scrollTo({ top: root.scrollTop + (targetTop - rootTop) - 8, behavior: 'smooth' });
}

function setSectionEl(el: Element | { $el?: Element } | null, sectionId: string): void {
  const element = el instanceof HTMLElement
    ? el
    : el?.$el instanceof HTMLElement
      ? el.$el
      : null;

  if (element) {
    sectionEls.value[sectionId] = element;
    return;
  }
  delete sectionEls.value[sectionId];
}

function setupObserver(): void {
  observer?.disconnect();
  observer = new IntersectionObserver(
    (entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting)
        .sort((left, right) => left.boundingClientRect.top - right.boundingClientRect.top)[0];
      if (visible) {
        activeId.value = (visible.target as HTMLElement).dataset.sid ?? '';
      }
    },
    {
      root: docScrollEl.value,
      rootMargin: '-12px 0px -65% 0px',
      threshold: 0,
    },
  );
  Object.values(sectionEls.value).forEach((el) => observer?.observe(el));
}

async function copySection(sectionId: string): Promise<void> {
  const section = sections.value.find((s) => s.id === sectionId);
  if (!section) return;
  const parts = [
    section.badge,
    section.headBodyText,
    ...section.children.map((b) => b.approved_text || b.normalized_text || b.raw_text || ''),
  ].filter(Boolean);
  try {
    await navigator.clipboard.writeText(parts.join('\n'));
  } catch {
    // ignore clipboard errors in restricted contexts
  }
}

async function confirmSign(): Promise<void> {
  confirming.value = true;
  try {
    writeStage(props.documentId, 'wait_esign');
    await router.push(`/documents/${props.documentId}/esign/preview`);
  } finally {
    confirming.value = false;
  }
}

onMounted(() => {
  if (documentStore.documentId !== props.documentId || !documentStore.review) {
    void documentStore.fetch(props.documentId);
  }
  if (!isEdit.value) {
    writeStage(props.documentId, 'wait_esign');
  }
});

watch(sections, async (value) => {
  activeId.value = value[0]?.id ?? '';
  await nextTick();
  setupObserver();
}, { immediate: true });

onBeforeUnmount(() => {
  observer?.disconnect();
  documentStore.reset();
});
</script>

<style scoped>
.esign-page {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
  height: 100%;
  overflow: hidden;
}

.esign-toolbar {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}

.esign-grid {
  display: grid;
  grid-template-columns: 260px minmax(0, 1fr) 300px;
  gap: 16px;
  align-items: stretch;
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
}

.esign-toc,
.esign-side,
.esign-headcard,
.esign-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
}

.esign-toc,
.esign-side,
.esign-doc {
  min-height: 0;
  max-height: 100%;
  overflow: auto;
  overscroll-behavior: contain;
}

.esign-toc {
  padding: 14px;
}

.esign-toc__head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 10px;
}

.esign-doc {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-width: 0;
  padding-right: 2px;
}

.esign-toc__group-btn {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  border: none;
  background: transparent;
  padding: 8px 4px;
  font: inherit;
  font-size: 13px;
  font-weight: 700;
  color: #1d4ed8;
  cursor: pointer;
  text-align: left;
}

.esign-toc__items {
  display: flex;
  flex-direction: column;
  padding: 0 0 6px 4px;
}

.esign-toc__item {
  display: flex;
  align-items: center;
  gap: 8px;
  border: none;
  background: transparent;
  text-align: left;
  padding: 7px 8px;
  border-radius: 8px;
  font: inherit;
  font-size: 13px;
  color: #475569;
  cursor: pointer;
}

.esign-toc__item:hover,
.esign-toc__item.is-active {
  background: #eff6ff;
  color: #1d4ed8;
  font-weight: 700;
}

.esign-toc__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.esign-toc__dot.is-active-status { background: #16a34a; }
.esign-toc__dot.is-cancelled { background: #dc2626; }
.esign-toc__dot.is-amended { background: #7c3aed; }
.esign-toc__dot.is-pending { background: #eab308; }

.esign-toc__stats {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px dashed #dbe3ee;
}

.esign-toc__stat-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  color: #64748b;
  padding: 3px 0;
}

.esign-toc__stat-row span {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.esign-headcard {
  padding: 20px;
}

.esign-headcard__title {
  margin: 0 0 16px;
  font-size: clamp(20px, 2.4vw, 28px);
  font-weight: 800;
  color: #1e2a4a;
  line-height: 1.35;
}

.esign-meta-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.esign-meta-cell {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  background: #f8fafc;
  border: 1px solid #eef2f7;
  border-radius: 12px;
  padding: 10px 12px;
}

.esign-meta-cell__label {
  font-size: 11px;
  color: #64748b;
}

.esign-meta-cell__value {
  font-size: 13px;
  font-weight: 700;
  color: #1e293b;
  word-break: break-word;
}

.esign-card {
  padding: 16px 18px 8px;
}

.esign-card__top {
  margin-bottom: 10px;
}

.esign-card__badge {
  display: inline-block;
  background: #ecfdf5;
  color: #047857;
  font-size: 13px;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 10px;
}

.esign-card__badge--chapter {
  background: #eef2ff;
  color: #4338ca;
}

.esign-card__body {
  min-width: 0;
}

.esign-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  margin-top: 10px;
  padding-top: 8px;
  border-top: 1px dashed #e2e8f0;
}

.esign-side {
  padding: 12px;
}

.esign-wait-box {
  background: #fff7ed;
  border: 1px solid #fed7aa;
  border-radius: 14px;
  padding: 14px;
}

.esign-kv {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 7px 0;
  font-size: 13px;
  border-bottom: 1px solid #f1f5f9;
}

.esign-kv:last-child {
  border-bottom: none;
}

.esign-kv span {
  color: #64748b;
  flex-shrink: 0;
}

.esign-kv strong {
  text-align: right;
  font-weight: 600;
  color: #1e293b;
  word-break: break-word;
}

.old-pdf-frame {
  width: 100%;
  height: calc(100vh - 160px);
  border: none;
  border-radius: 12px;
  box-shadow: 0 2px 16px rgba(0, 0, 0, 0.12);
}

@media (max-width: 1220px) {
  .esign-page {
    overflow: auto;
  }

  .esign-grid {
    grid-template-columns: 1fr;
    overflow: visible;
    flex: none;
  }

  .esign-toc,
  .esign-side,
  .esign-doc {
    max-height: none;
    overflow: visible;
  }

  .esign-meta-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 720px) {
  .esign-meta-grid {
    grid-template-columns: 1fr;
  }
}
</style>
