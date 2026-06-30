<template>
  <div class="lawx">
    <header class="lawx-topbar">
      <div class="lawx-topbar__inner">
        <div class="lawx-brand">
          <button
            type="button"
            class="lawx-menu-toggle"
            :aria-expanded="navOpen ? 'true' : 'false'"
            aria-label="สลับเมนูนำทาง"
            @click="navOpen = !navOpen"
          >
            <span class="mdi" :class="navOpen ? 'mdi-close' : 'mdi-menu'"></span>
          </button>
          <span class="lawx-brand__icon mdi mdi-scale-balance" />
          <span class="lawx-brand__name">e-Law</span>
        </div>
        <nav class="lawx-nav" :class="{ 'is-open': navOpen }">
          <a class="lawx-nav__link">หน้าแรก</a>
          <a class="lawx-nav__link">เกี่ยวกับระบบ</a>
          <a class="lawx-nav__link lawx-nav__link--active">ฐานข้อมูลกฎหมาย</a>
          <a class="lawx-nav__link">ความรู้</a>
        </nav>
        <button class="lawx-org-btn">
          <span class="mdi mdi-account-circle-outline" /> สำหรับบุคลากรองค์กร
        </button>
      </div>
    </header>

    <div class="lawx-subbar">
      <button class="lawx-back" @click="router.push(`/documents/${props.documentId}/rag`)">
        <span class="mdi mdi-arrow-left" /> ย้อนกลับ
      </button>
      <div class="lawx-actions">
        <button class="lawx-action" @click="tocOpen = !tocOpen">
          <span class="mdi" :class="tocOpen ? 'mdi-eye-off-outline' : 'mdi-table-of-contents'"></span>
          {{ tocOpen ? 'ซ่อนสารบัญ' : 'เปิดสารบัญ' }}
        </button>
        <button class="lawx-action" @click="infoOpen = !infoOpen">
          <span class="mdi" :class="infoOpen ? 'mdi-eye-off-outline' : 'mdi-card-text-outline'"></span>
          {{ infoOpen ? 'ซ่อนข้อมูล' : 'เปิดข้อมูล' }}
        </button>
        <button class="lawx-action" @click="printPage()">
          <span class="mdi mdi-printer-outline" /> พิมพ์
        </button>
        <button class="lawx-action lawx-action--pdf" @click="printPage()">
          <span class="mdi mdi-file-pdf-box" /> ดาวน์โหลด PDF
        </button>
      </div>
    </div>

    <div v-if="documentStore.loading" class="lawx-state">
      <v-progress-circular indeterminate />
      กำลังโหลด...
    </div>
    <div v-else-if="documentStore.error" class="lawx-state lawx-state--error">
      {{ documentStore.error }}
    </div>

    <div
      v-else-if="documentStore.review"
      class="lawx-grid"
      :class="{
        'is-toc-hidden': !tocOpen,
        'is-info-hidden': !infoOpen,
      }"
    >
      <v-card v-show="tocOpen" tag="aside" class="lawx-toc" elevation="0">
        <p class="lawx-toc__title"><span class="mdi mdi-format-list-bulleted" /> สารบัญมาตรา</p>
        <div v-for="group in tocGroups" :key="group.label" class="lawx-toc__group">
          <button class="lawx-toc__group-head" @click="toggleGroup(group.label)">
            <span>{{ group.label }}</span>
            <span class="mdi" :class="collapsed.has(group.label) ? 'mdi-chevron-down' : 'mdi-chevron-up'" />
          </button>
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
          <h1 class="lawx-headcard__title">{{ documentStore.review.source_file }}</h1>
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
              <BlockFlow :block="section.headBlock" :override-text="section.headBodyText" />
              <BlockFlow
                v-for="child in section.children"
                :key="child.block_id"
                :block="child"
              />
            </div>
          </div>
          <div v-if="sectionRelations(section.id).length" class="lawx-rel">
            <button class="lawx-rel__toggle" @click="toggleExpand(section.id)">
              <span class="mdi mdi-link-variant" />
              กฎหมายที่เกี่ยวข้อง · {{ sectionRelations(section.id).length }}
              <span class="mdi" :class="expanded.has(section.id) ? 'mdi-chevron-up' : 'mdi-chevron-down'" />
            </button>
            <ul v-show="expanded.has(section.id)" class="lawx-rel__list">
              <li v-for="rel in sectionRelations(section.id)" :key="rel.id" :class="{ 'is-repeal': rel.type === 'repeals' }">
                <span class="mdi" :class="rel.type === 'repeals' ? 'mdi-cancel' : 'mdi-link-variant'" />
                <a v-if="safeUrl(rel.url)" :href="safeUrl(rel.url) ?? ''" target="_blank" rel="noopener">{{ rel.target_title }}</a>
                <span v-else>{{ rel.target_title }}</span>
                <span v-if="rel.target_section" class="lawx-rel__sec">{{ rel.target_section }}</span>
                <span v-if="rel.note" class="lawx-rel__note">— {{ rel.note }}</span>
              </li>
            </ul>
          </div>
          <button class="lawx-copy" @click="copySection(section)">
            <span class="mdi mdi-content-copy" /> คัดลอก
          </button>
        </v-card>
      </main>

      <aside v-show="infoOpen" class="lawx-info">
        <LawInfoPanel :meta="meta" :article-count="articleCount" :relations="documentRelations(relations)" />
      </aside>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useDocumentStore } from '../../stores/documentStore';
