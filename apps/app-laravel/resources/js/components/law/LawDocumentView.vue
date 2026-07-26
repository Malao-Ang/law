<template>
  <div class="lawx">
    <ELawNavbar @go-admin="router.push('/admin')" />

    <div class="lawx-breadcrumb">
      <v-container style="max-width: 1360px" class="py-0">
        <div class="lawx-bc">
          <button type="button" class="lawx-bc__item" @click="router.push('/')">
            <v-icon icon="mdi-home-outline" size="13" />
            หน้าหลัก
          </button>
          <span class="lawx-bc__sep">›</span>
          <button type="button" class="lawx-bc__item" @click="router.push('/database')">
            ฐานข้อมูลกฎหมาย
          </button>
          <template v-if="meta.law_type">
            <span class="lawx-bc__sep">›</span>
            <span class="lawx-bc__item">{{ meta.law_type }}</span>
          </template>
          <span class="lawx-bc__sep">›</span>
          <span class="lawx-bc__item lawx-bc__item--current">{{ meta.title || 'กฎหมาย' }}</span>
        </div>
      </v-container>
    </div>

    <v-main class="lawx-main">
      <div class="lawx-subbar">
        <v-btn variant="outlined" size="small" prepend-icon="mdi-arrow-left"
          @click="router.push('/database')">ย้อนกลับฐานข้อมูล</v-btn>
        <div class="lawx-actions">
          <v-btn variant="outlined" size="small"
            :prepend-icon="tocOpen ? 'mdi-eye-off-outline' : 'mdi-table-of-contents'"
            @click="tocOpen = !tocOpen">{{ tocOpen ? 'ซ่อนสารบัญ' : 'เปิดสารบัญ' }}</v-btn>
          <v-btn variant="outlined" size="small"
            :prepend-icon="infoOpen ? 'mdi-eye-off-outline' : 'mdi-card-text-outline'"
            @click="infoOpen = !infoOpen">{{ infoOpen ? 'ซ่อนข้อมูล' : 'เปิดข้อมูล' }}</v-btn>
          <v-btn variant="outlined" size="small" prepend-icon="mdi-printer-outline"
            @click="printPage()">พิมพ์</v-btn>
          <v-btn variant="outlined" size="small" color="error" prepend-icon="mdi-file-pdf-box"
            :loading="exportingPdf"
            :disabled="exportingPdf"
            @click="downloadPdf()">ดาวน์โหลด PDF</v-btn>
        </div>
      </div>

      <div v-if="documentStore.loading" class="d-flex align-center justify-center ga-3 pa-16 text-medium-emphasis">
        <v-progress-circular indeterminate />
        <span>กำลังโหลด...</span>
      </div>
      <v-alert v-else-if="documentStore.error" type="error" variant="tonal" density="compact" class="ma-4">
        {{ documentStore.error }}
      </v-alert>

      <template v-else-if="documentStore.review">
      <v-alert v-if="pdfExportError" type="error" variant="tonal" density="compact" class="ma-4">
        {{ pdfExportError }}
      </v-alert>

      <v-alert
        v-if="meta.access_scope === 'private'"
        type="warning"
        variant="tonal"
        density="comfortable"
        class="ma-4"
      >
        เอกสารนี้ถูกกำหนดให้เป็น Private และไม่แสดงบนหน้าสาธารณะ
      </v-alert>

      <div
        class="lawx-grid"
        :class="{
          'is-toc-hidden': !tocOpen,
          'is-info-hidden': !infoOpen,
        }"
      >
      <v-card v-show="tocOpen" tag="aside" class="lawx-toc" elevation="0">
        <p class="lawx-toc__title"><span class="mdi mdi-format-list-bulleted" /> สารบัญมาตรา</p>
        <div v-for="group in tocGroups" :key="group.label" class="lawx-toc__group">
          <v-btn variant="text" block class="justify-space-between font-weight-bold text-body-2 mt-2 px-2"
            style="color:#1d4ed8" @click="toggleGroup(group.label)">
            <span>{{ group.label }}</span>
            <v-icon :icon="collapsed.has(group.label) ? 'mdi-chevron-down' : 'mdi-chevron-up'" />
          </v-btn>
          <div v-show="!collapsed.has(group.label)" class="lawx-toc__items">
            <button
              v-for="sid in group.sectionIds"
              :key="sid"
              class="lawx-toc__item"
              :class="{ 'is-active': activeId === sid }"
              @click="scrollTo(sid)"
            >{{ badgeOf(sid) }}</button>
          </div>
        </div>
      </v-card>

      <main class="lawx-doc">
        <v-card tag="section" class="lawx-headcard" elevation="0">
          <span class="lawx-headcard__badge">{{ meta.law_type || 'เอกสาร' }}</span>
          <h1 class="lawx-headcard__title">{{ meta.title || documentStore.review.source_file }}</h1>
          <div class="lawx-headcard__meta">
            <span v-if="meta.promulgation_date"><span class="mdi mdi-calendar" /> ประกาศ {{ meta.promulgation_date }}</span>
            <span v-if="meta.gazette_reference"><span class="mdi mdi-book-open-variant" /> {{ meta.gazette_reference }}</span>
            <span v-if="meta.royal_command"><span class="mdi mdi-crown-outline" /> {{ meta.royal_command }}</span>
          </div>
        </v-card>

        <v-card
          v-for="section in sections"
          :id="`sec-${section.id}`"
          :key="section.id"
          :ref="(el) => setSectionEl(el, section.id)"
          :data-sid="section.id"
          tag="section"
          class="lawx-card"
          elevation="0"
        >
          <div class="lawx-card__body">
            <span class="lawx-card__badge" :class="{ 'lawx-card__badge--chapter': section.isChapter }">{{ section.badge }}</span>
            <div class="lawx-card__content">
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
          </div>
          <div v-if="sectionRelations(section.id).length" class="lawx-rel">
            <v-btn variant="tonal" color="primary" size="small"
              prepend-icon="mdi-link-variant"
              :append-icon="expanded.has(section.id) ? 'mdi-chevron-up' : 'mdi-chevron-down'"
              @click="toggleExpand(section.id)">
              กฎหมายที่เกี่ยวข้อง · {{ sectionRelations(section.id).length }}
            </v-btn>
            <ul v-show="expanded.has(section.id)" class="lawx-rel__list">
              <li
                v-for="rel in sectionRelations(section.id)"
                :key="rel.id"
                :class="relationListClass(rel.type)"
              >
                <span class="mdi" :class="RELATION_TYPE_ICONS[rel.type] ?? 'mdi-link-variant'" />
                <span class="lawx-rel__type">{{ relationTypeLabel(rel.type) }}</span>
                <a v-if="safeUrl(rel.url)" :href="safeUrl(rel.url) ?? ''" target="_blank" rel="noopener">{{ rel.target_title }}</a>
                <span v-else>{{ rel.target_title }}</span>
                <span v-if="rel.target_section" class="lawx-rel__sec">{{ rel.target_section }}</span>
                <span v-if="rel.note" class="lawx-rel__note">— {{ rel.note }}</span>
              </li>
            </ul>
          </div>
        </v-card>
      </main>

      <aside v-show="infoOpen" class="lawx-info">
        <LawInfoPanel :meta="meta" :article-count="articleCount" :article-unit-label="articleUnitLabel" :relations="documentRelations(relations)" />
      </aside>
      </div>
      </template>

      <ELawFooter />
    </v-main>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useDocumentStore } from '../../stores/documentStore';
