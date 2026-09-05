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

    <v-main class="lawx-main pt-8">
      <div class="lawx-subbar">
        <v-btn variant="outlined" size="small" prepend-icon="mdi-arrow-left"
          @click="router.push('/database')">ย้อนกลับฐานข้อมูล</v-btn>
        <div class="lawx-actions">
          <v-btn v-if="!usesOriginalPdfLayout" variant="outlined" size="small"
            :prepend-icon="tocOpen ? 'mdi-eye-off-outline' : 'mdi-table-of-contents'"
            @click="tocOpen = !tocOpen">{{ tocOpen ? 'ซ่อนสารบัญ' : 'เปิดสารบัญ' }}</v-btn>
          <v-btn v-if="!usesOriginalPdfLayout" variant="outlined" size="small"
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
          v-else-if="documentStore.review && !isPublished"
        type="info"
        variant="tonal"
        density="compact"
        class="ma-4"
        prepend-icon="mdi-eye-off-outline"
      >
        เอกสารนี้ยังไม่เผยแพร่
      </v-alert>

      <template v-else-if="documentStore.review && isPublished">
      <div
        class="lawx-grid"
        :class="{
          'is-toc-hidden': !tocOpen,
          'is-info-hidden': !infoOpen,
          'is-original-pdf': usesOriginalPdfLayout,
        }"
      >
      <template v-if="!usesOriginalPdfLayout">
      <v-card v-show="tocOpen" tag="aside" class="lawx-toc" elevation="0">
        <p class="lawx-toc__title"><span class="mdi mdi-format-list-bulleted" /> สารบัญ{{ unitWord }}</p>
        <div class="lawx-toc__scroll">
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
        </div>
      </v-card>
      </template>

      <main
        class="lawx-doc"
        :class="{
          'is-superseded': notCurrentVersion,
          'is-original-pdf': usesOriginalPdfLayout,
        }"
      >
        <v-card tag="section" class="lawx-headcard" elevation="0" :style="{ borderTopColor: badgeColor }">
          <DocBadge :type="badgeType" :label="badgeLabel" class="lawx-headcard__badge" />
          <h1 class="lawx-headcard__title">{{ meta.title || cleanSourceFile || 'เอกสาร' }}</h1>
          <p v-if="buddhistYear" class="lawx-headcard__year">พ.ศ. {{ buddhistYear }}</p>
          <p v-if="meta.issuer" class="lawx-headcard__issuer">
            <span class="mdi mdi-office-building-outline" />
            {{ meta.issuer }}
          </p>
          <div class="lawx-headcard__meta">
            <span v-if="meta.promulgation_date"><span class="mdi mdi-calendar" /> ประกาศ {{ formatLawDate(meta.promulgation_date) }}</span>
            <span v-if="meta.gazette_reference"><span class="mdi mdi-book-open-variant" /> {{ meta.gazette_reference }}</span>
            <span v-if="meta.royal_command"><span class="mdi mdi-crown-outline" /> {{ meta.royal_command }}</span>
          </div>
        </v-card>

        <template v-if="usesOriginalPdfLayout">
          <v-card tag="section" class="lawx-card" elevation="0">
            <iframe :src="fileUrl" title="เอกสารต้นฉบับ" style="width:100%;height:80vh;border:0;border-radius:8px" />
          </v-card>
        </template>
        <template v-else>
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
          <div v-if="sectionRelations(section.id).length" class="lawx-section-relcard">
            <div class="lawx-section-relcard__head">
              <span class="mdi mdi-scale-balance" />
              กฎหมายที่เกี่ยวข้อง
            </div>
            <div
              v-for="group in groupedSectionRelations(section.id)"
              :key="group.type"
              class="lawx-section-relcard__group"
            >
              <div class="lawx-section-relcard__group-label" :class="`is-${group.type}`">{{ group.label }}</div>
              <a
                v-for="rel in group.items"
                :key="rel.id"
                class="lawx-section-relcard__item"
                :class="`is-${group.type}`"
                :href="relationHref(rel) ?? undefined"
                :target="safeUrl(rel.url) ? '_blank' : undefined"
                rel="noopener"
              >
                <span class="mdi lawx-section-relcard__icon" :class="relationIcon(rel)" />
                <span class="lawx-section-relcard__main">
                  <span class="lawx-section-relcard__title">{{ rel.target_title }}</span>
                  <span v-if="rel.note" class="lawx-section-relcard__note">— {{ rel.note }}</span>
                </span>
                <span v-if="rel.target_section" class="lawx-section-relcard__sec">{{ rel.target_section }}</span>
              </a>
            </div>
          </div>
        </v-card>
        </template>
      </main>

      <aside v-show="infoOpen" class="lawx-info">
        <v-card v-if="parentLawRelation" flat class="lawx-parent-law-card mb-4" rounded="lg">
          <div class="d-flex align-start ga-3 pa-4">
            <v-avatar color="primary" variant="tonal" size="36">
              <v-icon icon="mdi-bank" size="18" />
            </v-avatar>
            <div>
              <div class="text-caption font-weight-bold text-medium-emphasis">กฎหมายแม่</div>
              <a class="text-body-2 font-weight-bold d-block lawx-parent-law-card__link"
                @click="router.push(`/law/${parentLawRelation.target_document_id}`)">
                {{ parentLawRelation.target_title }}
              </a>
              <div v-if="parentLawRelation.target_section" class="text-caption text-medium-emphasis mt-1">
                {{ parentLawRelation.target_section }}
                <span v-if="parentLawRelation.note"> — {{ parentLawRelation.note }}</span>
              </div>
            </div>
          </div>
        </v-card>
        <section v-if="docRelations.length" class="lawx-parentcard">
          <div class="lawx-parentcard__head">
            <span class="mdi mdi-bank" />
            กฎหมายแม่ / เกี่ยวข้องทั้งฉบับ
          </div>
          <div
            v-for="group in groupedDocRelations"
            :key="group.type"
            class="lawx-relgroup"
          >
            <div class="lawx-relgroup__label" :class="`is-${group.type}`">{{ group.label }}</div>
            <a
              v-for="rel in group.items"
              :key="rel.id"
              class="lawx-relrow"
              :class="`is-${group.type}`"
              :href="relationHref(rel) ?? undefined"
              :target="safeUrl(rel.url) ? '_blank' : undefined"
              rel="noopener"
            >
              <span class="mdi lawx-relrow__icon" :class="relationIcon(rel)" />
              <span class="lawx-relrow__main">
                <span class="lawx-relrow__title">{{ rel.target_title }}</span>
                <span v-if="rel.note" class="lawx-relrow__note">— {{ rel.note }}</span>
              </span>
              <span v-if="rel.target_section" class="lawx-relrow__sec">{{ rel.target_section }}</span>
            </a>
          </div>
        </section>
        <section v-if="sectionRelationSummaries.length" class="lawx-parentcard lawx-parentcard--sections">
          <div class="lawx-parentcard__head">
            <span class="mdi mdi-file-tree-outline" />
            ความสัมพันธ์ราย{{ unitWord }}
          </div>
          <div v-for="summary in sectionRelationSummaries" :key="summary.sectionId" class="lawx-section-rel-summary">
            <div class="lawx-section-rel-summary__badge">{{ summary.badge }}</div>
            <div class="lawx-section-rel-summary__body">
              <div
                v-for="group in summary.groups"
                :key="group.type"
                class="lawx-relgroup"
              >
                <div class="lawx-relgroup__label" :class="`is-${group.type}`">{{ group.label }}</div>
                <a
                  v-for="rel in group.items"
                  :key="rel.id"
                  class="lawx-relrow lawx-relrow--compact"
                  :class="`is-${group.type}`"
                  :href="relationHref(rel) ?? undefined"
                  :target="safeUrl(rel.url) ? '_blank' : undefined"
                  rel="noopener"
                >
                  <span class="mdi lawx-relrow__icon" :class="relationIcon(rel)" />
                  <span class="lawx-relrow__main">
                    <span class="lawx-relrow__title">{{ rel.target_title || rel.target_document_id || 'ไม่ระบุชื่อกฎหมาย' }}</span>
                    <span v-if="rel.change_detail || rel.note" class="lawx-relrow__note">— {{ rel.change_detail || rel.note }}</span>
                  </span>
                  <span v-if="rel.target_section" class="lawx-relrow__sec">{{ rel.target_section }}</span>
                </a>
              </div>
            </div>
          </div>
        </section>
        <LawInfoPanel :meta="meta" :article-count="displayArticleCount" :article-unit-label="unitWord" :show-count="!isExternal && displayArticleCount > 0" :versions="versionStore.versions" :viewed-document-id="props.documentId" :parent-names="parentNames" />
      </aside>
      </div>
      </template>

      </template>

      <ELawFooter />
    </v-main>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useDocumentStore } from '../../stores/documentStore';
