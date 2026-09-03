<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'ข้อมูลเอกสาร']"
    title="ข้อมูลเอกสาร"
    subtitle="กรอกข้อมูลเอกสารและรายละเอียดการประกาศใช้"
  >
    <WorkflowFooterBar
      :step="isOld ? 2 : 4"
      :variant="isOld ? 'historical' : 'default'"
      next-label="ถัดไป"
      :next-loading="documentStore.saving"
      @back="router.back()"
      @next="saveAndNext"
    />
    <div class="law-info-page mx-auto">
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
          กรุณากรอกข้อมูลที่มีเครื่องหมาย * ให้ครบถ้วน ยกเว้นคำสำคัญ และเลือกวันที่สิ้นสุดการใช้หรือเลือกไม่มีวันสิ้นสุด
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
                :label="requiredLabel('ชื่อเอกสาร')"
                variant="outlined"
                :rules="requiredTextRules('ชื่อเอกสาร')"
                required
              />
            </v-col>
            <v-col v-if="isOld" cols="12" sm="6">
              <v-autocomplete
                v-model="form.source"
                :items="lawSources"
                item-title="title"
                item-value="value"
                :label="requiredLabel('แหล่งที่มาของเอกสาร')"
                placeholder="- เลือกแหล่งที่มา -"
                variant="outlined"
                :custom-filter="searchSelectableOption"
                :rules="sourceRules"
                required
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-autocomplete
                v-model="form.law_type"
                :items="filteredDocumentTypes"
                item-title="title"
                item-value="value"
                :label="requiredLabel('ประเภทเอกสาร')"
                :placeholder="documentTypePlaceholder"
                variant="outlined"
                :disabled="documentTypeDisabled"
                :custom-filter="searchSelectableOption"
                :rules="documentTypeRules"
                no-data-text="ไม่พบประเภทเอกสารสำหรับแหล่งที่มานี้"
                required
              />
            </v-col>
            <v-col v-if="form.law_type === 'ประกาศ'" cols="12">
              <v-radio-group
                v-model="form.issuer"
                :label="requiredLabel('ออกโดย')"
                :rules="issuerRules"
                required
                inline
                hide-details="auto"
              >
                <v-radio
                  v-for="opt in ISSUER_OPTIONS"
                  :key="opt.value"
                  :label="opt.title"
                  :value="opt.value"
                />
              </v-radio-group>
            </v-col>
            <v-col v-if="isEditMode" cols="12" sm="6">
              <v-autocomplete
                v-model="form.status"
                :items="statuses"
                item-title="title"
                item-value="value"
                :label="requiredLabel('สถานะการบังคับใช้')"
                placeholder="- เลือกสถานะ -"
                variant="outlined"
                clearable
                :custom-filter="searchSelectableOption"
                :rules="requiredTextRules('สถานะการบังคับใช้')"
                required
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-select
                v-model="form.change_status"
                :items="changeStatusTypeItems"
                item-title="title"
                item-value="value"
                :label="requiredLabel('สถานะการเปลี่ยนแปลง')"
                placeholder="- เลือกสถานะการเปลี่ยนแปลง -"
                variant="outlined"
                clearable
                :rules="requiredTextRules('สถานะการเปลี่ยนแปลง')"
                required
              />
              <p v-if="changeStatusHasDetails" class="text-caption text-medium-emphasis mt-n1 mb-3">
                รายละเอียดการเปลี่ยนแปลงเลือกตอนเพิ่มความสัมพันธ์รายข้อในขั้นตอนถัดไป
              </p>
            </v-col>
            <v-col cols="12">
              <v-autocomplete
                v-model="form.law_groups"
                :items="lawGroups"
                item-title="title"
                item-value="value"
                :label="requiredLabel('กลุ่มกฎหมาย')"
                placeholder="- เลือกกลุ่มกฎหมาย -"
                multiple
                chips
                closable-chips
                variant="outlined"
                clearable
                :custom-filter="searchSelectableOption"
                :rules="requiredArrayRules('กลุ่มกฎหมาย')"
                required
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
            <v-col v-if="!isOld" cols="12" sm="6">
              <v-text-field
                :model-value="sectionCountDisplay"
                :label="requiredLabel('จำนวนข้อ')"
                variant="outlined"
                readonly
                :rules="[() => articleCount >= 0 || 'ไม่พบจำนวนข้อ']"
                required
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
                :label="requiredLabel('วันที่ประกาศ')"
                required
                :rules="promulgationDateRules"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <ThaiDatePicker
                v-model="form.effective_date"
                :label="requiredLabel('วันที่มีผลบังคับใช้')"
                required
                :rules="effectiveDateRules"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <ThaiDatePicker
                v-model="form.expiry_date"
                :label="requiredLabel('วันที่สิ้นสุดการใช้')"
                required
                :disabled="noExpiry"
                disabled-placeholder="ไม่มีวันสิ้นสุด"
                :rules="expiryDateRules"
              />
              <v-checkbox
                v-model="noExpiry"
                label="ไม่มีวันสิ้นสุด"
                density="compact"
                hide-details="auto"
                class="mt-1"
                :rules="noExpiryRules"
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
                <span class="text-body-2 font-weight-medium">{{ requiredLabel('หน่วยงานรับผิดชอบ') }}</span>
                <v-chip v-if="form.agencies.length === 0" size="x-small" color="error" class="ml-1">จำเป็น</v-chip>
              </div>
              <v-autocomplete
                v-model="form.agencies"
                :items="agencies"
                item-title="title"
                item-value="value"
                :label="requiredLabel('หน่วยงานรับผิดชอบ')"
                placeholder="- เลือกหน่วยงานรับผิดชอบ -"
                prepend-inner-icon="mdi-office-building-outline"
                multiple
                chips
                closable-chips
                variant="outlined"
                clearable
                :custom-filter="searchSelectableOption"
                :rules="requiredArrayRules('หน่วยงานรับผิดชอบ')"
                required
              >
                <template #item="{ props: itemProps, item }">
                  <v-list-item v-bind="itemProps" :subtitle="item.subtitle" />
                </template>
              </v-autocomplete>
            </v-col>
            <v-col cols="12" sm="6" class="mt-2">
              <v-text-field
                v-model="form.imported_by"
                :label="requiredLabel('ผู้นำเข้าข้อมูล')"
                variant="outlined"
                prepend-inner-icon="mdi-account-outline"
                readonly
                :rules="requiredTextRules('ผู้นำเข้าข้อมูล')"
                required
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
import { useRoute, useRouter } from 'vue-router';
import type { SelectableOption } from '../../api/client';
import { useLookups } from '../../composables/useLookups';
import { useDocumentStore } from '../../stores/documentStore';
import type { DocumentBlock, LawMeta, ReviewDocument } from '../../types/document';
import { normalizeChunkType } from '../../types/chunkType';
import AppShell from '../../components/shared/AppShell.vue';
import WorkflowFooterBar from '../../components/shared/WorkflowFooterBar.vue';
import ThaiDatePicker from '../../components/shared/ThaiDatePicker.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();
const route = useRoute();
const isEditMode = computed(() => route.query.mode === 'edit');
const isOld = computed(() => documentStore.review?.law_meta?.document_type === 'old');
const { documentTypes, statuses, changeStatusTypes, agencies, lawGroups, lawSources, load: loadLookups } = useLookups();
const CURRENT_ADMIN_LABEL = 'ผู้ดูแลระบบ (Admin)';
const LAW_TYPE_INFERENCE_RULES: ReadonlyArray<[RegExp, string]> = [
  [/(พระราชบัญญัติ|พ\.?\s*ร\.?\s*บ\.?)/u, 'พระราชบัญญัติ'],
  [/ข้อบังคับ/u, 'ข้อบังคับ'],
  [/ระเบียบ/u, 'ระเบียบ'],
  [/สภามหาวิทยาลัย/u, 'ประกาศ'],
  [/ประกาศ/u, 'ประกาศ'],
  [/คำสั่ง/u, 'ประกาศ'],
  [/มติ/u, 'ประกาศ'],
];