import { buildSections, buildTocGroups, relationsForSection, documentRelations } from '../../composables/useLawSections';
import type { DocumentBlock, LawMeta, LawRelation } from '../../types/document';
import LawInfoPanel from './LawInfoPanel.vue';
import BlockFlow from '../shared/BlockFlow.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();

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
  agency: '',
  promulgation_date: '',
  effective_date: '',
  gazette_reference: '',
  royal_command: '',
  repealed_laws: [],
};

const meta = computed<LawMeta>(() => documentStore.review?.law_meta ?? EMPTY_META);

const articleCount = computed(() => sections.value.filter((section) => section.badge.startsWith('มาตรา')).length);

const relations = computed<LawRelation[]>(() => documentStore.review?.relations ?? []);
const navOpen = ref(false);
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

function copySection(section: { headBlock: DocumentBlock; children: DocumentBlock[] }): void {
  const text = [section.headBlock, ...section.children]
    .map((block) => block.approved_text || block.normalized_text || block.raw_text || '')
    .join('\n');

  navigator.clipboard?.writeText(text);
}

function printPage(): void {
  window.print();
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
  navOpen.value = false;
  tocOpen.value = true;
  infoOpen.value = true;
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<style scoped>
.lawx {
  min-height: 100vh;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  color: #1e293b;
}

.lawx-topbar {
  background: rgba(255, 252, 245, 0.92);
  border-bottom: 1px solid rgba(148, 163, 184, 0.2);
  backdrop-filter: blur(18px);
  position: sticky;
  top: 0;
  z-index: 50;
}

.lawx-topbar__inner {
  max-width: 1360px;
  margin: 0 auto;
  padding: 0 24px;
  min-height: 68px;
  display: flex;
  align-items: center;
  gap: 28px;
}

.lawx-brand {
  display: flex;
  align-items: center;
  gap: 10px;
}

.lawx-menu-toggle {
  display: none;
  width: 40px;
  height: 40px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.86);
  color: #1e2a4a;
  cursor: pointer;
  font: inherit;
}

