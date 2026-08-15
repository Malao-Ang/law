<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล']"
    title="การนำเข้าเอกสารกฎหมาย"
    subtitle="อัปโหลดไฟล์เพื่อเตรียมสกัดเนื้อหาเข้าสู่ระบบฐานข้อมูล"
    show-bell
  >
    <div class="adm-up">

      <!-- ── Drop zone ─────────────────────────────────────── -->
      <div
        class="adm-drop"
        :class="{ 'adm-drop--over': dragOver }"
        @dragover.prevent="dragOver = true"
        @dragleave.prevent="dragOver = false"
        @drop.prevent="onDrop"
      >
        <input
          ref="fileInputEl"
          type="file"
          accept=".pdf,.doc,.docx"
          multiple
          class="adm-drop__input"
          @change="onInputChange"
        />

        <div class="adm-drop__icon-ring" :class="{ 'adm-drop__icon-ring--active': dragOver }">
          <v-icon
            :icon="dragOver ? 'mdi-tray-arrow-down' : 'mdi-cloud-upload-outline'"
            size="40"
          />
        </div>

        <p class="adm-drop__title">
          {{ dragOver ? 'ปล่อยไฟล์เพื่ออัปโหลด' : 'ลากไฟล์มาวางที่นี่' }}
        </p>
        <p class="adm-drop__sub">รองรับ PDF, DOCX และ DOC — เลือกได้หลายไฟล์พร้อมกัน</p>

        <div class="adm-drop__or"><span>หรือ</span></div>

        <button
          type="button"
          class="adm-btn adm-btn--primary adm-drop__cta"
          @click.stop="fileInputEl?.click()"
        >
          <v-icon icon="mdi-folder-open-outline" size="18" />
          เลือกไฟล์จากเครื่อง
        </button>

        <div class="adm-ftypes">
          <span class="adm-ftype adm-ftype--pdf">PDF</span>
          <span class="adm-ftype adm-ftype--docx">DOCX</span>
          <span class="adm-ftype adm-ftype--doc">DOC</span>
        </div>
      </div>

      <!-- ── Pending bar ─────────────────────────────────── -->
      <div v-if="pendingItems.length && !uploadDialog" class="adm-review-bar">
        <v-icon icon="mdi-file-multiple-outline" size="18" color="admin-primary" />
        <span class="adm-review-bar__text">เลือกไว้ {{ pendingItems.length }} ไฟล์</span>
        <v-spacer />
        <button type="button" class="adm-btn adm-btn--primary" @click="uploadDialog = true">
          <v-icon icon="mdi-upload-outline" size="17" />
          ตรวจสอบและอัปโหลด
        </button>
      </div>

      <!-- ── Upload dialog ──────────────────────────────── -->
      <v-dialog v-model="uploadDialog" max-width="640" persistent scrollable>
        <v-card rounded="xl">

          <!-- Dialog header -->
          <div class="adm-dlg-head">
            <v-icon icon="mdi-file-document-multiple-outline" color="admin-primary" size="22" />
            <div class="flex-1">
              <div class="adm-dlg-head__title">รายการไฟล์</div>
              <div class="adm-dlg-head__sub">{{ pendingItems.length }} ไฟล์ที่เลือก</div>
            </div>
            <button type="button" class="adm-btn-sm adm-btn-sm--outline" @click="fileInputEl?.click()">
              <v-icon icon="mdi-plus" size="15" />
              เพิ่มไฟล์
            </button>
          </div>

          <v-divider />

          <v-card-text class="px-5 py-3">
            <div class="adm-file-list">
              <div v-for="(item, i) in pendingItems" :key="i" class="adm-file-item">

                <!-- File row -->
                <div class="adm-file-row">
                  <div
                    class="adm-file-icon"
                    :class="isPdf(item.file) ? 'adm-file-icon--pdf' : 'adm-file-icon--doc'"
                  >
                    <v-icon :icon="iconFor(item.file)" size="22" />
                  </div>
                  <div class="adm-file-row__body">
                    <span class="adm-file-row__name">{{ item.file.name }}</span>
                    <span class="adm-file-row__meta">
                      {{ isPdf(item.file) ? 'PDF' : 'Word' }} · {{ sizeOf(item.file) }}
                    </span>
                  </div>
                  <div class="adm-file-row__end">
                    <v-chip v-if="item.done" color="success" size="x-small" variant="tonal" rounded="pill">
                      <v-icon start icon="mdi-check" size="11" />อัปโหลดแล้ว
                    </v-chip>
                    <v-progress-circular
                      v-else-if="item.uploading"
                      indeterminate
                      size="20"
                      width="2"
                      color="admin-primary"
                    />
                    <button
                      v-if="!item.uploading && !item.done"
                      type="button"
                      class="adm-remove-btn"
                      title="ลบออก"
                      @click="removeItem(i)"
                    >
                      <v-icon icon="mdi-close" size="15" />
                    </button>
                  </div>
                </div>

                <!-- OCR mode row -->
                <div v-if="!item.done" class="adm-file-ocr">
                  <v-icon icon="mdi-cog-outline" size="14" color="grey-darken-1" />
                  <span class="adm-label-sm">โหมดสกัด:</span>
                  <v-select
                    v-model="item.scanMode"
                    :items="modeOptionsFor(item.file)"
                    item-title="title"
                    item-value="value"
                    density="compact"
                    variant="outlined"
                    hide-details
                    rounded="lg"
                    style="flex:1;max-width:380px"
                  />
                  <v-chip v-if="hintFor(item)" size="x-small" color="warning" variant="tonal" rounded="pill">
                    <v-icon start icon="mdi-key-outline" size="10" />
                    {{ hintFor(item) }}
                  </v-chip>
                </div>

                <v-alert v-if="item.error" type="error" density="compact" rounded="lg" class="mt-2 mx-4 mb-3">
                  {{ item.error }}
                </v-alert>
              </div>

              <div v-if="!pendingItems.length" class="adm-empty">
                <v-icon icon="mdi-inbox-outline" size="36" color="grey-lighten-1" />
                <p>ยังไม่มีไฟล์ที่เลือก</p>
              </div>
            </div>
          </v-card-text>

          <v-divider />

          <!-- Actions -->
          <v-card-actions class="pa-4 ga-2 justify-end">
            <button
              type="button"
              class="adm-btn adm-btn--ghost"
              :disabled="isUploading"
              @click="uploadDialog = false"
            >
              ปิด
            </button>
            <button
              type="button"
              class="adm-btn adm-btn--ghost"
              :disabled="isUploading || !pendingItems.length"
              @click="clearAll"
            >
              ล้างทั้งหมด
            </button>
            <button
              type="button"
              class="adm-btn adm-btn--primary"
              :disabled="isUploading || allDone || !pendingCount"
              @click="uploadAll"
            >
              <v-icon icon="mdi-cloud-upload-outline" size="17" />
              อัปโหลด {{ pendingCount }} ไฟล์
            </button>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- ── Queue table ────────────────────────────────── -->
      <DocumentPipelineTable ref="pipelineTable" class="mt-4" />
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import Swal from 'sweetalert2';
import AppShell from '../../components/shared/AppShell.vue';
import DocumentPipelineTable from '../../components/admin/DocumentPipelineTable.vue';
import type { ScanExtractionMode } from '../../types/document';
import { useUploadStore } from '../../stores/uploadStore';
import { useSnackbarStore } from '../../stores/snackbarStore';