import { buildSections, buildTocGroups, relationsForSection, documentRelations } from '../../composables/useLawSections';
import type { LawMeta, LawRelation, RelationType } from '../../types/document';
import {
  RELATION_TYPE_ICONS,
  relationTypeLabel,
} from '../../types/lawRelation';
import { downloadPdfExport } from '../../api/client';
import LawInfoPanel from './LawInfoPanel.vue';
import BlockFlow from '../shared/BlockFlow.vue';
import ELawFooter from '../shared/ELawFooter.vue';
import ELawNavbar from '../shared/ELawNavbar.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();
const exportingPdf = ref(false);
const pdfExportError = ref('');

onMounted(() => {
  if (documentStore.documentId !== props.documentId || !documentStore.review) {
    documentStore.fetch(props.documentId);
  }
});

const sections = computed(() => buildSections(documentStore.review));
const tocGroups = computed(() => buildTocGroups(sections.value));

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
  access_scope: 'public',
  permission_group_ids: [],
};

const meta = computed<LawMeta>(() => documentStore.review?.law_meta ?? EMPTY_META);

const articleCount = computed(() =>
  sections.value.filter((s) => s.badge.startsWith('มาตรา') || s.badge.startsWith('ข้อ')).length,
);
const articleUnitLabel = computed(() => {
  const hasClause = sections.value.some((s) => s.badge.startsWith('ข้อ'));
  const hasArticle = sections.value.some((s) => s.badge.startsWith('มาตรา'));
  if (hasClause && hasArticle) return 'ข้อ/มาตรา';
  if (hasClause) return 'ข้อ';
  if (hasArticle) return 'มาตรา';
  return 'ข้อ/มาตรา';
});

const relations = computed<LawRelation[]>(() => documentStore.review?.relations ?? []);
const tocOpen = ref(true);
const infoOpen = ref(true);
const expanded = ref<Set<string>>(new Set());

