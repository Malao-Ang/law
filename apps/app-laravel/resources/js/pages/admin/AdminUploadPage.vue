<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล']"
    title="การนำเข้าเอกสารกฎหมาย"
    subtitle="อัปโหลดไฟล์เพื่อเตรียมสกัดเนื้อหาเข้าสู่ระบบฐานข้อมูล"
    show-bell
  >
    <div class="adm-up">

      <!-- ── Step 0: Drop zone ──────────────────────────── -->
      <template v-if="!pendingItems.length">
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
          <div class="adm-drop__icon">
            <v-icon icon="mdi-cloud-upload-outline" size="30" color="white" />
          </div>
          <p class="adm-drop__title">ลากและวางไฟล์ข้อมูลกฎหมาย</p>
          <p class="adm-drop__sub">
            รองรับไฟล์ .PDF หรือ .DOCX (เลือกได้หลายไฟล์พร้อมกัน)
          </p>
          <div class="adm-drop__btns">
            <button type="button" class="adm-btn adm-btn--primary" @click="fileInputEl?.click()">
              เลือกไฟล์จากเครื่อง
            </button>
          </div>
        </div>
      </template>

      <!-- ── Step 1: Files pending ──────────────────────── -->
      <template v-else>
        <!-- Card: file list -->
        <div class="adm-card mb-4">
          <div class="adm-card__head-row">
            <h3 class="adm-card__head">ไฟล์เอกสาร ({{ pendingItems.length }} ไฟล์)</h3>
            <button type="button" class="adm-btn-sm adm-btn-sm--outline" @click="fileInputEl?.click()">
              <v-icon icon="mdi-plus" size="14" />
              เพิ่มไฟล์
            </button>
          </div>

          <input
            ref="fileInputEl"
            type="file"
            accept=".pdf,.doc,.docx"
            multiple
            class="adm-drop__input"
            style="position:absolute;opacity:0;pointer-events:none;width:1px;height:1px"
            @change="onInputChange"
          />

          <div class="adm-file-list">
            <div v-for="(item, i) in pendingItems" :key="i" class="adm-file-item">
              <!-- navy row -->
              <div class="adm-file-row">
                <div class="adm-file-row__icon">
                  <v-icon :icon="iconFor(item.file)" size="20" color="white" />
                </div>
                <div class="adm-file-row__body">
                  <span class="adm-file-row__name">{{ item.file.name }}</span>
                  <span class="adm-file-row__meta">{{ sizeOf(item.file) }}</span>
                </div>
                <v-chip
                  v-if="item.done"
                  color="success"
                  size="small"
                  variant="flat"
                  class="mr-2"
                >
                  <v-icon start icon="mdi-check" size="13" />อัปโหลดแล้ว
                </v-chip>
                <v-progress-circular
                  v-else-if="item.uploading"
                  indeterminate
                  size="18"
                  width="2"
                  color="white"
                  class="mr-2"
                />
                <button
                  v-if="!item.uploading && !item.done"
                  type="button"
                  class="adm-file-row__remove"
                  @click="removeItem(i)"
                >
                  <v-icon icon="mdi-close" size="16" />
                </button>
              </div>

              <!-- OCR selector row -->
              <div v-if="!item.done" class="adm-file-ocr">
                <span class="adm-label-sm">โหมด:</span>
                <v-select
                  v-model="item.scanMode"
                  :items="modeOptionsFor(item.file)"
                  item-title="title"
                  item-value="value"
                  density="compact"
                  variant="outlined"
                  hide-details
                  rounded="lg"
                  style="flex:1;max-width:420px"
                />
                <span v-if="hintFor(item)" class="adm-hint">{{ hintFor(item) }}</span>
              </div>

              <v-alert
                v-if="item.error"
                type="error"
                density="compact"
                rounded="lg"
                class="mt-2"
              >
                {{ item.error }}
              </v-alert>
            </div>
          </div>
        </div>

        <!-- Card: Upload action -->
        <div class="adm-card mb-4">
          <div class="adm-warn mb-4">
            <v-icon icon="mdi-information-outline" size="18" color="#1d4ed8" class="flex-shrink-0" style="margin-top:2px" />
            <div>
              <p class="adm-warn__title mb-0" style="color:#1e3a8a">
                หลังอัปโหลดสำเร็จ สามารถคลิกเปิดเอกสารในตารางด้านล่างเพื่อตรวจสอบและแก้ไขได้ทันที
              </p>
            </div>
          </div>

          <div class="d-flex gap-3 align-center">
            <button
              type="button"
              class="adm-btn adm-btn--primary"
              :disabled="isUploading || allDone"
              @click="confirmDialog = true"
            >
              <v-icon icon="mdi-cloud-upload-outline" size="16" class="mr-1" />
              {{ `อัปโหลด ${pendingCount} ไฟล์` }}
            </button>

            <button
              type="button"
              class="adm-btn adm-btn--ghost"
              :disabled="isUploading"
              @click="clearAll"
            >
              ยกเลิก
            </button>
          </div>
        </div>
      </template>

      <!-- ── Confirm upload dialog ──────────────────────── -->
      <v-dialog v-model="confirmDialog" max-width="520" persistent>
        <v-card rounded="xl">
          <v-card-title class="pa-5 pb-2 d-flex align-center gap-2">
            <v-icon icon="mdi-cloud-upload-outline" color="admin-primary" />
            <span style="font-family:'TH Sarabun New','Sarabun',sans-serif;font-size:20px;font-weight:700">
              ยืนยันการอัปโหลด
            </span>
          </v-card-title>

          <v-card-text class="px-5 pb-3">
            <p class="mb-3" style="font-family:'TH Sarabun New','Sarabun',sans-serif;font-size:16px;color:#374151">
              ระบบจะอัปโหลดและเริ่มประมวลผลเอกสารต่อไปนี้:
            </p>
            <div class="confirm-file-list">
              <div
                v-for="(item, i) in pendingItems.filter(i => !i.done)"
                :key="i"
                class="confirm-file-row"
              >
                <v-icon :icon="iconFor(item.file)" size="18" color="admin-primary" />
                <div class="confirm-file-row__body">
                  <span class="confirm-file-row__name">{{ item.file.name }}</span>
                  <span class="confirm-file-row__meta">{{ sizeOf(item.file) }} • {{ modeLabel(item.scanMode) }}</span>
                </div>
              </div>
            </div>
          </v-card-text>

          <v-divider />
          <v-card-actions class="pa-4 gap-2 justify-end">
            <button type="button" class="adm-btn adm-btn--ghost" style="font-size:15px;padding:8px 20px" @click="confirmDialog = false">
              ยกเลิก
            </button>
            <button type="button" class="adm-btn adm-btn--primary" style="font-size:15px;padding:8px 20px" @click="confirmAndUpload">
              <v-icon icon="mdi-check" size="16" class="mr-1" />
              ยืนยันอัปโหลด
            </button>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- ── Queue table (always visible) ──────────────── -->
      <DocumentPipelineTable ref="pipelineTable" class="mt-2" />
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
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
const confirmDialog = ref(false);
const pipelineTable = ref<InstanceType<typeof DocumentPipelineTable> | null>(null);