const ISSUER_OPTIONS: ReadonlyArray<{ title: string; value: string }> = [
  { title: 'ออกโดยมหาวิทยาลัย', value: 'มหาวิทยาลัย' },
  { title: 'ออกโดยสภามหาวิทยาลัย', value: 'สภามหาวิทยาลัย' },
];

const ANNOUNCEMENT_ISSUER_LAW_TYPES: Readonly<Record<string, string>> = {
  ประกาศที่ออกโดยมหาวิทยาลัย: 'มหาวิทยาลัย',
  ประกาศที่ออกโดยสภามหาวิทยาลัย: 'สภามหาวิทยาลัย',
};

const EMPTY: LawMeta = {
  status: 'ร่าง', source: '', law_type: '', law_group: '', law_groups: [],
  change_status: null, change_details: [],
  agency: '', agencies: [], promulgation_date: '', effective_date: '',
  published_date: '', expiry_date: null, section_count: null,
  title: '', gazette_reference: '', royal_command: '', repealed_laws: [], keywords: [],
  imported_by: CURRENT_ADMIN_LABEL, parent_document_id: null, parent_document_ids: [], signer_group: null, issuer: null,
  access_scope: 'public', permission_group_ids: [],
};

const form = ref<LawMeta>({ ...EMPTY, law_groups: [], agencies: [], repealed_laws: [], keywords: [] });
const noExpiry = ref(false);
const formRef = ref<VForm | null>(null);
const validationFailed = ref(false);

