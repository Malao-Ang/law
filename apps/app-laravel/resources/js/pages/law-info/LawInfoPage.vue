<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'ข้อมูลเอกสาร']"
    title="นำเข้าเอกสารกฎหมาย"
    subtitle="ขั้นตอนที่ 4 จาก 6: ข้อมูลเอกสาร"
  >
    <WorkflowFooterBar
      :step="4"
      next-label="ถัดไป"
      :next-loading="documentStore.saving"
      @back="router.push(`/documents/${props.documentId}/rag`)"
      @next="saveAndNext"
    />
    <div class="mx-auto" style="max-width:860px; padding-bottom:60px">
      <WorkflowStepper :step="4" />

      <div v-if="documentStore.loading" class="d-flex flex-column align-center justify-center pa-12 ga-3 text-medium-emphasis">
        <v-progress-circular indeterminate color="admin-primary" />
        <span>กำลังโหลด...</span>
      </div>

      <template v-else>
        <v-alert v-if="documentStore.saveError" type="error" variant="tonal" density="compact" closable class="mb-4"
          @click:close="documentStore.setSaveError()">
          {{ documentStore.saveError }}
        </v-alert>

        <!-- ข้อมูลพื้นฐาน -->
        <v-card flat border rounded="lg" class="pa-6 mb-4">
          <div class="d-flex align-center ga-2 mb-5">
            <v-icon icon="mdi-file-document-outline" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">ข้อมูลพื้นฐาน</span>
          </div>
          <v-row dense>
            <v-col cols="12">
              <v-text-field
                v-model="form.title"
                label="ชื่อเอกสาร"
                variant="outlined"
                :rules="[v => !!v || 'จำเป็นต้องกรอก']"
                required
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-select
                v-model="form.law_type"
                :items="DOC_TYPES"
                label="ประเภทเอกสาร"
                placeholder="- เลือกประเภทเอกสาร -"
                variant="outlined"
                :rules="[v => !!v || 'จำเป็นต้องเลือก']"
                required
              />
            </v-col>
            <v-col cols="12">
              <v-autocomplete
                v-model="form.law_groups"
                :items="LAW_GROUP_OPTIONS"
                item-title="title"
                item-value="value"
                label="กลุ่มกฎหมาย"
                placeholder="- เลือกกลุ่มกฎหมาย -"
                multiple
                chips
                closable-chips
                variant="outlined"
                clearable
                :custom-filter="searchSelectableOption"
                :rules="[v => v.length > 0 || 'จำเป็นต้องเลือกอย่างน้อย 1 กลุ่ม']"
              >
                <template #item="{ props: itemProps, item }">
                  <v-list-item v-bind="itemProps" :subtitle="item.raw.subtitle" />
                </template>
                <template #append-inner>
                  <v-chip v-if="form.law_groups.length" size="x-small" color="admin-primary" class="mr-1">
                    {{ form.law_groups.length }} กลุ่ม
                  </v-chip>
                </template>
              </v-autocomplete>
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                :model-value="sectionCountDisplay"
                label="จำนวนข้อ/มาตรา"
                variant="outlined"
                readonly
              />
            </v-col>
            <v-col cols="12">
              <v-combobox
                v-model="form.keywords"
                label="คำสำคัญ"
                placeholder="พิมพ์คำสำคัญแล้วกด Enter"
                variant="outlined"
                multiple
                chips
                closable-chips
                clearable
                hide-selected
                hint="เพิ่มได้สูงสุด 30 คำ"
                persistent-hint
              />
            </v-col>
          </v-row>
        </v-card>

        <!-- ข้อมูลการประกาศใช้ -->
        <v-card flat border rounded="lg" class="pa-6 mb-4">
          <div class="d-flex align-center ga-2 mb-5">
            <v-icon icon="mdi-calendar-check-outline" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">ข้อมูลการประกาศใช้</span>
          </div>
          <v-row dense>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="form.promulgation_date"
                label="วันที่ประกาศ"
                type="date"
                variant="outlined"
                prepend-inner-icon="mdi-calendar"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="form.effective_date"
                label="วันที่มีผลบังคับใช้ *"
                type="date"
                variant="outlined"
                prepend-inner-icon="mdi-calendar"
                :rules="[v => !!v || 'จำเป็นต้องระบุ']"
                required
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="form.expiry_date"
                label="วันที่สิ้นสุดการใช้"
                type="date"
                variant="outlined"
                prepend-inner-icon="mdi-calendar"
                :disabled="noExpiry"
                :placeholder="noExpiry ? 'ไม่มีวันสิ้นสุด' : ''"
              />
              <v-checkbox
                v-model="noExpiry"
                label="ไม่มีวันสิ้นสุด"
                density="compact"
                hide-details
                class="mt-1"
                @update:model-value="v => { if (v) form.expiry_date = null }"
              />
            </v-col>
          </v-row>
        </v-card>

        <!-- ข้อมูลหน่วยงาน -->
        <v-card flat border rounded="lg" class="pa-6 mb-4">
          <div class="d-flex align-center ga-2 mb-5">
            <v-icon icon="mdi-office-building-outline" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">ข้อมูลหน่วยงาน</span>
          </div>
          <v-row dense>
            <v-col cols="12">
              <div class="d-flex align-center ga-2 mb-2">
                <span class="text-body-2 font-weight-medium">หน่วยงานรับผิดชอบ</span>
                <span class="text-caption text-error">*</span>
                <v-chip v-if="form.agencies.length === 0" size="x-small" color="error" class="ml-1">จำเป็น</v-chip>
              </div>
              <v-autocomplete
                v-model="form.agencies"
                :items="AGENCY_OPTIONS"
                item-title="title"
                item-value="value"
                label="หน่วยงานรับผิดชอบ"
                placeholder="- เลือกหน่วยงานรับผิดชอบ -"
                prepend-inner-icon="mdi-office-building-outline"
                multiple
                chips
                closable-chips
                variant="outlined"
                clearable
                :custom-filter="searchSelectableOption"
                :rules="[v => v.length > 0 || 'จำเป็นต้องเลือกอย่างน้อย 1 หน่วยงาน']"
              >
                <template #item="{ props: itemProps, item }">
                  <v-list-item v-bind="itemProps" :subtitle="item.raw.subtitle" />
                </template>
              </v-autocomplete>
            </v-col>
            <v-col cols="12" sm="6" class="mt-2">
              <v-text-field
                v-model="form.imported_by"
                label="ผู้นำเข้าข้อมูล"
                variant="outlined"
                prepend-inner-icon="mdi-account-outline"
                readonly
              />
            </v-col>
          </v-row>
        </v-card>

      </template>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useDocumentStore } from '../../stores/documentStore';