interface PendingItem {
  file: File;
  scanMode: ScanExtractionMode;
  uploading: boolean;
  done: boolean;
  error: string;
}

const uploadStore = useUploadStore();
const snackbar = useSnackbarStore();

const fileInputEl = ref<HTMLInputElement | null>(null);
const pendingItems = ref<PendingItem[]>([]);
const dragOver = ref(false);
const uploadDialog = ref(false);
const pipelineTable = ref<InstanceType<typeof DocumentPipelineTable> | null>(null);

const isUploading = computed(() => pendingItems.value.some(i => i.uploading));
const allDone = computed(() => pendingItems.value.length > 0 && pendingItems.value.every(i => i.done));
const pendingCount = computed(() => pendingItems.value.filter(i => !i.done).length);

function isPdf(file: File): boolean {
  return file.name.split('.').pop()?.toLowerCase() === 'pdf';
}

function defaultMode(file: File): ScanExtractionMode {
  return isPdf(file) ? 'gemini' : 'local';
}

function iconFor(file: File): string {
  return isPdf(file) ? 'mdi-file-pdf-box' : 'mdi-file-word-box';
}

function sizeOf(file: File): string {
  const b = file.size;
  return b < 1024 * 1024 ? `${(b / 1024).toFixed(0)} KB` : `${(b / (1024 * 1024)).toFixed(1)} MB`;
}

function modeOptionsFor(file: File) {
  return isPdf(file)
    ? [
        { title: 'Gemini Vision (แนะนำสำหรับ PDF scan)', value: 'gemini' },
      ]
    : [
        { title: 'Fast PHP extraction (แนะนำ)', value: 'local' },
      ];
}