function normalizeSavedLawType(saved: string): string {
  return ANNOUNCEMENT_ISSUER_LAW_TYPES[saved] ? 'ประกาศ' : saved;
}

function inferAnnouncementIssuer(text: string, selectedAgencies: string[]): string | null {
  if (selectedAgencies.some((agency) => agency.includes('สภา')) || /สภามหาวิทยาลัย|มติ/u.test(text)) {
    return 'สภามหาวิทยาลัย';
  }
  if (/ประกาศ|คำสั่ง/u.test(text)) return 'มหาวิทยาลัย';
  return null;
}

function requiredLabel(label: string): string {
  return `${label} *`;
}

function hasText(value: unknown): boolean {
  return typeof value === 'string' ? value.trim().length > 0 : !!value;
}

function hasArrayValue(value: unknown): boolean {
  return Array.isArray(value) && value.some((entry) => typeof entry === 'string' ? entry.trim().length > 0 : !!entry);
}

function requiredTextRules(label: string): Array<(v: unknown) => boolean | string> {
  return [(v: unknown) => hasText(v) || `กรุณากรอก${label}`];
}

function requiredArrayRules(label: string): Array<(v: unknown) => boolean | string> {
  return [(v: unknown) => hasArrayValue(v) || `กรุณาเลือก${label}`];
}

const issuerRules = [
  (v: unknown) => form.value.law_type !== 'ประกาศ' || hasText(v) || 'กรุณาเลือกผู้ออกประกาศ',
];

// internal ('ข้อ') vs external ('มาตรา') is derived from the selected law_type's
// source tag, falling back to the explicit source field for old docs.
const lawSourceKind = computed<'internal' | 'external'>(() => {
  const byType = documentTypes.value.find((t) => t.value === form.value.law_type)?.source;
  const src = byType || form.value.source;
  return src === 'external' ? 'external' : 'internal';
});

function matchesSource(optionSource: string | undefined): boolean {
  return optionSource === 'both' || optionSource === undefined || optionSource === lawSourceKind.value;
}

const changeStatusTypeItems = computed(() => {
  const items = changeStatusTypes.value.filter((t) => matchesSource(t.source));
  const current = form.value.change_status?.trim() ?? '';
  if (current && !items.some((item) => item.value === current)) {
    return [{ title: current, value: current }, ...items];
  }
  return items;
});

const selectedChangeType = computed(() =>
  changeStatusTypes.value.find((t) => t.value === form.value.change_status),
);

const changeStatusHasDetails = computed(() => selectedChangeType.value?.has_details === true);

