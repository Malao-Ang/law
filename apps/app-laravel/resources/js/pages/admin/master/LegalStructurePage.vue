<template>
  <MasterDataPage
    kind="legal_structure"
    title="โครงสร้างกฎหมาย"
    subtitle="จัดการหัวข้อและรูปแบบการแบ่งส่วนเอกสารกฎหมายสำหรับการนำเข้าและประมวลผล"
    :breadcrumbs="['จัดการข้อมูลระบบ', 'โครงสร้างกฎหมาย']"
    add-label="เพิ่มโครงสร้าง"
    :stat-labels="statLabels"
    :columns="columns"
    search-placeholder="ค้นหาด้วยรหัส หรือชื่อโครงสร้าง..."
    :dialog-title="{ create: 'เพิ่มหัวข้อโครงสร้างกฎหมาย', edit: 'แก้ไขหัวข้อโครงสร้างกฎหมาย' }"
    dialog-subtitle="กำหนดโครงสร้างกฎหมายสำหรับการจัดลำดับเอกสาร"
    :field-labels="{ code: 'รหัสหัวข้อ', name: 'ชื่อหัวข้อ' }"
    basic-section-title="1. ข้อมูลพื้นฐาน"
    name-placeholder="เช่น ชื่อประกาศ"
    description-placeholder="ระบุรายละเอียดหรือคำอธิบายเพิ่มเติม (ถ้ามี)"
    dialog-max-width="820"
  >
    <template #item.name="{ item }">
      <div class="legal-structure-page__name-cell">
        <span class="font-weight-bold">{{ item.name }}</span>
        <v-chip v-if="isRequired(item)" size="x-small" color="warning" variant="tonal" class="font-weight-bold">
          บังคับ
        </v-chip>
      </div>
    </template>

    <template #item.family_codes="{ item }">
      <div class="legal-structure-page__chips">
        <v-chip
          v-for="code in familyCodes(item)"
          :key="code"
          size="small"
          :color="familyColor(code)"
          variant="tonal"
        >
          {{ familyLabel(code) }}
        </v-chip>
      </div>
    </template>

    <template #item.file_types="{ item }">
      <div class="legal-structure-page__chips">
        <v-chip
          v-for="fileType in fileTypes(item)"
          :key="fileType"
          size="small"
          :color="fileType === 'word' ? 'primary' : 'error'"
          variant="tonal"
        >
          {{ fileType === 'word' ? 'Word' : 'PDF' }}
        </v-chip>
      </div>
    </template>

    <template #after-basic="{ form, item, errors }">
      <section class="legal-structure-dialog__section">
        <div class="legal-structure-dialog__section-title">2. ประเภทเอกสารที่รองรับ</div>
        <p class="legal-structure-dialog__hint">เลือกประเภทเอกสารที่ต้องการให้แสดงหัวข้อโครงสร้างนี้</p>
        <div class="legal-structure-dialog__links">
          <button type="button" @click="selectAllFamilies(form)">เลือกทั้งหมด</button>
          <button type="button" @click="clearFamilies(form)">ล้างการเลือก</button>
        </div>
        <div class="legal-structure-dialog__chips">
          <v-chip
            v-for="family in activeFamilies"
            :key="family.code"
            :color="isFamilySelected(form, family.code) ? 'admin-primary' : undefined"
            :variant="isFamilySelected(form, family.code) ? 'tonal' : 'outlined'"
            class="legal-structure-dialog__choice"
            @click="toggleFamily(form, family.code)"
          >
            {{ family.title }}
          </v-chip>
        </div>
        <div v-if="errors['attrs.family_codes']" class="text-caption text-error">
          {{ errors['attrs.family_codes'][0] }}
        </div>

        <div class="legal-structure-dialog__subhead">ชนิดไฟล์ที่รองรับ</div>
        <div class="legal-structure-dialog__chips">
          <v-chip
            v-for="fileType in fileTypeOptions"
            :key="fileType.value"
            :color="isFileTypeSelected(form, fileType.value) ? 'admin-primary' : undefined"
            :variant="isFileTypeSelected(form, fileType.value) ? 'tonal' : 'outlined'"
            class="legal-structure-dialog__choice"
            @click="toggleFileType(form, fileType.value)"
          >
            {{ fileType.title }}
          </v-chip>
        </div>
        <div v-if="errors['attrs.file_types']" class="text-caption text-error">
          {{ errors['attrs.file_types'][0] }}
        </div>

        <div class="legal-structure-dialog__subhead">เงื่อนไข*</div>
        <div class="legal-structure-dialog__toggle">
          <button
            type="button"
            :class="{ 'is-active': requiredValue(form) }"
            @click="setRequired(form, true)"
          >
            บังคับ
          </button>
          <button
            type="button"
            :class="{ 'is-active': !requiredValue(form) }"
            @click="setRequired(form, false)"
          >
            ไม่บังคับ
          </button>
        </div>
        <p class="legal-structure-dialog__hint">* หัวข้อบังคับ: ผู้ใช้ต้องกรอกเนื้อหาก่อนบันทึกเอกสาร</p>

        <v-alert
          v-if="usageRemovalWarning(form, item)"
          type="warning"
          variant="tonal"
          density="compact"
        >
          {{ usageRemovalWarning(form, item) }}
        </v-alert>
      </section>
    </template>
  </MasterDataPage>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue';