import { useVersionStore } from '../../stores/versionStore';
import { buildSections, buildTocGroups, relationsForSection, documentRelations, sourceOf } from '../../composables/useLawSections';
import { useLookups } from '../../composables/useLookups';
import type { LawMeta, LawRelation, RelationType } from '../../types/document';
import {
  RELATION_TYPE_ICONS,
} from '../../types/lawRelation';
import { documentFileDownloadUrl, documentFileUrl, downloadPdfExport } from '../../api/client';
import DocBadge from '../shared/DocBadge.vue';
import { lawBadgeType, LAW_BADGE_COLORS } from '../../utils/lawTypeBadge';
import LawInfoPanel from './LawInfoPanel.vue';
import BlockFlow from '../shared/BlockFlow.vue';
import ELawFooter from '../shared/ELawFooter.vue';
import ELawNavbar from '../shared/ELawNavbar.vue';
import { formatThaiDate } from '../../utils/thaiDate';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();
const versionStore = useVersionStore();
const { documentTypes, load: loadLookups } = useLookups();
const exportingPdf = ref(false);
const pdfExportError = ref('');

// Fetch in the watcher (immediate) — same-route param changes (/law/A -> /law/B) reuse this
// component, so onMounted never re-fires; the watcher keeps content + versions in sync.
watch(() => props.documentId, (id) => {
  if (documentStore.documentId !== id || !documentStore.review) {
    void documentStore.fetch(id);
  }
  void versionStore.fetch(id);
  void loadLookups();
}, { immediate: true });

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
  parent_document_ids: [],
  access_scope: 'public',
  permission_group_ids: [],
};

