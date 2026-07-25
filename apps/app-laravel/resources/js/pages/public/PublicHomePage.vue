<template>
  <div class="d-flex flex-column" style="min-height:100vh">
    <ELawNavbar @go-admin="router.push('/admin')" />
    <v-main>
      <ELawHeroSearch id="about" @search="onSearch" />

      <div id="knowledge" class="elaw-home-sections">
        <!-- Section: กฎหมายอัพเดทล่าสุด -->
        <section class="elaw-home-section">
          <div class="elaw-section-header">
            <div class="elaw-section-header__left">
              <div class="elaw-section-heading">
                <span class="elaw-section-heading__bar" />
                <h2 class="elaw-section-heading__text">กฎหมายอัพเดทล่าสุด</h2>
              </div>
              <p class="elaw-section-heading__sub">รวบรวมพระราชบัญญัติ กฎกระทรวง ระเบียบ ข้อบังคับ และประกาศต่าง ๆ ที่มีการเปลี่ยนแปลงล่าสุด</p>
            </div>
            <div class="elaw-section-header__right">
              <div class="elaw-filter-tabs">
                <button
                  v-for="tab in updateTabs"
                  :key="tab.label"
                  type="button"
                  class="elaw-filter-tab"
                  :class="{ 'elaw-filter-tab--active': activeUpdateTab === tab.label }"
                  @click="activeUpdateTab = tab.label"
                >{{ tab.label }}</button>
              </div>
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
                :department="doc.metadata.ownerAgencyId"
              />
            </v-col>
          </v-row>
        </section>

        <!-- Section: ระเบียบ -->
        <section class="elaw-home-section">
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
                :department="doc.metadata.ownerAgencyId"
              />
            </v-col>
          </v-row>
        </section>

        <!-- Section: ประกาศ -->
        <section class="elaw-home-section">
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
                :department="doc.metadata.ownerAgencyId"
              />
            </v-col>
          </v-row>
        </section>
      </div>

      <footer class="elaw-footer">
        <p>© 2567 ระบบฐานข้อมูลกฎหมาย — มหาวิทยาลัยบูรพา</p>
      </footer>
    </v-main>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchDocumentList, fetchReview } from '../../api/client';
import ELawHeroSearch from '../../components/shared/ELawHeroSearch.vue';
import ELawLawCard from '../../components/shared/ELawLawCard.vue';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import type { DocType } from '../../components/shared/lawBadge';
import type { DocumentListItem, ReviewDocument } from '../../types/document';
import type { DocumentType, DocumentVersion, PublicationScope } from '../../types/document-version';

function toDocType(t: DocumentType): DocType {
  return t === 'phrb' ? 'kotmai-krung' : t;
}

const activeUpdateTab = ref('ทั้งหมด');
const updateTabs = [
  { label: 'ล่าสุด' },
  { label: 'ยอดนิยม' },
  { label: 'ทั้งหมด' },
];

const router = useRouter();

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

const fallbackLatestDocs: DocumentVersion[] = [
  buildFallbackVersion({
    id: 'home-001',
    documentId: 'doc-home-001',
    versionNo: 12,
    documentType: 'phrb',
    publicationScope: 'public',
    title: 'พระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 และที่แก้ไขเพิ่มเติม',
    summary: 'กำหนดหลักเกณฑ์และมาตรการคุ้มครองข้อมูลส่วนบุคคล เพื่อคุมการเก็บ ใช้ และเปิดเผยข้อมูลให้สอดคล้องกับมาตรฐานภาครัฐ',
    documentGroupId: '1.6 โครงสร้างองค์กร',
    ownerAgencyId: 'รัฐบาลดิจิทัล',
    publishedDate: '2020-05-28',
  }),
  buildFallbackVersion({
    id: 'home-002',
    documentId: 'doc-home-002',
    versionNo: 3,
    documentType: 'kho-bangkhab',
    publicationScope: 'organization',
    title: 'ข้อบังคับมหาวิทยาลัยบูรพา ว่าด้วยการบริหารงานบุคคล พ.ศ. 2563 (แก้ไขเพิ่มเติม ฉบับที่ 3)',
    summary: 'แก้ไขหลักเกณฑ์เกี่ยวกับการประเมินผลการปฏิบัติงาน และการเลื่อนระดับตำแหน่งของบุคลากรให้สอดคล้องกับโครงสร้างใหม่',
    documentGroupId: '1.7 การบริหารงานบุคคล',
    ownerAgencyId: 'มหาวิทยาลัยบูรพา',
    publishedDate: '2021-01-31',
  }),
  buildFallbackVersion({
    id: 'home-003',
    documentId: 'doc-home-003',
    versionNo: 1,
    documentType: 'rabiap',
    publicationScope: 'public',
    title: 'ระเบียบมหาวิทยาลัยบูรพา ว่าด้วยการเบิกจ่ายค่าใช้จ่ายในการเดินทางไปราชการ (ฉบับใหม่) พ.ศ. 2567',
    summary: 'กำหนดอัตราและเงื่อนไขการเบิกจ่ายค่าใช้จ่ายในการเดินทางไปราชการและค่าใช้จ่ายสนับสนุนตามภารกิจ',
    documentGroupId: '1.8 การเงินและพัสดุ',
    ownerAgencyId: 'กองคลัง',
    publishedDate: '2024-05-20',
  }),
  buildFallbackVersion({
    id: 'home-004',
    documentId: 'doc-home-004',
    versionNo: 1,
    documentType: 'prakat',
    publicationScope: 'private',
    title: 'ประกาศมหาวิทยาลัยบูรพา เรื่อง หลักเกณฑ์การจัดซื้อจัดจ้างและการเบิกจ่าย',
    summary: 'ใช้เป็นแนวทางปฏิบัติสำหรับการจัดซื้อจัดจ้าง การบันทึกข้อมูล และการอ้างอิงเอกสารประกอบในระบบงาน',
    documentGroupId: '1.8 การเงินและพัสดุ',
    ownerAgencyId: 'กองพัสดุ',
    publishedDate: '2024-02-10',
  }),
];