const documentTypeDisabled = computed(() => isOld.value && !hasText(form.value.source));
const documentTypePlaceholder = computed(() =>
  documentTypeDisabled.value ? 'กรุณาเลือกแหล่งที่มาก่อน' : '- เลือกประเภทเอกสาร -',
);

// Old docs restrict law_type to the chosen source; new docs keep the full list.
const selectableDocumentTypes = computed(() =>
  documentTypes.value.filter((t) => !ANNOUNCEMENT_ISSUER_LAW_TYPES[t.value]),
);

const filteredDocumentTypes = computed(() => {
  const items = isOld.value
    ? selectableDocumentTypes.value.filter((t) => hasText(form.value.source) && t.source === form.value.source)
    : selectableDocumentTypes.value;
  const current = form.value.law_type?.trim() ?? '';
  if (current && !items.some((item) => item.value === current)) {
    return [{ title: current, value: current }, ...items];
  }
  return items;
});

const sourceRules = [
  (v: unknown) => !isOld.value || hasText(v) || 'กรุณาเลือกแหล่งที่มาของเอกสาร',
];

const documentTypeRules = [
  () => !isOld.value || hasText(form.value.source) || 'กรุณาเลือกแหล่งที่มาของเอกสารก่อน',
  (v: unknown) => hasText(v) || 'กรุณาเลือกประเภทเอกสาร',
  (v: unknown) => {
    if (!isOld.value || !hasText(v)) return true;
    return filteredDocumentTypes.value.some((type) => type.value === v)
      || 'ประเภทเอกสารไม่ตรงกับแหล่งที่มา';
  },
];

function dateMs(value: unknown): number | null {
  if (typeof value !== 'string' || value === '') return null;
  const t = new Date(`${value}T00:00:00`).getTime();
  return Number.isNaN(t) ? null : t;
}

const promulgationDateRules = [
  ...requiredTextRules('วันที่ประกาศ'),
  (v: unknown) => {
    const prom = dateMs(v);
    const eff = dateMs(form.value.effective_date);
    if (prom == null || eff == null) return true;
    return prom <= eff || 'ต้องไม่หลังวันที่มีผลบังคับใช้';
  },
];

const effectiveDateRules = [
  (v: unknown) => !!v || 'จำเป็นต้องระบุ',
  (v: unknown) => {
    const eff = dateMs(v);
    const prom = dateMs(form.value.promulgation_date);
    if (eff == null || prom == null) return true;
    return eff >= prom || 'ต้องไม่ก่อนวันที่ประกาศ';
  },
];

const expiryDateRules = [
  (v: unknown) => {
    if (noExpiry.value) return true;
    if (!hasText(v)) return 'กรุณาเลือกวันที่สิ้นสุดการใช้ หรือเลือกไม่มีวันสิ้นสุด';
    const exp = dateMs(v);
    const eff = dateMs(form.value.effective_date);
    if (exp == null || eff == null) return true;
    return exp > eff || 'ต้องอยู่หลังวันที่มีผลบังคับใช้';
  },
];

const noExpiryRules = [
  (v: unknown) => !!v || hasText(form.value.expiry_date) || 'ถ้าไม่มีวันที่สิ้นสุด กรุณาเลือกไม่มีวันสิ้นสุด',
];

const articleBlocks = computed<DocumentBlock[]>(() =>
  (documentStore.review?.pages ?? [])
    .flatMap((page) => page.blocks)
    .filter(isClauseBlock),
);
const articleCount = computed(() => articleBlocks.value.length);
const articleUnitLabel = computed(() => 'ข้อ');
const sectionCountDisplay = computed(() => `${articleCount.value} ${articleUnitLabel.value}`);