const meta = computed<LawMeta>(() => documentStore.review?.law_meta ?? EMPTY_META);
const buddhistYear = computed(() => {
  const dateValue = meta.value.effective_date || meta.value.promulgation_date;
  if (!dateValue) return '';

  const year = new Date(dateValue).getFullYear();
  if (Number.isNaN(year)) return '';

  return year > 2400 ? year : year + 543;
});
const isPublished = computed(() => !!documentStore.review?.law_meta?.published_date);
const isOld = computed(() => documentStore.review?.law_meta?.document_type === 'old');
const isPdfSource = computed(() => documentStore.review?.source_type?.startsWith('pdf') ?? false);
const usesOriginalPdfLayout = computed(() => isOld.value || isPdfSource.value);
const fileUrl = computed(() => documentFileUrl(props.documentId));
const downloadUrl = computed(() => documentFileDownloadUrl(props.documentId));
const cleanSourceFile = computed(() => {
  const raw = documentStore.review?.source_file || '';
  return raw.replace(/\.[^.]+$/, '').replace(/_/g, ' ');
});
const safeFileName = computed(() => {
  const title = meta.value.title || documentStore.review?.source_file || props.documentId;
  return `${title.replace(/[/\\?%*:|"<>]/g, '_').substring(0, 100)}.pdf`;
});