const fallbackRabiapDocs: DocumentVersion[] = [
  buildFallbackVersion({
    id: 'home-r-001',
    documentId: 'doc-home-r-001',
    versionNo: 12,
    documentType: 'rabiap',
    publicationScope: 'public',
    title: 'ระเบียบว่าด้วยการลา พ.ศ. 2566',
    summary: 'แนวทางการลาและการอนุมัติสิทธิการลาของบุคลากรภายในหน่วยงาน',
    documentGroupId: '1.7 การบริหารงานบุคคล',
    ownerAgencyId: 'สำนักงานอธิการบดี',
    publishedDate: '2023-01-01',
  }),
  buildFallbackVersion({
    id: 'home-r-002',
    documentId: 'doc-home-r-002',
    versionNo: 4,
    documentType: 'rabiap',
    publicationScope: 'private',
    title: 'ระเบียบการเบิกค่าใช้จ่ายในการเดินทาง',
    summary: 'อัปเดตหลักเกณฑ์การเดินทางไปราชการและเอกสารประกอบการเบิกจ่าย',
    documentGroupId: '1.8 การเงินและพัสดุ',
    ownerAgencyId: 'กองคลัง',
    publishedDate: '2023-02-15',
  }),
  buildFallbackVersion({
    id: 'home-r-003',
    documentId: 'doc-home-r-003',
    versionNo: 7,
    documentType: 'rabiap',
    publicationScope: 'private',
    title: 'ระเบียบว่าด้วยเงินกองทุนสวัสดิการ',
    summary: 'กำหนดกรอบการบริหารกองทุนและสิทธิประโยชน์ที่เกี่ยวข้องกับสวัสดิการ',
    documentGroupId: '1.7 การบริหารงานบุคคล',
    ownerAgencyId: 'กองกลาง',
    publishedDate: '2023-04-10',
  }),
];

const fallbackPrakatDocs: DocumentVersion[] = [
  buildFallbackVersion({
    id: 'home-p-001',
    documentId: 'doc-home-p-001',
    versionNo: 1,
    documentType: 'prakat',
    publicationScope: 'public',
    title: 'ประกาศ เรื่อง หลักเกณฑ์การให้ทุนการศึกษา',
    summary: 'รายละเอียดหลักเกณฑ์ คุณสมบัติ และกำหนดการยื่นขอรับทุนการศึกษา',
    documentGroupId: '1.4 กิจการนิสิต',
    ownerAgencyId: 'กองกิจการนิสิต',
    publishedDate: '2024-02-10',
  }),
  buildFallbackVersion({
    id: 'home-p-002',
    documentId: 'doc-home-p-002',
    versionNo: 1,
    documentType: 'prakat',
    publicationScope: 'private',
    title: 'ประกาศ เรื่อง การรับสมัครบุคลากร',
    summary: 'ประกาศรับสมัครบุคลากรตามตำแหน่งและเงื่อนไขที่กำหนดของหน่วยงาน',
    documentGroupId: '1.7 การบริหารงานบุคคล',
    ownerAgencyId: 'สำนักวิชาการ',
    publishedDate: '2024-03-20',
  }),
  buildFallbackVersion({
    id: 'home-p-003',
    documentId: 'doc-home-p-003',
    versionNo: 2,
    documentType: 'prakat',
    publicationScope: 'private',
    title: 'ประกาศ อัตราค่าธรรมเนียมการศึกษา',
    summary: 'ปรับปรุงอัตราค่าธรรมเนียมการศึกษาและช่องทางการชำระเงินสำหรับปีการศึกษาใหม่',
    documentGroupId: '1.6 โครงสร้างองค์กร',
    ownerAgencyId: 'สำนักวิชาการ',
    publishedDate: '2024-04-01',
  }),
];