function hintFor(item: PendingItem): string {
  if (isPdf(item.file) && item.scanMode === 'gemini') return 'ต้องตั้งค่า GEMINI_API_KEY';
  return '';
}

function engineFor(item: PendingItem): 'fast' | 'standard' {
  return isPdf(item.file) ? 'standard' : 'fast';
}

function addFiles(files: FileList | File[]): void {
  for (const file of Array.from(files)) {
    const dup = pendingItems.value.some(i => i.file.name === file.name && i.file.size === file.size);
    if (!dup) {
      pendingItems.value.push({ file, scanMode: defaultMode(file), uploading: false, done: false, error: '' });
    }
  }
}

async function removeItem(index: number): Promise<void> {
  const item = pendingItems.value[index];
  if (!item) return;

  const confirmed = await Swal.fire({
    icon: 'warning',
    title: 'ลบไฟล์นี้ออก?',
    text: item.file.name,
    showCancelButton: true,
    confirmButtonText: 'ลบ',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#b42318',
    cancelButtonColor: '#64748b',
  });
  if (!confirmed.isConfirmed) return;

  pendingItems.value.splice(index, 1);
}

async function clearAll(): Promise<void> {
  const confirmed = await Swal.fire({
    icon: 'warning',
    title: 'ล้างรายการทั้งหมด?',
    text: `ลบไฟล์ที่เลือกไว้ ${pendingItems.value.length} ไฟล์ออกจากรายการอัปโหลด`,
    showCancelButton: true,
    confirmButtonText: 'ล้างทั้งหมด',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#b42318',
    cancelButtonColor: '#64748b',
  });
  if (!confirmed.isConfirmed) return;

  pendingItems.value = [];
}

function onInputChange(event: Event): void {
  const input = event.target as HTMLInputElement;
  if (input.files?.length) addFiles(input.files);
  input.value = '';
  if (pendingItems.value.length) uploadDialog.value = true;
}

function onDrop(event: DragEvent): void {
  dragOver.value = false;
  if (event.dataTransfer?.files.length) addFiles(event.dataTransfer.files);
  if (pendingItems.value.length) uploadDialog.value = true;
}

async function uploadAll(): Promise<void> {
  const toUpload = pendingItems.value.filter(i => !i.done && !i.uploading);
  if (!toUpload.length) return;

  await Promise.all(
    toUpload.map(async item => {
      item.uploading = true;
      item.error = '';
      try {
        await uploadStore.upload(item.file, item.scanMode, engineFor(item));
        item.done = true;
      } catch (err) {
        item.error = err instanceof Error ? err.message : 'อัปโหลดไม่สำเร็จ';
        snackbar.error(`${item.file.name}: ${item.error}`);
      } finally {
        item.uploading = false;
      }
    }),
  );

  await pipelineTable.value?.load();

  const failed = pendingItems.value.filter(i => i.error).length;
  if (!failed) {
    snackbar.success?.(`อัปโหลดสำเร็จ ${toUpload.length} ไฟล์`);
    pendingItems.value = [];
    uploadDialog.value = false;
  }
}
</script>

<style scoped>
.adm-up {
  max-width: 860px;
  margin: 0 auto;
}

/* ── Drop zone ──────────────────────────────────────────── */
.adm-drop {
  position: relative;
  background: #f5f7ff;
  border: 2px dashed #c7d2fe;
  border-radius: 20px;
  padding: 56px 40px 48px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  transition: border-color 0.18s, background 0.18s, transform 0.18s;
}

.adm-drop--over {
  border-color: rgb(var(--v-theme-admin-primary));
  background: rgba(var(--v-theme-admin-primary), 0.05);
  transform: scale(1.005);
}

.adm-drop__input {
  position: absolute;
  inset: 0;
  opacity: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
}

/* Icon ring */
.adm-drop__icon-ring {
  width: 88px;
  height: 88px;
  border-radius: 50%;
  background: #e0e7ff;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 24px;
  transition: background 0.18s;
}

.adm-drop__icon-ring :deep(.v-icon) {
  color: rgb(var(--v-theme-admin-primary)) !important;
  transition: color 0.18s;
}

.adm-drop__icon-ring--active {
  background: rgba(var(--v-theme-admin-primary), 0.18);
}

/* Text */
.adm-drop__title {
  font-size: 22px;
  font-weight: 700;
  color: #1e293b;
  margin: 0 0 8px;
  transition: color 0.18s;
}