import type { DocumentBlock, LawMeta, ReviewDocument } from '../../types/document';
import AppShell from '../../components/shared/AppShell.vue';
import WorkflowStepper from '../../components/shared/WorkflowStepper.vue';
import WorkflowFooterBar from '../../components/shared/WorkflowFooterBar.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();
const CURRENT_ADMIN_LABEL = 'ผู้ดูแลระบบ (Admin)';

type SelectableOption = {
  title: string;
  value: string;
  subtitle: string;
};

const DOC_TYPES = ['พ.ร.บ.', 'ข้อบังคับ', 'ระเบียบ', 'ประกาศ', 'คำสั่ง', 'มติ'];
const LAW_TYPE_INFERENCE_RULES: ReadonlyArray<[RegExp, string]> = [
  [/(พระราชบัญญัติ|พ\.?\s*ร\.?\s*บ\.?)/u, 'พ.ร.บ.'],
  [/ข้อบังคับ/u, 'ข้อบังคับ'],
  [/ระเบียบ/u, 'ระเบียบ'],
  [/ประกาศ/u, 'ประกาศ'],
  [/คำสั่ง/u, 'คำสั่ง'],
  [/มติ/u, 'มติ'],
];
const LAW_GROUP_OPTIONS: SelectableOption[] = [
  { title: 'ด้านวิชาการ', value: 'ด้านวิชาการ', subtitle: 'นโยบายวิชาการ หลักสูตร และมาตรฐานการศึกษา' },
  { title: 'การผลิตบัณฑิต', value: 'การผลิตบัณฑิต', subtitle: 'การจัดการเรียนการสอนและการพัฒนาผู้เรียน' },
  { title: 'การเรียนรู้ตลอดชีวิต', value: 'การเรียนรู้ตลอดชีวิต', subtitle: 'หลักสูตรระยะสั้นและการบริการวิชาการต่อเนื่อง' },
  { title: 'การบริหารหลักสูตร', value: 'การบริหารหลักสูตร', subtitle: 'การเปิด ปรับปรุง และกำกับดูแลหลักสูตร' },
  { title: 'ด้านกิจการนิสิต', value: 'ด้านกิจการนิสิต', subtitle: 'สวัสดิการ วินัย และกิจกรรมพัฒนานิสิต' },
  { title: 'ด้านบริหารบุคคล', value: 'ด้านบริหารบุคคล', subtitle: 'การสรรหา แต่งตั้ง สิทธิประโยชน์ และวินัยบุคลากร' },
  { title: 'ด้านการเงินและงบประมาณ', value: 'ด้านการเงินและงบประมาณ', subtitle: 'งบประมาณ รายรับ รายจ่าย และการควบคุมทางการเงิน' },
  { title: 'ด้านทรัพย์สินและจัดซื้อจัดจ้าง', value: 'ด้านทรัพย์สินและจัดซื้อจัดจ้าง', subtitle: 'พัสดุ ทรัพย์สิน และกระบวนการจัดซื้อจัดจ้าง' },
  { title: 'ด้านเทคโนโลยีสารสนเทศ', value: 'ด้านเทคโนโลยีสารสนเทศ', subtitle: 'ระบบสารสนเทศ ความมั่นคงปลอดภัย และข้อมูลดิจิทัล' },
  { title: 'ด้านกฎหมายและนิติการ', value: 'ด้านกฎหมายและนิติการ', subtitle: 'งานนิติการ การตีความ และการกำกับตามกฎหมาย' },
  { title: 'ด้านความร่วมมือระหว่างประเทศ', value: 'ด้านความร่วมมือระหว่างประเทศ', subtitle: 'ความร่วมมือ หน่วยงานคู่สัญญา และกิจการต่างประเทศ' },
  { title: 'อื่นๆ', value: 'อื่นๆ', subtitle: 'รายการที่ไม่อยู่ในหมวดหลักของระบบ' },
];
const AGENCY_OPTIONS: SelectableOption[] = [
  { title: 'มหาวิทยาลัยบูรพา', value: 'มหาวิทยาลัยบูรพา', subtitle: 'หน่วยงานหลักระดับสถาบัน' },
  { title: 'สำนักงานอธิการบดี', value: 'สำนักงานอธิการบดี', subtitle: 'งานบริหารกลางและสนับสนุนผู้บริหาร' },
  { title: 'กองกลาง', value: 'กองกลาง', subtitle: 'สารบรรณ งานธุรการ และงานอำนวยการกลาง' },
  { title: 'กองคลัง', value: 'กองคลัง', subtitle: 'การเงิน บัญชี งบประมาณ และเบิกจ่าย' },
  { title: 'กองพัสดุ', value: 'กองพัสดุ', subtitle: 'จัดซื้อจัดจ้างและบริหารพัสดุ' },
  { title: 'กองกิจการนิสิต', value: 'กองกิจการนิสิต', subtitle: 'สวัสดิการและกิจกรรมนิสิต' },
  { title: 'สำนักวิชาการ', value: 'สำนักวิชาการ', subtitle: 'งานวิชาการ หลักสูตร และมาตรฐานการศึกษา' },
  { title: 'บัณฑิตวิทยาลัย', value: 'บัณฑิตวิทยาลัย', subtitle: 'กำกับดูแลการศึกษาระดับบัณฑิตศึกษา' },
  { title: 'กระทรวงการคลัง', value: 'กระทรวงการคลัง', subtitle: 'หน่วยงานภายนอกด้านการคลังและงบประมาณ' },
  { title: 'สำนักนายกรัฐมนตรี', value: 'สำนักนายกรัฐมนตรี', subtitle: 'หน่วยงานภายนอกด้านนโยบายและงานบริหารราชการ' },
  { title: 'กระทรวงสาธารณสุข', value: 'กระทรวงสาธารณสุข', subtitle: 'หน่วยงานภายนอกด้านสาธารณสุขและสุขภาพ' },
];