const latestDocs = ref<DocumentVersion[]>(fallbackLatestDocs.filter((doc) => doc.metadata.publicationScope === 'public'));
const rabiapDocs = ref<DocumentVersion[]>(fallbackRabiapDocs.filter((doc) => doc.metadata.publicationScope === 'public'));
const prakatDocs = ref<DocumentVersion[]>(fallbackPrakatDocs.filter((doc) => doc.metadata.publicationScope === 'public'));

onMounted(async () => {
  try {
    const { documents } = await fetchDocumentList();
    const recentDocs = documents.slice(0, 8);
    const hydrated = (await Promise.all(recentDocs.map(async (doc) => hydrateDoc(doc)))).filter(
      (doc): doc is DocumentVersion => doc !== null,
    );
    const publicDocs = hydrated.filter((doc) => doc.metadata.publicationScope === 'public');

    if (publicDocs.length > 0) {
      latestDocs.value = publicDocs.slice(0, 4);

      const rabiap = publicDocs.filter((doc) => doc.metadata.documentType === 'rabiap').slice(0, 3);
      const prakat = publicDocs.filter((doc) => doc.metadata.documentType === 'prakat').slice(0, 3);

      if (rabiap.length > 0) rabiapDocs.value = rabiap;
      if (prakat.length > 0) prakatDocs.value = prakat;
    }
  } catch {
    // Keep fallback content when the document API is unavailable.
  }
});

function hydrateDoc(doc: DocumentListItem): Promise<DocumentVersion | null> {
  return fetchReview(doc.document_id)
    .then((review) => mapReviewToDocumentVersion(doc, review))
    .catch(() => null);
}

function mapReviewToDocumentVersion(doc: DocumentListItem, review: ReviewDocument): DocumentVersion {
  const docType = inferDocType(review.law_meta?.law_type ?? doc.title);
  const publicationScope: PublicationScope = review.law_meta?.access_scope === 'private' ? 'private' : 'public';
  const group = review.law_meta?.law_group || 'กลุ่มกฎหมาย';
  const org = review.law_meta?.agency || 'หน่วยงานที่เกี่ยวข้อง';
  const publishedDate = toDate(review.law_meta?.promulgation_date)
    || toDate(review.law_meta?.effective_date)
    || toDate(doc.updated_at)
    || new Date();

  return buildFallbackVersion({
    id: doc.document_id,
    documentId: doc.document_id,
    versionNo: Math.max(review.relations?.length ?? 0, 1),
    documentType: docType,
    publicationScope,
    title: review.law_meta?.title || doc.title,
    summary: review.law_meta?.status
      ? `${review.law_meta.status} · ${group}`
      : `อัปเดตล่าสุดจาก ${org}`,
    documentGroupId: group,
    ownerAgencyId: org,
    publishedDate,
    status: publicationScope === 'public' ? 'published' : 'draft',
  });
}

function inferDocType(source: string): DocumentType {
  const value = source.replace(/\s+/g, '');
  if (value.includes('พระราชบัญญัติ') || value.includes('พ.ร.บ.')) return 'phrb';
  if (value.includes('ข้อบังคับ')) return 'kho-bangkhab';
  if (value.includes('ประกาศ')) return 'prakat';
  return 'rabiap';
}

function buildFallbackVersion(input: {
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
  background: rgb(var(--v-theme-primary));
  flex-shrink: 0;
}

.elaw-section-heading__bar--rabiap { background: #3b82f6; }
.elaw-section-heading__bar--prakat { background: #fb923c; }

.elaw-section-heading__text {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 32px;
  font-weight: 700;
  color: #1e293b;
  margin: 0;
}

.elaw-section-heading__sub {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px;
  color: #64748b;
  margin: 0;
  padding-left: 14px;
}

/* Filter tabs (ล่าสุด / ยอดนิยม / ทั้งหมด) */
.elaw-filter-tabs {
  display: flex;
  gap: 8px;
}

.elaw-filter-tab {
  padding: 4px 16px;
  border: 1px solid #d2c5b3;
  border-radius: 9999px;
  background: #ffffff;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px;
  font-weight: 700;
  color: #3c2900;
  cursor: pointer;
  transition: background-color 0.15s, border-color 0.15s, color 0.15s;
}

.elaw-filter-tab--active {
  background: #b68d40;
  border-color: #b68d40;
  color: #ffffff;
}

.elaw-section-link {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px;
  color: rgb(var(--v-theme-primary));
  cursor: pointer;
  text-decoration: none;
  white-space: nowrap;
}

.elaw-footer {
  background: #1a2e52;
  color: #ffffff;
  text-align: center;
  padding: 24px 16px;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px;
}

.elaw-footer p { margin: 0; }
</style>
