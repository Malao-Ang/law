<template>
  <div class="d-flex flex-column" style="min-height:100vh">
    <ELawNavbar @go-admin="router.push('/admin')" />
    <v-main>
      <ELawHeroSearch id="about" @search="onSearch" />

      <div id="knowledge" class="elaw-home-sections">
        <!-- Section: กฎหมายอัพเดทล่าสุด -->
        <section v-if="latestDocs.length" class="elaw-home-section">
          <div class="elaw-section-header">
            <div class="elaw-section-header__left">
              <div class="elaw-section-heading">
                <span class="elaw-section-heading__bar" />
                <h2 class="elaw-section-heading__text">กฎหมายอัพเดทล่าสุด</h2>
              </div>
              <p class="elaw-section-heading__sub">รวบรวมกฎหมายภายนอก ระเบียบ ข้อบังคับ และประกาศต่าง ๆ ที่มีการเปลี่ยนแปลงล่าสุด</p>
            </div>
            <div class="elaw-section-header__right">
              <a class="elaw-section-link" @click.prevent="goToDatabase()">ดูทั้งหมด →</a>
            </div>
          </div>
          <v-row class="mt-2">
            <v-col
              v-for="doc in latestDocs.slice(0, 3)"
              :key="doc._id"
              cols="12"
              md="4"
            >
              <ELawLawCard
                :title="doc.metadata.title"
                :doc-type="toDocType(doc.metadata.documentType)"
                :description="doc.metadata.summary"
                :change-status-text="doc.metadata.changeStatus"
                :use-status="doc.metadata.useStatus"
                :department="doc.metadata.ownerAgencyId"
                :law-group="doc.metadata.documentGroupId"
                :date="formatThaiDate(doc.metadata.publishedDate)"
                :visibility="doc.metadata.publicationScope"
                @click="openLaw(doc)"
              />
            </v-col>
          </v-row>
        </section>

        <!-- Section: ระเบียบ -->
        <section v-if="rabiapDocs.length" class="elaw-home-section">
          <div class="elaw-section-header">
            <div class="elaw-section-header__left">
              <div class="elaw-section-heading">
                <span class="elaw-section-heading__bar elaw-section-heading__bar--rabiap" />
                <h2 class="elaw-section-heading__text">ระเบียบ</h2>
              </div>
            </div>
            <a class="elaw-section-link" @click.prevent="goToDatabase('rabiap')">ดูทั้งหมด →</a>
          </div>
          <v-row class="mt-2">
            <v-col
              v-for="doc in rabiapDocs.slice(0, 3)"
              :key="doc._id"
              cols="12"
              md="4"
            >
              <ELawLawCard
                :title="doc.metadata.title"
                :doc-type="toDocType(doc.metadata.documentType)"
                :description="doc.metadata.summary"
                :change-status-text="doc.metadata.changeStatus"
                :use-status="doc.metadata.useStatus"
                :department="doc.metadata.ownerAgencyId"
                :law-group="doc.metadata.documentGroupId"
                :date="formatThaiDate(doc.metadata.publishedDate)"
                :visibility="doc.metadata.publicationScope"
                @click="openLaw(doc)"
              />
            </v-col>
          </v-row>
        </section>

        <!-- Section: ข้อบังคับ -->
        <section v-if="khoBangkhabDocs.length" class="elaw-home-section">
          <div class="elaw-section-header">
            <div class="elaw-section-header__left">
              <div class="elaw-section-heading">
                <span class="elaw-section-heading__bar elaw-section-heading__bar--kho-bangkhab" />
                <h2 class="elaw-section-heading__text">ข้อบังคับ</h2>
              </div>
            </div>
            <a class="elaw-section-link" @click.prevent="goToDatabase('kho-bangkhab')">ดูทั้งหมด →</a>
          </div>
          <v-row class="mt-2">
            <v-col
              v-for="doc in khoBangkhabDocs.slice(0, 3)"
              :key="doc._id"
              cols="12"
              md="4"
            >
              <ELawLawCard
                :title="doc.metadata.title"
                :doc-type="toDocType(doc.metadata.documentType)"
                :description="doc.metadata.summary"
                :change-status-text="doc.metadata.changeStatus"
                :use-status="doc.metadata.useStatus"
                :department="doc.metadata.ownerAgencyId"
                :law-group="doc.metadata.documentGroupId"
                :date="formatThaiDate(doc.metadata.publishedDate)"
                :visibility="doc.metadata.publicationScope"
                @click="openLaw(doc)"
              />
            </v-col>
          </v-row>
        </section>

        <!-- Section: ประกาศ -->
        <section v-if="prakatDocs.length" class="elaw-home-section">
          <div class="elaw-section-header">
            <div class="elaw-section-header__left">
              <div class="elaw-section-heading">
                <span class="elaw-section-heading__bar elaw-section-heading__bar--prakat" />
                <h2 class="elaw-section-heading__text">ประกาศ</h2>
              </div>
            </div>
            <a class="elaw-section-link" @click.prevent="goToDatabase('prakat')">ดูทั้งหมด →</a>
          </div>
          <v-row class="mt-2">
            <v-col
              v-for="doc in prakatDocs.slice(0, 3)"
              :key="doc._id"
              cols="12"
              md="4"
            >
              <ELawLawCard
                :title="doc.metadata.title"
                :doc-type="toDocType(doc.metadata.documentType)"
                :description="doc.metadata.summary"
                :change-status-text="doc.metadata.changeStatus"
                :use-status="doc.metadata.useStatus"
                :issuer="doc.metadata.issuer"
                :department="doc.metadata.ownerAgencyId"
                :law-group="doc.metadata.documentGroupId"
                :date="formatThaiDate(doc.metadata.publishedDate)"
                :visibility="doc.metadata.publicationScope"
                @click="openLaw(doc)"
              />
            </v-col>
          </v-row>
        </section>
      </div>

      <ELawFooter />
    </v-main>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { searchLaws } from '../../api/client';