.adm-drop--over .adm-drop__title {
  color: rgb(var(--v-theme-admin-primary));
}

.adm-drop__sub {
  font-size: 15px;
  color: #64748b;
  margin: 0 0 24px;
}

/* หรือ divider */
.adm-drop__or {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  max-width: 280px;
  margin-bottom: 20px;
  color: #94a3b8;
  font-size: 13px;
}

.adm-drop__or::before,
.adm-drop__or::after {
  content: '';
  flex: 1;
  height: 1px;
  background: #e2e8f0;
}

/* CTA button - on top of hidden input */
.adm-drop__cta {
  position: relative;
  z-index: 1;
  margin-bottom: 28px;
}

/* File type badges */
.adm-ftypes {
  display: flex;
  gap: 8px;
  position: relative;
  z-index: 1;
}

.adm-ftype {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  padding: 3px 10px;
  border-radius: 20px;
  border: 1px solid;
}

.adm-ftype--pdf  { color: #dc2626; border-color: #fca5a5; background: #fff1f2; }
.adm-ftype--docx { color: #2563eb; border-color: #93c5fd; background: #eff6ff; }
.adm-ftype--doc  { color: #7c3aed; border-color: #c4b5fd; background: #f5f3ff; }

/* ── Pending bar ────────────────────────────────────────── */
.adm-review-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 14px;
  padding: 12px 18px;
  background: #eef2ff;
  border: 1px solid #c7d2fe;
  border-radius: 12px;
}

.adm-review-bar__text {
  font-weight: 600;
  color: #1e3a8a;
}

/* ── Dialog header ──────────────────────────────────────── */
.adm-dlg-head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px 20px 16px;
}

.adm-dlg-head__title {
  font-size: 16px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1.2;
}

.adm-dlg-head__sub {
  font-size: 13px;
  color: #64748b;
  margin-top: 1px;
}

/* ── File list ──────────────────────────────────────────── */
.adm-file-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.adm-file-item {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  overflow: hidden;
  background: #ffffff;
}

/* File row - light */
.adm-file-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  background: #ffffff;
}

.adm-file-icon {
  width: 42px;
  height: 42px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.adm-file-icon--pdf {
  background: #fff1f2;
}

.adm-file-icon--pdf :deep(.v-icon) {
  color: #dc2626 !important;
}

.adm-file-icon--doc {
  background: #eff6ff;
}

.adm-file-icon--doc :deep(.v-icon) {
  color: #2563eb !important;
}

.adm-file-row__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.adm-file-row__name {
  font-size: 14px;
  font-weight: 600;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.adm-file-row__meta {
  font-size: 12px;
  color: #94a3b8;
}

.adm-file-row__end {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

/* Remove button */
.adm-remove-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: #f1f5f9;
  border: none;
  color: #94a3b8;
  cursor: pointer;
  transition: background 0.12s, color 0.12s;
}

.adm-remove-btn:hover {
  background: #fee2e2;
  color: #dc2626;
}

/* ── OCR selector row ───────────────────────────────────── */
.adm-file-ocr {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  background: #f8fafc;
  border-top: 1px solid #f0f0f0;
  flex-wrap: wrap;
}

.adm-label-sm {
  font-size: 13px;
  font-weight: 600;
  color: #475569;
  white-space: nowrap;
}

/* ── Empty state ────────────────────────────────────────── */
.adm-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 40px 20px;
  color: #94a3b8;
  font-size: 14px;
}

/* ── Buttons ────────────────────────────────────────────── */
.adm-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border: none;
  cursor: pointer;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 17px;
  font-weight: 700;
  border-radius: 10px;
  padding: 10px 22px;
  transition: opacity 0.15s, background 0.15s;
  white-space: nowrap;
}

.adm-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.adm-btn--primary {
  background: rgb(var(--v-theme-admin-primary));
  color: #ffffff;
}

.adm-btn--primary:hover:not(:disabled) {
  opacity: 0.88;
}

.adm-btn--ghost {
  background: transparent;
  border: 1.5px solid #d1d5db;
  color: #374151;
}

.adm-btn--ghost:hover:not(:disabled) {
  background: #f9fafb;
}

.adm-btn-sm {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  cursor: pointer;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 14px;
  font-weight: 700;
  border-radius: 8px;
  padding: 6px 14px;
  transition: background 0.12s;
}

.adm-btn-sm--outline {
  background: transparent;
  border: 1.5px solid #d1d5db;
  color: #374151;
}

.adm-btn-sm--outline:hover {
  background: #f3f4f6;
}
</style>