import MasterDataPage from '../../../components/master/MasterDataPage.vue';
import { useLookups } from '../../../composables/useLookups';
import type { LawFamilyOption } from '../../../api/client';
import type { MasterItem, UpsertMasterItemPayload } from '../../../types/masterData';

type LegalFileType = 'word' | 'pdf';

const { lawFamilies, load: loadLookups } = useLookups();

const statLabels = {
  total: 'โครงสร้างกฎหมายทั้งหมด',
  active: 'โครงสร้างกฎหมายที่ใช้งาน',
  inactive: 'โครงสร้างกฎหมายที่ปิดใช้งาน',
};

const columns = [
  { key: 'name', title: 'ชื่อหัวข้อ', width: '260px' },
  { key: 'family_codes', title: 'ประเภทเอกสารที่รองรับ', width: '300px' },
  { key: 'file_types', title: 'ชนิดไฟล์', width: '180px' },
];

const fileTypeOptions: Array<{ title: string; value: LegalFileType }> = [
  { title: 'Word (.doc, .docx)', value: 'word' },
  { title: 'PDF', value: 'pdf' },
];

const activeFamilies = computed<LawFamilyOption[]>(() =>
  [...lawFamilies.value].sort((a, b) => {
    const sort = (a.sort_order ?? 9999) - (b.sort_order ?? 9999);
    if (sort !== 0) return sort;
    return a.code.localeCompare(b.code, 'th');
  }),
);

onMounted(() => {
  void loadLookups();
});

function attrs(form: UpsertMasterItemPayload): NonNullable<UpsertMasterItemPayload['attrs']> {
  form.attrs = form.attrs ?? {};
  if (!Array.isArray(form.attrs.family_codes)) form.attrs.family_codes = [];
  if (!Array.isArray(form.attrs.file_types)) form.attrs.file_types = [];
  if (typeof form.attrs.is_required !== 'boolean') form.attrs.is_required = false;
  return form.attrs;
}

function familyCodes(item: MasterItem): string[] {
  return Array.isArray(item.attrs.family_codes) ? item.attrs.family_codes : [];
}

function fileTypes(item: MasterItem): LegalFileType[] {
  return Array.isArray(item.attrs.file_types)
    ? item.attrs.file_types.filter((value): value is LegalFileType => value === 'word' || value === 'pdf')
    : [];
}

function isRequired(item: MasterItem): boolean {
  return item.attrs.is_required === true;
}

function familyItem(code: string): LawFamilyOption | null {
  return activeFamilies.value.find((family) => family.code === code) ?? null;
}