import ELawFooter from '../../components/shared/ELawFooter.vue';
import ELawHeroSearch from '../../components/shared/ELawHeroSearch.vue';
import ELawLawCard from '../../components/shared/ELawLawCard.vue';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import type { DocType } from '../../components/shared/lawBadge';
import type { DocumentType, DocumentVersion, PublicationScope } from '../../types/document-version';
import type { LawSearchResult } from '../../types/lawSearch';
import { formatThaiDate } from '../../utils/thaiDate';
import { useAuthStore } from '../../stores/authStore';
import { canDisplayLawResult } from '../../utils/lawAccess';

function toDocType(t: DocumentType): DocType {
  return t;
}

const router = useRouter();
const auth = useAuthStore();

function onSearch(query: string, types: string[], groups: string[]): void {
  router.push({
    path: '/database',
    query: {
      q: query || undefined,
      type: types.length > 0 ? types : undefined,
      group: groups.length > 0 ? groups : undefined,
    },
  });
}

function goToDatabase(type?: string): void {
  router.push({
    path: '/database',
    query: {
      type: type ? [type] : undefined,
      sort: 'newest',
    },
  });
}

function openLaw(doc: DocumentVersion): void {
  const lawPath = `/law/${encodeURIComponent(doc.documentId)}`;
  if (doc.metadata.publicationScope === 'private' && !auth.isAuthenticated) {
    router.push({ path: '/login', query: { redirect: lawPath } });
    return;
  }

  router.push(lawPath);
}

const latestDocs = ref<DocumentVersion[]>([]);
const rabiapDocs = ref<DocumentVersion[]>([]);
const khoBangkhabDocs = ref<DocumentVersion[]>([]);
const prakatDocs = ref<DocumentVersion[]>([]);

onMounted(async () => {
  try {
    const response = await searchLaws({ q: '', filters: {}, page: 1, per_page: 24 });
    const databaseDocs = response.results
      .filter((law) => canDisplayLawResult(law, auth.isAuthenticated))
      .map(mapSearchResultToDocumentVersion);

    latestDocs.value = databaseDocs.slice(0, 4);
    rabiapDocs.value = docsByType(databaseDocs, 'rabiap');
    khoBangkhabDocs.value = docsByType(databaseDocs, 'kho-bangkhab');
    prakatDocs.value = docsByType(databaseDocs, 'prakat');
  } catch {
    latestDocs.value = [];
    rabiapDocs.value = [];
    khoBangkhabDocs.value = [];
    prakatDocs.value = [];
  }
});

function docsByType(docs: DocumentVersion[], type: DocumentType): DocumentVersion[] {
  return docs.filter((doc) => doc.metadata.documentType === type).slice(0, 3);
}