watch(() => documentStore.review, (review) => {
  const meta = review?.law_meta;
  const lawGroups = normalizeSelection(meta?.law_groups, meta?.law_group);
  const agencies = normalizeSelection(meta?.agencies, meta?.agency);
  const savedTitle = meta?.title?.trim() ?? '';
  const inferredTitle = inferDocumentTitle(review);
  const documentTitle = savedTitle || inferredTitle || review?.source_file || '';
  const savedLawType = meta?.law_type?.trim() ?? '';
  const oldDocument = meta?.document_type === 'old';
  const normalizedLawType = normalizeSavedLawType(savedLawType) || (oldDocument ? '' : inferLawType(documentTitle));
  form.value = {
    ...EMPTY,
    ...(meta ?? {}),
    law_type: normalizedLawType,
    law_group: lawGroups[0] ?? '',
    law_groups: lawGroups,
    agency: agencies[0] ?? '',
    agencies,
    repealed_laws: [...(meta?.repealed_laws ?? [])],
    change_details: [...(meta?.change_details ?? [])],
    keywords: normalizeKeywords(meta?.keywords),
    title: documentTitle,
    imported_by: meta?.imported_by?.trim() || CURRENT_ADMIN_LABEL,
    expiry_date: meta?.expiry_date ?? null,
    parent_document_id: meta?.parent_document_id ?? null,
    parent_document_ids: meta?.parent_document_ids?.length
      ? [...meta.parent_document_ids]
      : (meta?.parent_document_id ? [meta.parent_document_id] : []),
    issuer: normalizedLawType === 'ประกาศ'
      ? (meta?.issuer || ANNOUNCEMENT_ISSUER_LAW_TYPES[savedLawType] || inferAnnouncementIssuer(documentTitle, agencies))
      : null,
  };
  noExpiry.value = meta?.expiry_date === null && !!meta?.title;
}, { immediate: true });

function blockText(block: DocumentBlock): string {
  return (block.approved_text || block.normalized_text || block.raw_text || '').trim();
}

function isClauseBlock(block: DocumentBlock): boolean {
  if (normalizeChunkType(block.meta?.chunk_type) === 'CLAUSE') return true;

  const markerType = block.meta?.list_marker?.type;
  if (markerType === 'legal-มาตรา' || markerType === 'legal-ข้อ') return true;

  return /^(มาตรา|ข้อ)\s*[๐-๙0-9]/u.test(blockText(block));
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
    issuer: form.value.law_type === 'ประกาศ' ? (form.value.issuer ?? null) : null,
    change_details: changeStatusHasDetails.value ? [...(form.value.change_details ?? [])] : [],
  };
}

function focusFirstError(errors: { id: string | number; errorMessages: string[] }[]): void {
  const firstId = errors[0]?.id;
  if (firstId == null) return;

  const el = document.getElementById(String(firstId));
  if (!el) return;

  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  requestAnimationFrame(() => el.focus());
}

async function saveAndNext(): Promise<void> {
  const result = await formRef.value?.validate();
  if (!result?.valid) {
    validationFailed.value = true;
    await nextTick();
    focusFirstError(result?.errors ?? []);
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

async function clearValidationBannerIfValid(): Promise<void> {
  if (!validationFailed.value) return;
  const result = await formRef.value?.validate();
  if (result?.valid) validationFailed.value = false;
}

watch(form, () => {
  void clearValidationBannerIfValid();
}, { deep: true });

watch(noExpiry, () => {
  void clearValidationBannerIfValid();
});

watch(() => form.value.law_type, (lawType) => {
  if (lawType !== 'ประกาศ') form.value.issuer = null;
});

watch(() => form.value.change_status, () => {
  if (!changeStatusHasDetails.value) form.value.change_details = [];
});

watch(() => form.value.source, () => {
  // Only clear law_type if it no longer fits the chosen source — keeps a stored
  // value intact when the review load sets source+law_type together.
  if (!isOld.value) return;
  if (!hasText(form.value.source)) {
    form.value.law_type = '';
    return;
  }
  const stillValid = filteredDocumentTypes.value.some((t) => t.value === form.value.law_type);
  if (!stillValid) form.value.law_type = '';
});

onMounted(() => {
  void loadLookups();
  void documentStore.fetch(props.documentId);
});
onBeforeUnmount(() => documentStore.reset());
</script>

<style scoped>
.law-info-page {
  max-width: 860px;
  padding-bottom: 60px;
}
</style>