function familyLabel(code: string): string {
  return familyItem(code)?.title ?? code;
}

function familyColor(code: string): string | undefined {
  return familyItem(code)?.color || undefined;
}

function formFamilyCodes(form: UpsertMasterItemPayload): string[] {
  return attrs(form).family_codes as string[];
}

function formFileTypes(form: UpsertMasterItemPayload): LegalFileType[] {
  return attrs(form).file_types as LegalFileType[];
}

function isFamilySelected(form: UpsertMasterItemPayload, code: string): boolean {
  return formFamilyCodes(form).includes(code);
}

function toggleFamily(form: UpsertMasterItemPayload, code: string): void {
  const selected = formFamilyCodes(form);
  attrs(form).family_codes = selected.includes(code)
    ? selected.filter((item) => item !== code)
    : [...selected, code];
}

function selectAllFamilies(form: UpsertMasterItemPayload): void {
  attrs(form).family_codes = activeFamilies.value.map((family) => family.code);
}

function clearFamilies(form: UpsertMasterItemPayload): void {
  attrs(form).family_codes = [];
}

function isFileTypeSelected(form: UpsertMasterItemPayload, fileType: LegalFileType): boolean {
  return formFileTypes(form).includes(fileType);
}

function toggleFileType(form: UpsertMasterItemPayload, fileType: LegalFileType): void {
  const selected = formFileTypes(form);
  attrs(form).file_types = selected.includes(fileType)
    ? selected.filter((item) => item !== fileType)
    : [...selected, fileType];
}

function requiredValue(form: UpsertMasterItemPayload): boolean {
  return attrs(form).is_required === true;
}

function setRequired(form: UpsertMasterItemPayload, value: boolean): void {
  attrs(form).is_required = value;
}

function usageRemovalWarning(form: UpsertMasterItemPayload, item: MasterItem | null): string {
  const usageCount = item?.usage_count ?? 0;
  if (!item || usageCount <= 0) return '';

  const originalFamilies = new Set(familyCodes(item));
  const originalFileTypes = new Set(fileTypes(item));
  const selectedFamilies = new Set(formFamilyCodes(form));
  const selectedFileTypes = new Set(formFileTypes(form));
  const removedFamily = [...originalFamilies].some((code) => !selectedFamilies.has(code));
  const removedFileType = [...originalFileTypes].some((fileType) => !selectedFileTypes.has(fileType));
  if (!removedFamily && !removedFileType) return '';

  return `มีเอกสาร ${usageCount.toLocaleString('th-TH')} ฉบับใช้โครงสร้างนี้อยู่ — เอกสารเดิมยังเก็บค่าไว้ แต่จะไม่แสดงเป็นตัวเลือกสำหรับประเภทที่นำออก`;
}
</script>

<style scoped>
.legal-structure-page__name-cell,
.legal-structure-page__chips {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 2px 0;
}

.legal-structure-dialog__section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.legal-structure-dialog__section-title,
.legal-structure-dialog__subhead {
  color: #1f2933;
  font-size: 14px;
  font-weight: 800;
}

.legal-structure-dialog__hint {
  color: #667085;
  font-size: 13px;
  line-height: 1.45;
  margin: 0;
}

.legal-structure-dialog__links {
  display: flex;
  gap: 14px;
}

.legal-structure-dialog__links button {
  color: rgb(var(--v-theme-admin-primary));
  font-size: 13px;
  font-weight: 700;
}

.legal-structure-dialog__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.legal-structure-dialog__choice {
  cursor: pointer;
}

.legal-structure-dialog__toggle {
  background: #eef2f7;
  border-radius: 10px;
  display: grid;
  gap: 6px;
  grid-template-columns: 1fr 1fr;
  padding: 6px;
}

.legal-structure-dialog__toggle button {
  border-radius: 8px;
  color: #667085;
  font-weight: 800;
  min-height: 42px;
}

.legal-structure-dialog__toggle button.is-active {
  background: #fff;
  color: rgb(var(--v-theme-admin-primary));
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12);
}
</style>
