<template>
  <div class="d-flex flex-column" style="min-height:100vh">
    <ELawNavbar @go-admin="router.push('/admin')" />
    <v-main>
      <ELawHeroSearch id="about" @search="onSearch" />

      <v-container id="knowledge" class="d-flex flex-column ga-14 elaw-home-sections">
        <section>
          <div class="d-flex justify-space-between align-center mb-4">
            <h2 class="text-h6 font-weight-bold">กฎหมายล่าสุด</h2>
            <v-btn variant="text" size="small" color="secondary" append-icon="mdi-arrow-right" @click="goToDatabase()">
              ดูเพิ่มเติม
            </v-btn>
          </div>
          <v-row>
            <v-col
              v-for="doc in latestDocs.slice(0, 3)"
              :key="doc._id"
              cols="12"
              md="4"
            >
              <DocumentVersionCard :version="doc" variant="rectangle" />
            </v-col>
          </v-row>
        </section>

        <section>
          <div class="d-flex justify-space-between align-center mb-4">
            <h2 class="text-h6 font-weight-bold">ระเบียบ</h2>
            <v-btn variant="text" size="small" color="secondary" append-icon="mdi-arrow-right" @click="goToDatabase('rabiap')">
              ดูเพิ่มเติม
            </v-btn>
          </div>
          <v-row>
            <v-col
              v-for="doc in rabiapDocs.slice(0, 3)"
              :key="doc._id"
              cols="12"
              md="4"
            >
              <DocumentVersionCard :version="doc" variant="rectangle" />
            </v-col>
          </v-row>
        </section>

        <section>
          <div class="d-flex justify-space-between align-center mb-4">
            <h2 class="text-h6 font-weight-bold">ประกาศ</h2>
            <v-btn variant="text" size="small" color="secondary" append-icon="mdi-arrow-right" @click="goToDatabase('prakat')">
              ดูเพิ่มเติม
            </v-btn>
          </div>
          <v-row>
            <v-col
              v-for="doc in prakatDocs.slice(0, 3)"
              :key="doc._id"
              cols="12"
              md="4"
            >
              <DocumentVersionCard :version="doc" variant="rectangle" />
            </v-col>
          </v-row>
        </section>
      </v-container>

      <v-footer color="elaw-navy" class="justify-center">
        <p>© 2567 ระบบฐานข้อมูลกฎหมาย — มหาวิทยาลัยบูรพา</p>
      </v-footer>
    </v-main>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchDocumentList, fetchReview } from '../../api/client';
import DocumentVersionCard from '../../components/shared/DocumentVersionCard.vue';
import ELawHeroSearch from '../../components/shared/ELawHeroSearch.vue';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import type { DocumentListItem, ReviewDocument } from '../../types/document';
import type { DocumentType, DocumentVersion, PublicationScope } from '../../types/document-version';

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

const latestDocs = ref<DocumentVersion[]>(fallbackLatestDocs);
const rabiapDocs = ref<DocumentVersion[]>(fallbackRabiapDocs);
const prakatDocs = ref<DocumentVersion[]>(fallbackPrakatDocs);

onMounted(async () => {
  try {
    const { documents } = await fetchDocumentList();
    const recentDocs = documents.slice(0, 8);
    const hydrated = (await Promise.all(recentDocs.map(async (doc) => hydrateDoc(doc)))).filter(
      (doc): doc is DocumentVersion => doc !== null,
    );

    if (hydrated.length > 0) {
      latestDocs.value = hydrated.slice(0, 4);

      const rabiap = hydrated.filter((doc) => doc.metadata.documentType === 'rabiap').slice(0, 3);
      const prakat = hydrated.filter((doc) => doc.metadata.documentType === 'prakat').slice(0, 3);

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
  const publicationScope: PublicationScope = doc.status === 'done' || doc.status === 'exported' || doc.status === 'ingested'
    ? 'public'
    : 'private';
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
    title: review.source_file || doc.title,
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
  padding-top: 28px;
  padding-bottom: 44px;
}
</style>