const articleCount = computed(() =>
  sections.value.filter((s) => s.badge.startsWith('มาตรา') || s.badge.startsWith('ข้อ')).length,
);
const displayArticleCount = computed(() => articleCount.value || meta.value.section_count || 0);
const isExternal = computed(() => sourceOf(meta.value.law_type, documentTypes.value, meta.value.source) === 'external');
const unitWord = computed(() => (isExternal.value ? 'มาตรา' : 'ข้อ'));
const badgeType = computed(() => lawBadgeType(meta.value.law_type, isExternal.value));
const badgeColor = computed(() => LAW_BADGE_COLORS[badgeType.value]);
const badgeLabel = computed(() =>
  isExternal.value && meta.value.law_type
    ? `${meta.value.law_type} · กฎหมายภายนอก`
    : (meta.value.law_type || 'เอกสาร'),
);

function formatLawDate(value: string | null | undefined): string {
  return formatThaiDate(value) || value || '';
}

const relations = computed<LawRelation[]>(() => documentStore.review?.relations ?? []);
const parentLawRelation = computed(() =>
  relations.value.find((rel) => rel.type === 'issued_under' && rel.scope === 'document' && rel.target_document_id) ?? null,
);

const parentNames = computed<Array<{ id: string; title: string }>>(() => {
  const ids = meta.value.parent_document_ids ?? [];
  if (!ids.length) return [];
  return ids.map((id) => {
    const rel = relations.value.find((r) => r.target_document_id === id);
    return { id, title: rel?.target_title || id };
  });
});

const notCurrentVersion = computed(() =>
  versionStore.currentDocumentId !== '' && versionStore.currentDocumentId !== props.documentId,
);

const tocOpen = ref(true);
const infoOpen = ref(true);

function sectionRelations(sectionId: string): LawRelation[] {
  return relationsForSection(relations.value, sectionId);
}

const RELATION_GROUP_LABELS: Record<RelationType, string> = {
  repeals: 'กฎหมายที่ถูกยกเลิก',
  amends: 'กฎหมายที่แก้ไขเพิ่มเติม',
  supersedes: 'กฎหมายที่ถูกแทนที่',
  issued_under: 'ออกตามอำนาจของ',
  related: 'กฎหมายที่เกี่ยวข้อง',
};

const RELATION_GROUP_ORDER: RelationType[] = ['repeals', 'supersedes', 'amends', 'issued_under', 'related'];

function groupRelations(rels: LawRelation[]): Array<{ type: RelationType; label: string; items: LawRelation[] }> {
  return RELATION_GROUP_ORDER
    .map((type) => ({
      type,
      label: RELATION_GROUP_LABELS[type],
      items: rels.filter((rel) => rel.type === type),
    }))
    .filter((group) => group.items.length > 0);
}

function groupedSectionRelations(sectionId: string) {
  return groupRelations(sectionRelations(sectionId));
}

function relationIcon(rel: LawRelation): string {
  if (rel.type === 'amends') return 'mdi-pencil';
  return RELATION_TYPE_ICONS[rel.type] ?? 'mdi-link-variant';
}

const docRelations = computed<LawRelation[]>(() => documentRelations(relations.value));
const groupedDocRelations = computed(() => groupRelations(docRelations.value));
const sectionRelationSummaries = computed(() =>
  sections.value
    .map((section) => ({
      sectionId: section.id,
      badge: section.badge,
      groups: groupedSectionRelations(section.id),
    }))
    .filter((summary) => summary.groups.length > 0),
);

function badgeOf(sectionId: string): string {
  return sections.value.find((section) => section.id === sectionId)?.badge ?? '';
}

function safeUrl(url: string | null): string | null {
  if (!url) return null;
  const trimmed = url.trim();
  return /^https?:\/\//i.test(trimmed) ? trimmed : null;
}

function relationHref(rel: LawRelation): string | null {
  const external = safeUrl(rel.url);
  if (external) return external;
  return rel.target_document_id ? `/law/${encodeURIComponent(rel.target_document_id)}` : null;
}


async function downloadPdf(): Promise<void> {
  if (exportingPdf.value) return;

  exportingPdf.value = true;
  pdfExportError.value = '';
  try {
    if (usesOriginalPdfLayout.value) {
      const anchor = document.createElement('a');
      anchor.href = downloadUrl.value;
      anchor.download = safeFileName.value;
      document.body.appendChild(anchor);
      anchor.click();
      document.body.removeChild(anchor);
    } else {
      await downloadPdfExport(props.documentId, safeFileName.value);
      await documentStore.fetch(props.documentId, true);
    }
  } catch (error) {
    pdfExportError.value = error instanceof Error ? error.message : 'ดาวน์โหลดไม่สำเร็จ';
  } finally {
    exportingPdf.value = false;
  }
}