const EMPTY: LawMeta = {
  status: '', law_type: '', law_group: '', law_groups: [],
  change_status: null,
  agency: '', agencies: [], promulgation_date: '', effective_date: '',
  published_date: '', expiry_date: null, section_count: null,
  title: '', gazette_reference: '', royal_command: '', repealed_laws: [], keywords: [],
  imported_by: CURRENT_ADMIN_LABEL, parent_document_id: null, signer_group: null,
  access_scope: 'public', permission_group_ids: [],
};

const form = ref<LawMeta>({ ...EMPTY, law_groups: [], agencies: [], repealed_laws: [], keywords: [] });
const noExpiry = ref(false);
const articleBlocks = computed<DocumentBlock[]>(() =>
  (documentStore.review?.pages ?? [])
    .flatMap((page) => page.blocks)
    .filter((block) => block.meta?.chunk_type === 'ARTICLE' || block.meta?.chunk_type === 'CLAUSE'),
);
const articleCount = computed(() => articleBlocks.value.length);
const articleUnitLabel = computed(() => {
  const hasClause = articleBlocks.value.some((b) => b.meta?.chunk_type === 'CLAUSE');
  const hasArticle = articleBlocks.value.some((b) => b.meta?.chunk_type === 'ARTICLE');
  if (hasClause && hasArticle) return 'ข้อ/มาตรา';
  if (hasClause) return 'ข้อ';
  if (hasArticle) return 'มาตรา';
  return 'ข้อ/มาตรา';
});
const sectionCountDisplay = computed(() => `${articleCount.value} ${articleUnitLabel.value}`);