function sectionRelations(sectionId: string): LawRelation[] {
  return relationsForSection(relations.value, sectionId);
}

function toggleExpand(sectionId: string): void {
  const next = new Set(expanded.value);
  if (next.has(sectionId)) next.delete(sectionId);
  else next.add(sectionId);
  expanded.value = next;
}

function badgeOf(sectionId: string): string {
  return sections.value.find((section) => section.id === sectionId)?.badge ?? '';
}

function safeUrl(url: string | null): string | null {
  if (!url) return null;
  const trimmed = url.trim();
  return /^https?:\/\//i.test(trimmed) ? trimmed : null;
}

function relationListClass(type: RelationType): Record<string, boolean> {
  return {
    'is-repeal': type === 'repeals',
    'is-supersedes': type === 'supersedes',
    'is-amends': type === 'amends',
    'is-issued-under': type === 'issued_under',
  };
}

function printPage(): void {
  window.print();
}

async function downloadPdf(): Promise<void> {
  if (exportingPdf.value) return;

  exportingPdf.value = true;
  pdfExportError.value = '';
  try {
    await downloadPdfExport(props.documentId);
    await documentStore.fetch(props.documentId, true);
  } catch (error) {
    pdfExportError.value = error instanceof Error ? error.message : 'ส่งออก PDF ไม่สำเร็จ';
  } finally {
    exportingPdf.value = false;
  }
}

const sectionEls = ref<Record<string, HTMLElement>>({});
const activeId = ref('');
const collapsed = ref<Set<string>>(new Set());
let observer: IntersectionObserver | null = null;

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

function toggleGroup(label: string): void {
  const next = new Set(collapsed.value);
  if (next.has(label)) next.delete(label);
  else next.add(label);
  collapsed.value = next;
}