const isUploading = computed(() => pendingItems.value.some(i => i.uploading));
const allDone = computed(() => pendingItems.value.length > 0 && pendingItems.value.every(i => i.done));
const pendingCount = computed(() => pendingItems.value.filter(i => !i.done).length);

function defaultMode(file: File): ScanExtractionMode {
  return file.name.split('.').pop()?.toLowerCase() === 'pdf' ? 'gemini' : 'local';
}

function iconFor(file: File): string {
  return file.name.split('.').pop()?.toLowerCase() === 'pdf' ? 'mdi-file-pdf-box' : 'mdi-file-word-box';
}

function sizeOf(file: File): string {
  const b = file.size;
  return b < 1024 * 1024 ? `${(b / 1024).toFixed(0)} KB` : `${(b / (1024 * 1024)).toFixed(1)} MB`;
}

function modeOptionsFor(file: File) {
  const isPdf = file.name.split('.').pop()?.toLowerCase() === 'pdf';
  return isPdf
    ? [
        { title: 'Gemini Vision (แนะนำสำหรับ PDF scan)', value: 'gemini' },
        { title: 'Auto — EasyOCR → cloud fallback', value: 'auto' },
        { title: 'LandingAI — ADE Parse', value: 'landingai' },
        { title: 'Local — EasyOCR ในเครื่อง', value: 'local' },
      ]
    : [
        { title: 'Local — Fast PHP extraction (แนะนำ)', value: 'local' },
        { title: 'Standard — Python Docling', value: 'auto' },
      ];
}

function modeLabel(mode: ScanExtractionMode): string {
  const map: Record<ScanExtractionMode, string> = {
    gemini: 'Gemini Vision', auto: 'Auto OCR', landingai: 'LandingAI', local: 'Local',
  };
  return map[mode] ?? mode;
}

function hintFor(item: PendingItem): string {
  const isPdf = item.file.name.split('.').pop()?.toLowerCase() === 'pdf';
  if (isPdf && item.scanMode === 'gemini') return 'ต้องตั้งค่า GEMINI_API_KEY';
  if (isPdf && item.scanMode === 'landingai') return 'ต้องตั้งค่า VISION_AGENT_API_KEY';
  return '';
}

function engineFor(item: PendingItem): 'fast' | 'standard' {
  const isPdf = item.file.name.split('.').pop()?.toLowerCase() === 'pdf';
  if (isPdf && item.scanMode !== 'auto') return 'standard';
  return item.scanMode === 'local' ? 'fast' : 'standard';
}