function printPage(): void {
  if (usesOriginalPdfLayout.value) {
    window.open(fileUrl.value, '_blank');
  } else {
    window.print();
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
  padding-top: 96px;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
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
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
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

.lawx-grid.is-original-pdf,
.lawx-grid.is-original-pdf.is-toc-hidden {
  grid-template-columns: minmax(0, 960px) 280px;
}

.lawx-grid.is-original-pdf.is-info-hidden,
.lawx-grid.is-original-pdf.is-toc-hidden.is-info-hidden {
  grid-template-columns: minmax(0, 960px);
}

.lawx-toc,
.lawx-info :deep(.law-info-panel) {
  background: rgb(var(--v-theme-detail-surface));
  border: 1px solid rgba(226, 232, 240, 0.9);
  box-shadow: 0 18px 42px rgba(148, 163, 184, 0.12);
}

.lawx-toc {
  border-radius: 18px;
  display: flex;
  flex-direction: column;
  padding: 14px;
  position: sticky;
  top: 84px;
  max-height: calc(100vh - 108px);
  overflow: hidden;
}

.lawx-toc__title { font-family: 'Sarabun', 'Noto Sans Thai', sans-serif; font-weight: 700; font-size: 16px; margin: 0 0 10px; display: flex; align-items: center; gap: 6px; color: #343028; }
.lawx-toc__scroll { min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding-right: 2px; }
.lawx-toc__items { display: flex; flex-direction: column; padding: 5px 0 4px 8px; }
.lawx-toc__item { text-align: left; background: transparent; border: none; border-left: 2px solid transparent; padding: 7px 10px; font-size: 13px; color: #475569; border-radius: 0; cursor: pointer; font-family: inherit; }
.lawx-toc__item:hover { color: #7b580d; }
.lawx-toc__item.is-active { background: transparent; border-left-color: #b68d40; color: #7b580d; font-weight: 700; }

.lawx-doc {
  min-width: 0;
}

.lawx-doc.is-original-pdf {
  margin: 0 auto;
  width: min(100%, 960px);
}

.lawx-doc.is-superseded {
  position: relative;
}
.lawx-doc.is-superseded::before {
  content: 'SUPERSEDED';
  position: absolute;
  top: 40%;
  left: 50%;
  transform: translate(-50%, -50%) rotate(-24deg);
  font-size: 5rem;
  font-weight: 800;
  letter-spacing: 0.2em;
  color: rgba(0, 0, 0, 0.06);
  pointer-events: none;
  z-index: 0;
  white-space: nowrap;
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

.lawx-headcard__badge { margin-bottom: 12px; }
.lawx-headcard__title { font-family: 'Sarabun', 'Noto Sans Thai', sans-serif; font-size: clamp(22px, 3vw, 30px); font-weight: 700; color: #1f1b14; margin: 0 0 14px; line-height: 1.3; }
.lawx-headcard__year { color: #92400e; font-family: 'Sarabun', 'Noto Sans Thai', sans-serif; font-size: 18px; font-weight: 700; margin: -4px 0 10px; }
.lawx-headcard__issuer { align-items: center; color: #4e4538; display: flex; font-family: 'Sarabun', 'Noto Sans Thai', sans-serif; font-size: 14px; gap: 6px; justify-content: center; margin: 0 0 12px; }
.lawx-headcard__issuer .mdi { color: #b68d40; }
.lawx-headcard__meta { display: flex; flex-wrap: wrap; justify-content: center; gap: 16px; font-family: 'Sarabun', 'Noto Sans Thai', sans-serif; font-size: 14px; color: #4e4538; }
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
.lawx-card__content :deep(.block-flow) {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif !important;
  font-size: 16px !important;
  line-height: 1.78;
}
.lawx-card__content :deep(.block-flow *) {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif !important;
  font-size: 16px !important;
  line-height: inherit !important;
}
.lawx-card__content :deep(table),
.lawx-card__content :deep(th),
.lawx-card__content :deep(td) {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif !important;
  font-size: 16px !important;
}

.lawx-download-card {
  padding: 16px 24px;
}

.lawx-download-card__title {
  font-weight: 600;
  font-size: 0.95rem;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.lawx-download-card__actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.lawx-download-card__subtitle {
  font-weight: 500;
  font-size: 0.85rem;
  margin-top: 16px;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
  color: rgba(var(--v-theme-on-surface), 0.7);
}

.lawx-download-card__relations {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.lawx-download-card__relrow {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 0;
}

.lawx-download-card__reltitle {
  font-size: 0.85rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;
  min-width: 0;
}

.lawx-parentcard {
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 16px;
}
.lawx-parentcard__head {
  font-weight: 700;
  font-size: 14px;
  color: #92400e;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.lawx-parentcard__head .mdi { color: #b45309; }

.lawx-parent-law-card {
  background: #eff6ff;
  border: 1px solid #bfdbfe;
}

.lawx-parent-law-card__link {
  color: #1e3a5f;
  cursor: pointer;
  text-decoration: none;
}

.lawx-parent-law-card__link:hover {
  text-decoration: underline;
}

.lawx-parentcard--sections {
  background: #f8fafc;
  border-color: #e2e8f0;
}

.lawx-parentcard--sections .lawx-parentcard__head {
  color: #1e3a5f;
}

.lawx-parentcard--sections .lawx-parentcard__head .mdi {
  color: #2563eb;
}

.lawx-section-rel-summary {
  border-top: 1px solid #e2e8f0;
  display: flex;
  gap: 10px;
  padding: 12px 0;
}

.lawx-section-rel-summary:first-of-type {
  border-top: 0;
  padding-top: 0;
}

.lawx-section-rel-summary__badge {
  align-self: flex-start;
  background: #ecfdf5;
  border: 1px solid #bbf7d0;
  border-radius: 10px;
  color: #047857;
  flex: 0 0 auto;
  font-size: 12px;
  font-weight: 700;
  max-width: 88px;
  overflow-wrap: anywhere;
  padding: 5px 8px;
  text-align: center;
}

.lawx-section-rel-summary__body {
  flex: 1 1 auto;
  min-width: 0;
}

.lawx-section-relcard {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 14px 18px;
  margin-top: 16px;
}

.lawx-section-relcard__head {
  font-weight: 700;
  font-size: 13px;
  color: #1e3a5f;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
}

.lawx-section-relcard__group {
  margin-top: 8px;
}

.lawx-section-relcard__group-label {
  font-size: 12px;
  font-weight: 700;
  margin-bottom: 4px;
}

.lawx-section-relcard__group-label.is-repeals { color: #dc2626; }
.lawx-section-relcard__group-label.is-amends { color: #7c3aed; }
.lawx-section-relcard__group-label.is-related { color: #2563eb; }
.lawx-section-relcard__group-label.is-supersedes { color: #ea580c; }
.lawx-section-relcard__group-label.is-issued_under { color: #7c3aed; }

.lawx-section-relcard__item {
  font-size: 13px;
  padding: 4px 0;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) max-content;
  align-items: center;
  gap: 6px;
  color: #334155;
  text-decoration: none;
}

.lawx-section-relcard__item:hover {
  color: #0f172a;
}

.lawx-section-relcard__icon {
  flex-shrink: 0;
}

.lawx-section-relcard__item.is-repeals .lawx-section-relcard__icon { color: #dc2626; }
.lawx-section-relcard__item.is-supersedes .lawx-section-relcard__icon { color: #ea580c; }
.lawx-section-relcard__item.is-amends .lawx-section-relcard__icon { color: #7c3aed; }
.lawx-section-relcard__item.is-issued_under .lawx-section-relcard__icon { color: #7c3aed; }
.lawx-section-relcard__item.is-related .lawx-section-relcard__icon { color: #2563eb; }

.lawx-section-relcard__main {
  align-items: baseline;
  display: flex;
  gap: 6px;
  min-width: 0;
}

.lawx-section-relcard__title {
  display: block;
  font-weight: 500;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.lawx-section-relcard__note {
  color: #94a3b8;
  flex: 0 1 auto;
  font-size: 12px;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.lawx-section-relcard__sec {
  color: #64748b;
  font-size: 12px;
  justify-self: end;
  min-width: 34px;
  text-align: right;
  white-space: nowrap;
}
.lawx-relgroup { margin-top: 8px; }
.lawx-relgroup__label {
  font-size: 12px;
  font-weight: 700;
  margin-bottom: 6px;
  color: #64748b;
}
.lawx-relgroup__label.is-repeals { color: #dc2626; }
.lawx-relgroup__label.is-supersedes { color: #ea580c; }
.lawx-relgroup__label.is-amends { color: #7c3aed; }
.lawx-relgroup__label.is-issued_under { color: #7c3aed; }

.lawx-relrow {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) max-content;
  align-items: center;
  gap: 8px;
  padding: 9px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
  margin-bottom: 6px;
  font-size: 13px;
  color: #334155;
  text-decoration: none;
}
.lawx-relrow:hover { border-color: #cbd5e1; background: #fcfcfd; }
.lawx-relrow--compact {
  grid-template-columns: auto minmax(0, 1fr);
  padding: 8px 10px;
}

.lawx-relrow--compact .lawx-relrow__main {
  flex-direction: column;
  gap: 2px;
}

.lawx-relrow--compact .lawx-relrow__note {
  white-space: normal;
}

.lawx-relrow--compact .lawx-relrow__sec {
  grid-column: 2;
  justify-self: start;
  text-align: left;
}
.lawx-relrow__icon { flex-shrink: 0; }
.lawx-relrow.is-repeals .lawx-relrow__icon { color: #dc2626; }
.lawx-relrow.is-supersedes .lawx-relrow__icon { color: #ea580c; }
.lawx-relrow.is-amends .lawx-relrow__icon { color: #7c3aed; }
.lawx-relrow.is-issued_under .lawx-relrow__icon { color: #7c3aed; }
.lawx-relrow.is-related .lawx-relrow__icon { color: #2563eb; }
.lawx-relrow__main {
  display: flex;
  align-items: baseline;
  gap: 6px;
  min-width: 0;
}

.lawx-relrow__title {
  display: block;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
}

.lawx-relrow__sec {
  color: #64748b;
  font-size: 12px;
  justify-self: end;
  min-width: 34px;
  text-align: right;
  white-space: nowrap;
}

.lawx-relrow__note {
  color: #94a3b8;
  flex: 0 1 auto;
  font-size: 12px;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.lawx-info {
  position: sticky;
  top: 84px;
}

@media (max-width: 1220px) {
  .lawx-grid,
  .lawx-grid.is-toc-hidden,
  .lawx-grid.is-info-hidden,
  .lawx-grid.is-toc-hidden.is-info-hidden,
  .lawx-grid.is-original-pdf,
  .lawx-grid.is-original-pdf.is-toc-hidden,
  .lawx-grid.is-original-pdf.is-info-hidden,
  .lawx-grid.is-original-pdf.is-toc-hidden.is-info-hidden {
    grid-template-columns: minmax(0, 1fr);
  }

  .lawx-toc,
  .lawx-info {
    position: static;
    max-height: none;
  }

  .lawx-toc {
    overflow: visible;
  }

  .lawx-toc__scroll {
    overflow: visible;
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

  .lawx-relrow {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .lawx-section-relcard__item {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .lawx-relrow__sec {
    grid-column: 2;
    justify-self: start;
    text-align: left;
  }

  .lawx-section-relcard__sec {
    grid-column: 2;
    justify-self: start;
    text-align: left;
  }
}

@media print {
  :deep(.v-app-bar), .lawx-subbar, .lawx-toc, .lawx-info { display: none !important; }
  .lawx-grid { grid-template-columns: 1fr; padding: 0; }
}
</style>