function scrollTo(sectionId: string): void {
  document.getElementById(`sec-${sectionId}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
    { rootMargin: '-80px 0px -65% 0px', threshold: 0 },
  );

  Object.values(sectionEls.value).forEach((el) => observer?.observe(el));
}

watch(sections, async (value) => {
  activeId.value = value[0]?.id ?? '';
  await nextTick();
  setupObserver();
}, { immediate: true });

watch(() => props.documentId, () => {
  tocOpen.value = true;
  infoOpen.value = true;
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<style scoped>
.lawx {
  min-height: 100vh;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  color: #1e293b;
  background: #f6f4ef;
}

.lawx-breadcrumb {
  background: #ffffff;
  border-bottom: 1px solid #e7e2d9;
  padding: 9px 0;
}

.lawx-bc {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  padding: 0 24px;
}

.lawx-bc__sep {
  color: #9ca3af;
  font-size: 14px;
  user-select: none;
}

.lawx-bc__item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 14px;
  color: #4e4538;
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;
  white-space: nowrap;
  max-width: 300px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.lawx-bc__item:hover {
  color: #7b580d;
  text-decoration: underline;
}

.lawx-bc__item--current {
  color: #7b580d;
  font-weight: 700;
  cursor: default;
  text-decoration: none !important;
  max-width: 400px;
}

.lawx-main {
  background: #f6f4ef;
}

.lawx-subbar {
  max-width: 1360px;
  margin: 0 auto;
  padding: 18px 24px 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.lawx-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }

/* ponytail: lawx-grid 3-column responsive layout + sticky topbar/toc/info — no Vuetify equivalent */
.lawx-grid {
  max-width: 1360px;
  margin: 0 auto;
  padding: 18px 24px 72px;
  display: grid;
  grid-template-columns: 250px minmax(0, 820px) 280px;
  justify-content: center;
  gap: 22px;
  align-items: start;
}

.lawx-grid.is-toc-hidden {
  grid-template-columns: minmax(0, 880px) 280px;
}

.lawx-grid.is-info-hidden {
  grid-template-columns: 250px minmax(0, 880px);
}

.lawx-grid.is-toc-hidden.is-info-hidden {
  grid-template-columns: minmax(0, 920px);
}

.lawx-toc,
.lawx-info :deep(.law-info-panel) {
  background: rgb(var(--v-theme-detail-surface));
  border: 1px solid rgba(226, 232, 240, 0.9);
  box-shadow: 0 18px 42px rgba(148, 163, 184, 0.12);
}

.lawx-toc {
  border-radius: 18px;
  padding: 14px;
  position: sticky;
  top: 84px;
  max-height: calc(100vh - 108px);
  overflow-y: auto;
}

.lawx-toc__title { font-family: 'TH Sarabun New', 'Sarabun', sans-serif; font-weight: 700; font-size: 16px; margin: 0 0 10px; display: flex; align-items: center; gap: 6px; color: #343028; }
.lawx-toc__items { display: flex; flex-direction: column; padding: 5px 0 4px 8px; }
.lawx-toc__item { text-align: left; background: transparent; border: none; border-left: 2px solid transparent; padding: 7px 10px; font-size: 13px; color: #475569; border-radius: 0; cursor: pointer; font-family: inherit; }
.lawx-toc__item:hover { color: #7b580d; }
.lawx-toc__item.is-active { background: transparent; border-left-color: #b68d40; color: #7b580d; font-weight: 700; }

.lawx-doc {
  min-width: 0;
}

.lawx-headcard {
  background: rgb(var(--v-theme-detail-surface));
  border: 1px solid #e7e2d9;
  border-top: 5px solid #b68d40;
  border-radius: 22px;
  padding: 32px 28px;
  text-align: center;
  margin-bottom: 18px;
  box-shadow: 0 10px 40px rgba(75, 70, 61, 0.08);
}

.lawx-headcard__badge { display: inline-block; background: #fef9ec; color: #7b580d; border: 1px solid #d2c5b3; font-family: 'TH Sarabun New', 'Sarabun', sans-serif; font-size: 14px; font-weight: 700; padding: 4px 14px; border-radius: 999px; margin-bottom: 12px; }
.lawx-headcard__title { font-family: 'TH Sarabun New', 'Sarabun', sans-serif; font-size: clamp(22px, 3vw, 30px); font-weight: 700; color: #1f1b14; margin: 0 0 14px; line-height: 1.3; }
.lawx-headcard__meta { display: flex; flex-wrap: wrap; justify-content: center; gap: 16px; font-family: 'TH Sarabun New', 'Sarabun', sans-serif; font-size: 14px; color: #4e4538; }
.lawx-headcard__meta .mdi { color: #b68d40; }

.lawx-card {
  background: rgb(var(--v-theme-detail-surface));
  border: 1px solid rgba(226, 232, 240, 0.82);
  border-radius: 22px;
  padding: 20px 22px 10px;
  margin-bottom: 16px;
  box-shadow: 0 18px 38px rgba(148, 163, 184, 0.1);
}

.lawx-card__body { display: flex; flex-direction: column; gap: 10px; }
.lawx-card__badge { align-self: flex-start; flex-shrink: 0; max-width: 100%; background: #ecfdf5; color: #047857; font-size: 13px; font-weight: 700; padding: 5px 12px; border-radius: 10px; height: fit-content; white-space: normal; overflow-wrap: break-word; line-height: 1.3; }
.lawx-card__badge--chapter { background: #eef2ff; color: #4338ca; }
.lawx-card__content { flex: 1; min-width: 0; }
.lawx-card__content :deep(.block-flow) { font-size: 13px; line-height: 1.72; }
.lawx-card__content :deep(table) { font-size: 12px; }

.lawx-rel { margin-top: 12px; border-top: 1px dashed #d7dee7; padding-top: 10px; }
.lawx-rel__list { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.lawx-rel__list li { display: flex; align-items: center; gap: 6px; font-size: 13px; color: #334155; }
.lawx-rel__list li.is-repeal { color: #dc2626; }
.lawx-rel__list li.is-supersedes { color: #ea580c; }
.lawx-rel__list li.is-amends { color: #0d9488; }
.lawx-rel__list li.is-issued-under { color: #7c3aed; }
.lawx-rel__type { font-size: 11px; font-weight: 600; color: #64748b; }
.lawx-rel__sec { color: #64748b; font-size: 12px; }
.lawx-rel__note { color: #94a3b8; font-size: 12px; }

.lawx-info {
  position: sticky;
  top: 84px;
}

@media (max-width: 1220px) {
  .lawx-grid,
  .lawx-grid.is-toc-hidden,
  .lawx-grid.is-info-hidden,
  .lawx-grid.is-toc-hidden.is-info-hidden {
    grid-template-columns: minmax(0, 1fr);
  }

  .lawx-toc,
  .lawx-info {
    position: static;
    max-height: none;
  }
}

@media (max-width: 920px) {
  .lawx-subbar {
    padding: 16px 20px 0;
    flex-direction: column;
    align-items: stretch;
  }

  .lawx-actions {
    width: 100%;
    justify-content: stretch;
  }

  .lawx-actions > * {
    flex: 1 1 180px;
  }

  .lawx-grid {
    padding: 16px 20px 56px;
  }

  .lawx-card__body {
    flex-direction: column;
  }
}

@media print {
  :deep(.v-app-bar), .lawx-subbar, .lawx-toc, .lawx-info { display: none !important; }
  .lawx-grid { grid-template-columns: 1fr; padding: 0; }
}
</style>