.lawx-brand__icon { font-size: 24px; color: #b45309; }
.lawx-brand__name { font-weight: 800; font-size: 19px; color: #1e2a4a; letter-spacing: 0.02em; }

.lawx-nav {
  display: flex;
  gap: 8px;
  flex: 1;
  align-items: center;
}

.lawx-nav__link {
  font-size: 14px;
  color: #475569;
  padding: 8px 12px;
  border-radius: 999px;
  cursor: pointer;
  transition: background 0.18s ease, color 0.18s ease;
}

.lawx-nav__link:hover {
  background: rgba(30, 42, 74, 0.06);
  color: #1e2a4a;
}

.lawx-nav__link--active {
  color: #1e2a4a;
  font-weight: 700;
  background: rgba(180, 83, 9, 0.12);
}

.lawx-org-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  background: linear-gradient(135deg, #1e2a4a 0%, #28416f 100%);
  color: #fff;
  border: none;
  border-radius: 999px;
  padding: 10px 18px;
  font-size: 13px;
  cursor: pointer;
  font-family: inherit;
  box-shadow: 0 12px 28px rgba(30, 42, 74, 0.18);
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

.lawx-back,
.lawx-action {
  background: rgba(255, 252, 245, 0.92);
  border: 1px solid rgba(148, 163, 184, 0.24);
  border-radius: 14px;
  padding: 9px 14px;
  font-size: 13px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
  font-family: inherit;
  color: #334155;
  box-shadow: 0 8px 24px rgba(148, 163, 184, 0.12);
}

.lawx-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
.lawx-action--pdf { color: #b91c1c; border-color: rgba(248, 113, 113, 0.3); }

.lawx-state { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 80px; color: #64748b; }
.lawx-state--error { color: #b91c1c; }

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
  background: #fff;
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

.lawx-toc__title { font-weight: 700; font-size: 14px; margin: 0 0 10px; display: flex; align-items: center; gap: 6px; color: #1e2a4a; }
.lawx-toc__group-head { width: 100%; display: flex; justify-content: space-between; align-items: center; background: transparent; border: none; border-radius: 10px; padding: 9px 10px; font-size: 13px; font-weight: 700; color: #1d4ed8; cursor: pointer; margin-top: 6px; font-family: inherit; }
.lawx-toc__items { display: flex; flex-direction: column; padding: 5px 0 4px 8px; }
.lawx-toc__item { text-align: left; background: transparent; border: none; border-left: 2px solid transparent; padding: 7px 10px; font-size: 13px; color: #475569; border-radius: 0; cursor: pointer; font-family: inherit; }
.lawx-toc__item:hover { color: #1d4ed8; }
.lawx-toc__item.is-active { background: transparent; border-left-color: #1d4ed8; color: #1d4ed8; font-weight: 700; }

.lawx-doc {
  min-width: 0;
}

.lawx-headcard {
  background: #fff;
  border: 1px solid rgba(226, 232, 240, 0.9);
  border-top: 5px solid #1e2a4a;
  border-radius: 22px;
  padding: 32px 28px;
  text-align: center;
  margin-bottom: 18px;
  box-shadow: 0 22px 46px rgba(148, 163, 184, 0.12);
}

.lawx-headcard__badge { display: inline-block; background: #eff6ff; color: #1d4ed8; font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 999px; margin-bottom: 12px; }
.lawx-headcard__title { font-size: clamp(24px, 3vw, 32px); font-weight: 800; color: #1e2a4a; margin: 0 0 14px; line-height: 1.25; }
.lawx-headcard__meta { display: flex; flex-wrap: wrap; justify-content: center; gap: 16px; font-size: 13px; color: #64748b; }
.lawx-headcard__meta .mdi { color: #94a3b8; }

.lawx-card {
  background: #fff;
  border: 1px solid rgba(226, 232, 240, 0.82);
  border-radius: 22px;
  padding: 20px 22px 10px;
  margin-bottom: 16px;
  box-shadow: 0 18px 38px rgba(148, 163, 184, 0.1);
}

.lawx-card__body { display: flex; gap: 16px; align-items: flex-start; }
.lawx-card__badge { flex-shrink: 0; max-width: 160px; background: #ecfdf5; color: #047857; font-size: 13px; font-weight: 700; padding: 5px 12px; border-radius: 10px; height: fit-content; white-space: normal; overflow-wrap: break-word; line-height: 1.3; }
.lawx-card__badge--chapter { background: #eef2ff; color: #4338ca; }
.lawx-card__content { flex: 1; min-width: 0; }

.lawx-copy { display: block; margin-left: auto; background: none; border: none; color: #94a3b8; font-size: 12px; cursor: pointer; padding: 6px; font-family: inherit; }
.lawx-copy:hover { color: #475569; }

.lawx-rel { margin-top: 12px; border-top: 1px dashed #d7dee7; padding-top: 10px; }
.lawx-rel__toggle { display: inline-flex; align-items: center; gap: 6px; background: #eff6ff; color: #1d4ed8; border: none; border-radius: 999px; padding: 6px 12px; font: inherit; font-size: 12px; cursor: pointer; }
.lawx-rel__list { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.lawx-rel__list li { display: flex; align-items: center; gap: 6px; font-size: 13px; color: #334155; }
.lawx-rel__list li.is-repeal { color: #dc2626; }
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
  .lawx-topbar__inner {
    min-height: 72px;
    flex-wrap: wrap;
    padding: 12px 20px;
    gap: 14px;
  }

  .lawx-menu-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .lawx-brand {
    width: 100%;
    justify-content: space-between;
  }

  .lawx-nav {
    display: none;
    width: 100%;
    flex-direction: column;
    align-items: stretch;
    gap: 6px;
    padding: 6px 0 2px;
  }

  .lawx-nav.is-open {
    display: flex;
  }

  .lawx-nav__link {
    background: rgba(255, 255, 255, 0.7);
  }

  .lawx-org-btn {
    width: 100%;
    justify-content: center;
  }

  .lawx-subbar {
    padding: 16px 20px 0;
    flex-direction: column;
    align-items: stretch;
  }

  .lawx-back,
  .lawx-action {
    justify-content: center;
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
  .lawx-topbar, .lawx-subbar, .lawx-toc, .lawx-info, .lawx-copy { display: none !important; }
  .lawx-grid { grid-template-columns: 1fr; padding: 0; }
}
</style>
