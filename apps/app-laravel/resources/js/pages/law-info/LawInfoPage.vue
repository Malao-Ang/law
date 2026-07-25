<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'ข้อมูลเอกสาร']"
    title=""
  >
    <WorkflowFooterBar
      :step="4"
      next-label="ถัดไป"
      :next-loading="documentStore.saving"
      @back="router.push(`/documents/${props.documentId}/rag`)"
      @next="saveAndNext"
    />
    <div class="mx-auto" style="max-width:860px; padding-bottom:60px">
      <WorkflowStepper :step="4" description="กรอกข้อมูลเอกสารและรายละเอียดการประกาศใช้" />

      <div v-if="documentStore.loading" class="d-flex flex-column align-center justify-center pa-12 ga-3 text-medium-emphasis">
        <v-progress-circular indeterminate color="admin-primary" />
        <span>กำลังโหลด...</span>
      </div>

      <v-form v-else ref="formRef" validate-on="submit lazy">
        <v-alert v-if="documentStore.saveError" type="error" variant="tonal" density="compact" closable class="mb-4"
          @click:close="documentStore.setSaveError()">
          {{ documentStore.saveError }}
        </v-alert>

        <v-alert
          v-if="validationFailed"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-4"
          icon="mdi-alert-circle-outline"
        >
          กรุณากรอกข้อมูลที่จำเป็นให้ครบก่อนดำเนินการต่อ: ชื่อเอกสาร, ประเภทเอกสาร, กลุ่มกฎหมาย, หน่วยงานรับผิดชอบ, วันที่มีผลบังคับใช้
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
                :items="documentTypes"
                item-title="title"
                item-value="value"
                label="ประเภทเอกสาร"
                placeholder="- เลือกประเภทเอกสาร -"
                variant="outlined"
                :rules="[v => !!v || 'จำเป็นต้องเลือก']"
                required
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-select
                v-model="form.status"
                :items="statuses"
                item-title="title"
                item-value="value"
                label="สถานะการบังคับใช้"
                placeholder="- เลือกสถานะ -"
                variant="outlined"
                clearable
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-select
                v-model="form.change_status"
                :items="changeStatuses"
                item-title="title"
                item-value="value"
                label="สถานะการเปลี่ยนแปลง"
                placeholder="- เลือกสถานะการเปลี่ยนแปลง -"
                variant="outlined"
                clearable
              />
            </v-col>
            <v-col cols="12">
              <v-autocomplete
                v-model="form.law_groups"
                :items="lawGroups"
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
                  <v-list-item v-bind="itemProps" :subtitle="item.subtitle" />
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
              <ThaiDatePicker
                v-model="form.promulgation_date"
                label="วันที่ประกาศ"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <ThaiDatePicker
                v-model="form.effective_date"
                label="วันที่มีผลบังคับใช้ *"
                required
                :rules="[v => !!v || 'จำเป็นต้องระบุ']"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <ThaiDatePicker
                v-model="form.expiry_date"
                label="วันที่สิ้นสุดการใช้"
                :disabled="noExpiry"
                disabled-placeholder="ไม่มีวันสิ้นสุด"
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
                :items="agencies"
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
                  <v-list-item v-bind="itemProps" :subtitle="item.subtitle" />
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

      </v-form>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import type { VForm } from 'vuetify/components';
import { useRouter } from 'vue-router';
import type { SelectableOption } from '../../api/client';
import { useLookups } from '../../composables/useLookups';
import { useDocumentStore } from '../../stores/documentStore';
import type { DocumentBlock, LawMeta, ReviewDocument } from '../../types/document';
import AppShell from '../../components/shared/AppShell.vue';
import WorkflowStepper from '../../components/shared/WorkflowStepper.vue';
import WorkflowFooterBar from '../../components/shared/WorkflowFooterBar.vue';
import ThaiDatePicker from '../../components/shared/ThaiDatePicker.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();
const { documentTypes, statuses, changeStatuses, agencies, lawGroups, load: loadLookups } = useLookups();
const CURRENT_ADMIN_LABEL = 'ผู้ดูแลระบบ (Admin)';
const LAW_TYPE_INFERENCE_RULES: ReadonlyArray<[RegExp, string]> = [
  [/(พระราชบัญญัติ|พ\.?\s*ร\.?\s*บ\.?)/u, 'พ.ร.บ.'],
  [/ข้อบังคับ/u, 'ข้อบังคับ'],
  [/ระเบียบ/u, 'ระเบียบ'],
  [/ประกาศ/u, 'ประกาศ'],
  [/คำสั่ง/u, 'คำสั่ง'],
  [/มติ/u, 'มติ'],
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
const formRef = ref<VForm | null>(null);
const validationFailed = ref(false);
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
  const result = await formRef.value?.validate();
  if (!result?.valid) {
    validationFailed.value = true;
    await nextTick();
    document.querySelector('.v-alert[type="warning"]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return;
  }
  validationFailed.value = false;
  const payload = buildLawMetaPayload();
  const saved = await documentStore.saveLawMeta(payload);
  if (!saved) return;
  form.value = { ...form.value, ...payload };
  const progressed = await documentStore.completeWorkflowStep(4);
  if (!progressed) return;
  router.push(`/documents/${props.documentId}/relations`);
}

onMounted(() => {
  void loadLookups();
  void documentStore.fetch(props.documentId);
});
onBeforeUnmount(() => documentStore.reset());
</script>