function addFiles(files: FileList | File[]): void {
  for (const file of Array.from(files)) {
    const dup = pendingItems.value.some(
      i => i.file.name === file.name && i.file.size === file.size,
    );
    if (!dup) {
      pendingItems.value.push({
        file,
        scanMode: defaultMode(file),
        uploading: false,
        done: false,
        error: '',
      });
    }
  }
}

function removeItem(index: number): void {
  pendingItems.value.splice(index, 1);
}

function clearAll(): void {
  pendingItems.value = [];
}

function onInputChange(event: Event): void {
  const input = event.target as HTMLInputElement;
  if (input.files?.length) addFiles(input.files);
  input.value = '';
}

function onDrop(event: DragEvent): void {
  dragOver.value = false;
  if (event.dataTransfer?.files.length) addFiles(event.dataTransfer.files);
}

async function confirmAndUpload(): Promise<void> {
  confirmDialog.value = false;
  await uploadAll();
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
  background: #ffffff;
  border: 1.5px dashed #d1d5db;
  border-radius: 16px;
  padding: 52px 40px 44px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
}

.adm-drop--over {
  border-color: rgb(var(--v-theme-admin-primary));
  background: rgba(var(--v-theme-admin-primary), 0.03);
}

.adm-drop__input {
  position: absolute;
  inset: 0;
  opacity: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
}

.adm-drop__icon {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: #dbeafe;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 20px;
}

.adm-drop__icon :deep(.v-icon) {
  color: rgb(var(--v-theme-admin-primary)) !important;
}

.adm-drop__title {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 22px;
  font-weight: 700;
  color: rgb(var(--v-theme-admin-primary));
  margin: 0 0 10px;
}

.adm-drop__sub {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px;
  color: #6b7280;
  margin: 0 0 28px;
  line-height: 1.6;
}

.adm-drop__btns {
  display: flex;
  gap: 12px;
  align-items: center;
  position: relative;
  z-index: 1;
}

/* ── Cards ──────────────────────────────────────────────── */
.adm-card {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 24px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.adm-card__head-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.adm-card__head {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 18px;
  font-weight: 700;
  color: #1e293b;
  margin: 0;
}

/* ── File list ──────────────────────────────────────────── */
.adm-file-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.adm-file-item {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  overflow: hidden;
}

/* ── File row (navy) ────────────────────────────────────── */
.adm-file-row {
  display: flex;
  align-items: center;
  gap: 12px;
  background: rgb(var(--v-theme-admin-primary));
  padding: 12px 16px;
}

.adm-file-row__icon {
  width: 36px;
  height: 36px;
  border-radius: 7px;
  background: rgba(255, 255, 255, 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.adm-file-row__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.adm-file-row__name {
  font-family: 'Sarabun', sans-serif;
  font-size: 14px;
  font-weight: 700;
  color: #ffffff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.adm-file-row__meta {
  font-family: 'Sarabun', sans-serif;
  font-size: 12px;
  color: rgba(255, 255, 255, 0.65);
}

.adm-file-row__remove {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.25);
  color: rgba(255, 255, 255, 0.8);
  cursor: pointer;
  flex-shrink: 0;
  transition: background 0.12s;
}

.adm-file-row__remove:hover {
  background: rgba(255, 80, 80, 0.35);
}

/* ── OCR selector row ───────────────────────────────────── */
.adm-file-ocr {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: #f9fafb;
  flex-wrap: wrap;
}

.adm-label-sm {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 14px;
  font-weight: 700;
  color: #374151;
  white-space: nowrap;
}

.adm-hint {
  font-family: 'Sarabun', sans-serif;
  font-size: 12px;
  color: #9a7840;
}

/* ── Warning / info box ─────────────────────────────────── */
.adm-warn {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 10px;
  padding: 14px 16px;
}

.adm-warn__title {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 16px;
  font-weight: 700;
}

/* ── Buttons ────────────────────────────────────────────── */
.adm-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border: none;
  cursor: pointer;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 17px;
  font-weight: 700;
  border-radius: 10px;
  padding: 11px 24px;
  transition: opacity 0.15s, background 0.15s;
  white-space: nowrap;
}

.adm-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.adm-btn--primary {
  background: rgb(var(--v-theme-admin-primary));
  color: #ffffff;
}

.adm-btn--primary:hover:not(:disabled) {
  opacity: 0.9;
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
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
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

/* gap utility for flex */
.gap-3 { gap: 12px; }

/* ── Confirm dialog file list ───────────────────────────── */
.confirm-file-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 260px;
  overflow-y: auto;
}

.confirm-file-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: #f8fafc;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}

.confirm-file-row__body {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.confirm-file-row__name {
  font-family: 'Sarabun', sans-serif;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.confirm-file-row__meta {
  font-family: 'Sarabun', sans-serif;
  font-size: 12px;
  color: #6b7280;
}
</style>