watch(() => documentStore.review, (review) => {
  const meta = review?.law_meta;
  const lawGroups = normalizeSelection(meta?.law_groups, meta?.law_group);
  const agencies = normalizeSelection(meta?.agencies, meta?.agency);
  const savedTitle = meta?.title?.trim() ?? '';
  const inferredTitle = inferDocumentTitle(review);
  const documentTitle = savedTitle || inferredTitle || review?.source_file || '';
  const savedLawType = meta?.law_type?.trim() ?? '';
  form.value = {
    ...EMPTY,
    ...(meta ?? {}),
    law_type: savedLawType || inferLawType(documentTitle),
    law_group: lawGroups[0] ?? '',
    law_groups: lawGroups,
    agency: agencies[0] ?? '',
    agencies,
    repealed_laws: [...(meta?.repealed_laws ?? [])],
    keywords: normalizeKeywords(meta?.keywords),
    title: documentTitle,
    imported_by: meta?.imported_by?.trim() || CURRENT_ADMIN_LABEL,
    expiry_date: meta?.expiry_date ?? null,
    parent_document_id: meta?.parent_document_id ?? null,
  };
  noExpiry.value = meta?.expiry_date === null && !!meta?.title;
}, { immediate: true });

function blockText(block: DocumentBlock): string {
  return (block.approved_text || block.normalized_text || block.raw_text || '').trim();
}

function isImageBlock(block: DocumentBlock): boolean {
  return block.type === 'image' || !!block.meta?.image;
}

function normalizeWhitespace(text: string): string {
  return text.replace(/\s+/g, ' ').trim();
}

function firstDocumentTextLines(review: ReviewDocument | null | undefined): string[] {
  return (review?.pages ?? [])
    .flatMap((page) => [...page.blocks].sort((a, b) => a.reading_order - b.reading_order))
    .filter((block) => !isImageBlock(block))
    .flatMap((block) => blockText(block).split(/\r?\n/u))
    .map(normalizeWhitespace)
    .filter(Boolean)
    .slice(0, 3);
}

function inferDocumentTitle(review: ReviewDocument | null | undefined): string {
  return normalizeWhitespace(firstDocumentTextLines(review).join(' '));
}

function inferLawType(text: string): string {
  for (const [pattern, lawType] of LAW_TYPE_INFERENCE_RULES) {
    if (pattern.test(text)) return lawType;
  }
  return '';
}

function normalizeSelection(values: string[] | undefined, legacyValue: string | undefined): string[] {
  const normalized = (values ?? []).map((value) => value.trim()).filter(Boolean);
  if (normalized.length > 0) return normalized;

  const fallback = legacyValue?.trim() ?? '';
  return fallback ? [fallback] : [];
}

function normalizeKeywords(values: string[] | undefined): string[] {
  const keywords: string[] = [];
  for (const value of values ?? []) {
    const normalized = value.trim();
    if (normalized !== '' && !keywords.includes(normalized)) {
      keywords.push(normalized);
    }
  }

  return keywords.slice(0, 30);
}

function searchSelectableOption(
  value: string,
  query: string,
  item?: { raw?: SelectableOption },
): boolean {
  const needle = query.trim().toLowerCase();
  if (needle === '') return true;

  const title = item?.raw?.title.toLowerCase() ?? value.toLowerCase();
  const subtitle = item?.raw?.subtitle.toLowerCase() ?? '';
  return title.includes(needle) || subtitle.includes(needle);
}

function buildLawMetaPayload(): LawMeta {
  const lawGroups = normalizeSelection(form.value.law_groups, form.value.law_group);
  const agencies = normalizeSelection(form.value.agencies, form.value.agency);

  return {
    ...form.value,
    law_group: lawGroups[0] ?? '',
    law_groups: lawGroups,
    agency: agencies[0] ?? '',
    agencies,
    keywords: normalizeKeywords(form.value.keywords),
    imported_by: form.value.imported_by.trim() || CURRENT_ADMIN_LABEL,
    section_count: articleCount.value,
  };
}

async function saveAndNext(): Promise<void> {
  const payload = buildLawMetaPayload();
  const saved = await documentStore.saveLawMeta(payload);
  if (!saved) return;
  form.value = { ...form.value, ...payload };
  const progressed = await documentStore.completeWorkflowStep(4);
  if (!progressed) return;
  router.push(`/documents/${props.documentId}/relations`);
}

onMounted(() => documentStore.fetch(props.documentId));
onBeforeUnmount(() => documentStore.reset());
</script>