function mapSearchResultToDocumentVersion(law: LawSearchResult): DocumentVersion {
  const docType = docTypeFromSource(law);
  const publicationScope: PublicationScope = law.restricted ? 'private' : 'public';
  const group = law.law_group || 'กลุ่มกฎหมาย';
  const org = law.agency || 'หน่วยงานที่เกี่ยวข้อง';
  const publishedDate = toDate(law.published_date) || new Date();

  return buildDocumentVersion({
    id: law.law_id,
    documentId: law.law_id,
    versionNo: 1,
    documentType: docType,
    publicationScope,
    title: law.title || 'ไม่ระบุชื่อกฎหมาย',
    summary: law.summary || `อัปเดตล่าสุดจาก ${org}`,
    documentGroupId: group,
    ownerAgencyId: org,
    publishedDate,
    status: 'published',
    issuer: law.issuer ?? '',
    changeStatus: law.change_status ?? '',
    useStatus: law.status ?? '',
  });
}

function docTypeFromSource(law: LawSearchResult): DocumentType {
  if (law.source === 'external') return 'kotmai-phaainok';
  if (law.source === 'internal') return internalDocType(law.law_type ?? law.title ?? '');
  // source missing (older result) — fall back to the legacy heuristic
  return inferDocType(law.law_type ?? law.title ?? '');
}

function internalDocType(source: string): DocumentType {
  const value = source.replace(/\s+/g, '');
  if (value.includes('ข้อบังคับ')) return 'kho-bangkhab';
  if (value.includes('ประกาศ')) return 'prakat';
  return 'rabiap';
}

function inferDocType(source: string): DocumentType {
  const value = source.replace(/\s+/g, '');
  if (value.includes('พระราชบัญญัติ') || value.includes('พ.ร.บ.') || value.includes('กฎหมายภายนอก')) return 'kotmai-phaainok';
  if (value.includes('ข้อบังคับ')) return 'kho-bangkhab';
  if (value.includes('ประกาศ')) return 'prakat';
  return 'rabiap';
}

function buildDocumentVersion(input: {
  id: string;
  documentId: string;
  versionNo: number;
  documentType: DocumentType;
  publicationScope: PublicationScope;
  title: string;
  summary: string;
  documentGroupId: string;
  ownerAgencyId: string;
  publishedDate: string | Date;
  status?: DocumentVersion['status'];
  issuer?: string;
  changeStatus?: string;
  useStatus?: string;
}): DocumentVersion {
  const publishedDate = typeof input.publishedDate === 'string' ? new Date(input.publishedDate) : input.publishedDate;

  return {
    _id: input.id,
    documentId: input.documentId,
    versionNo: input.versionNo,
    status: input.status ?? 'published',
    isCurrent: input.status !== 'draft',
    metadata: {
      title: input.title,
      documentType: input.documentType,
      documentGroupId: input.documentGroupId,
      publicationScope: input.publicationScope,
      summary: input.summary,
      publishedDate,
      ownerAgencyId: input.ownerAgencyId,
      issuer: input.issuer ?? '',
      changeStatus: input.changeStatus ?? '',
      useStatus: input.useStatus ?? '',
      keywords: [],
    },
    createdAt: publishedDate,
    updatedAt: publishedDate,
    publishedAt: publishedDate,
  };
}

function toDate(value?: string | null): Date | undefined {
  if (!value) return undefined;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? undefined : date;
}
</script>

<style scoped>
.elaw-home-sections {
  max-width: 1215px;
  margin: 0 auto;
  padding: 50px 24px 60px;
  display: flex;
  flex-direction: column;
  gap: 56px;
}

.elaw-home-section {
  width: 100%;
}

/* Section header: heading + tabs row */
.elaw-section-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 24px;
}

.elaw-section-header__left {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.elaw-section-header__right {
  display: flex;
  align-items: center;
  gap: 24px;
  flex-wrap: wrap;
}

/* Heading with 4px accent bar (from Figma Heading 2) */
.elaw-section-heading {
  display: flex;
  align-items: center;
  gap: 10px;
}

.elaw-section-heading__bar {
  display: inline-block;
  width: 4px;
  height: 40px;
  border-radius: 2px;
  background: #b68d40;
  flex-shrink: 0;
}

.elaw-section-heading__bar--external { background: #854d0e; }
.elaw-section-heading__bar--rabiap { background: #3b82f6; }
.elaw-section-heading__bar--kho-bangkhab { background: #10b981; }
.elaw-section-heading__bar--prakat { background: #fb923c; }

.elaw-section-heading__text {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 32px;
  font-weight: 700;
  color: #1e293b;
  margin: 0;
}

.elaw-section-heading__sub {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 14px;
  color: #64748b;
  margin: 0;
  padding-left: 14px;
}

.elaw-section-link {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 14px;
  font-weight: 600;
  color: #b68d40;
  cursor: pointer;
  text-decoration: none;
  white-space: nowrap;
}
</style>
