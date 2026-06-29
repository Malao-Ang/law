<template>
  <div class="compose-meta-panel">
    <div class="compose-meta-tabs">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="compose-meta-tab"
        :class="{ 'compose-meta-tab--active': activeTab === tab.key }"
        type="button"
        @click="activeTab = tab.key"
      >
        <span class="mdi" :class="tab.icon" aria-hidden="true"></span>
        {{ tab.label }}
      </button>
    </div>

    <div v-if="activeTab === 'info'" class="compose-meta-content">
      <div class="compose-meta-save">
        <span
          class="compose-meta-save__indicator"
          :class="`compose-meta-save__indicator--${saveIndicatorState}`"
        ></span>
        <span class="compose-meta-save__label">{{ saveMessage }}</span>
      </div>

      <div class="compose-meta-section">
        <p class="compose-meta-section__label">หนังสือราชการ</p>

        <label class="compose-meta-field">
          <span class="compose-meta-field__label">ส่วนราชการ</span>
          <input
            class="compose-meta-field__input"
            type="text"
            :value="modelValue.department"
            @input="patch('department', ($event.target as HTMLInputElement).value)"
          >
        </label>

        <label class="compose-meta-field">
          <span class="compose-meta-field__label">เลขที่หนังสือ</span>
          <input
            class="compose-meta-field__input"
            type="text"
            :value="modelValue.doc_number"
            @input="patch('doc_number', ($event.target as HTMLInputElement).value)"
          >
        </label>

        <label class="compose-meta-field">
          <span class="compose-meta-field__label">วันที่</span>
          <input
            class="compose-meta-field__input"
            type="text"
            :value="modelValue.date"
            @input="patch('date', ($event.target as HTMLInputElement).value)"
          >
        </label>

        <label class="compose-meta-field">
          <span class="compose-meta-field__label">เรื่อง</span>
          <textarea
            class="compose-meta-field__input compose-meta-field__input--textarea"
            rows="2"
            :value="modelValue.subject"
            @input="patch('subject', ($event.target as HTMLTextAreaElement).value)"
          ></textarea>
        </label>

        <label class="compose-meta-field">
          <span class="compose-meta-field__label">เรียน</span>
          <input
            class="compose-meta-field__input"
            type="text"
            :value="modelValue.recipient"
            @input="patch('recipient', ($event.target as HTMLInputElement).value)"
          >
        </label>
      </div>

      <div class="compose-meta-section">
        <p class="compose-meta-section__label">อ้างอิง</p>

        <label class="compose-meta-field">
          <span class="compose-meta-field__label">อ้างถึง</span>
          <input
            class="compose-meta-field__input"
            type="text"
            :value="modelValue.reference"
            @input="patch('reference', ($event.target as HTMLInputElement).value)"
          >
        </label>

        <label class="compose-meta-field">
          <span class="compose-meta-field__label">สิ่งที่ส่งมาด้วย</span>
          <input
            class="compose-meta-field__input"
            type="text"
            :value="modelValue.attachments"
            @input="patch('attachments', ($event.target as HTMLInputElement).value)"
          >
        </label>
      </div>

      <div class="compose-meta-section">
        <p class="compose-meta-section__label">ชั้นความ</p>

        <label class="compose-meta-field">
          <span class="compose-meta-field__label">ชั้นความเร็ว</span>
          <select
            class="compose-meta-field__input"
            :value="modelValue.urgency"
            @change="patch('urgency', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">ปกติ</option>
            <option value="ด่วน">ด่วน</option>
            <option value="ด่วนที่สุด">ด่วนที่สุด</option>
            <option value="ด่วนมาก">ด่วนมาก</option>
          </select>
        </label>

        <label class="compose-meta-field">
          <span class="compose-meta-field__label">ชั้นความลับ</span>
          <select
            class="compose-meta-field__input"
            :value="modelValue.confidentiality"
            @change="patch('confidentiality', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">ไม่ลับ</option>
            <option value="ลับ">ลับ</option>
            <option value="ลับมาก">ลับมาก</option>
            <option value="ลับที่สุด">ลับที่สุด</option>
          </select>
        </label>
      </div>

      <div class="compose-meta-section">
        <p class="compose-meta-section__label">ผู้ลงนาม</p>

        <label class="compose-meta-field">
          <span class="compose-meta-field__label">ชื่อ-สกุล</span>
          <input
            class="compose-meta-field__input"
            type="text"
            :value="modelValue.signatory_name"
            @input="patch('signatory_name', ($event.target as HTMLInputElement).value)"
          >
        </label>

        <label class="compose-meta-field">
          <span class="compose-meta-field__label">ตำแหน่ง</span>
          <input
            class="compose-meta-field__input"
            type="text"
            :value="modelValue.signatory_position"
            @input="patch('signatory_position', ($event.target as HTMLInputElement).value)"
          >
        </label>
      </div>
    </div>

    <div v-else-if="activeTab === 'timeline'" class="compose-meta-content">
      <div class="compose-meta-timeline">
        <div v-if="review" class="compose-meta-timeline__item">
          <span class="mdi mdi-check-circle-outline compose-meta-timeline__icon compose-meta-timeline__icon--done"></span>
          <div>
            <div class="compose-meta-timeline__label">อัปเดตล่าสุด</div>
            <div class="compose-meta-timeline__value">{{ review.document_review.updated_at ?? '—' }}</div>
          </div>
        </div>
        <div v-if="review" class="compose-meta-timeline__item">
          <span class="mdi mdi-account-check-outline compose-meta-timeline__icon"></span>
          <div>
            <div class="compose-meta-timeline__label">อนุมัติโดย</div>
            <div class="compose-meta-timeline__value">{{ review.document_review.approved_by ?? 'ยังไม่อนุมัติ' }}</div>
          </div>
        </div>
        <p v-else class="compose-meta-empty">ยังไม่มีข้อมูล Timeline</p>
      </div>
    </div>

    <div v-else class="compose-meta-content">
      <div class="compose-meta-actions">
        <p class="compose-meta-actions__hint">ดำเนินการกับเอกสารทั้งฉบับ</p>
        <button
          class="compose-meta-action-btn"
          type="button"
          :disabled="exportDisabled || exportBusy"
          @click="$emit('export')"
        >
          <span class="mdi mdi-download-outline"></span>
          ส่งออก RAG JSON
        </button>
        <p v-if="exportDisabled" class="compose-meta-actions__note">
          รอ AI correction ให้เสร็จก่อนจึงจะส่งออกได้
        </p>
        <button class="compose-meta-action-btn compose-meta-action-btn--secondary" type="button" @click="$emit('reload')">
          <span class="mdi mdi-refresh"></span>
          โหลดเอกสารใหม่
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import type { DocumentMetadata, ReviewDocument } from '../../types/document';

const props = defineProps<{
  modelValue: DocumentMetadata;
  review: ReviewDocument | null;
  documentId: string;
  saveMessage: string;
  correctionStatus?: 'not_required' | 'pending' | 'in_progress' | 'done' | 'failed' | null;
  exportBusy?: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [DocumentMetadata];
  reload: [];
  export: [];
}>();

const activeTab = ref<'info' | 'timeline' | 'actions'>('info');
const tabs = [
  { key: 'info', label: 'ข้อมูล', icon: 'mdi-file-document-outline' },
  { key: 'timeline', label: 'Timeline', icon: 'mdi-timeline-outline' },
  { key: 'actions', label: 'ดำเนินการ', icon: 'mdi-cog-outline' },
] as const;

const saveIndicatorState = computed<'idle' | 'saving' | 'saved' | 'error'>(() => {
  if (props.saveMessage.includes('กำลัง')) return 'saving';
  if (props.saveMessage.includes('ไม่สำเร็จ') || props.saveMessage.includes('error')) return 'error';
  if (props.saveMessage.includes('บันทึกอัตโนมัติแล้ว')) return 'saved';
  return 'idle';
});

const exportDisabled = computed(() =>
  ['pending', 'in_progress', 'failed'].includes(props.correctionStatus ?? ''),
);

function patch(key: keyof DocumentMetadata, value: string): void {
  emit('update:modelValue', { ...props.modelValue, [key]: value });
}
</script>

<style scoped>
.compose-meta-panel {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.compose-meta-tabs {
  display: flex;
  border-bottom: 1px solid var(--law-border);
  flex-shrink: 0;
  background: #fff;
}

.compose-meta-tab {
  flex: 1;
  padding: 10px 4px;
  border: none;
  background: none;
  font-size: 12px;
  font-weight: 600;
  color: var(--elaw-muted);
  cursor: pointer;
  font-family: inherit;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 3px;
  border-bottom: 2px solid transparent;
  transition: color 0.12s, border-color 0.12s;
}

.compose-meta-tab .mdi {
  font-size: 16px;
}

.compose-meta-tab--active {
  color: var(--law-primary);
  border-bottom-color: var(--law-primary);
}

.compose-meta-tab:hover {
  color: var(--law-primary);
  background: var(--law-primary-soft);
}

.compose-meta-content {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.compose-meta-save {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: var(--elaw-muted);
}

.compose-meta-save__indicator {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--elaw-border);
  flex-shrink: 0;
}

.compose-meta-save__indicator--saving {
  background: var(--law-warning);
}

.compose-meta-save__indicator--saved {
  background: #16a34a;
}

.compose-meta-save__indicator--error {
  background: var(--law-danger);
}

.compose-meta-section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.compose-meta-section__label {
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--elaw-muted);
  margin: 0;
}

.compose-meta-field {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.compose-meta-field__label {
  font-size: 11px;
  font-weight: 600;
  color: var(--elaw-muted);
}

.compose-meta-field__input {
  border: 1px solid var(--law-border);
  border-radius: 6px;
  padding: 7px 10px;
  font-size: 13px;
  font-family: inherit;
  color: var(--elaw-text);
  background: #fff;
  outline: none;
  transition: border-color 0.12s;
  width: 100%;
}

.compose-meta-field__input:focus {
  border-color: var(--law-primary);
}

.compose-meta-field__input--textarea {
  resize: vertical;
  min-height: 56px;
}

select.compose-meta-field__input {
  cursor: pointer;
}

.compose-meta-timeline {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.compose-meta-timeline__item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}

.compose-meta-timeline__icon {
  font-size: 18px;
  color: var(--elaw-muted);
  margin-top: 2px;
}

.compose-meta-timeline__icon--done {
  color: #16a34a;
}

.compose-meta-timeline__label {
  font-size: 11px;
  color: var(--elaw-muted);
  font-weight: 600;
}

.compose-meta-timeline__value {
  font-size: 13px;
  color: var(--elaw-text);
  margin-top: 2px;
}

.compose-meta-empty {
  color: var(--elaw-muted);
  font-size: 13px;
}

.compose-meta-actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.compose-meta-actions__hint {
  font-size: 12px;
  color: var(--elaw-muted);
  margin: 0;
}

.compose-meta-actions__note {
  font-size: 12px;
  color: var(--law-danger);
  margin: 0;
}

.compose-meta-action-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  text-decoration: none;
  border: 1px solid var(--law-primary);
  color: var(--law-primary);
  background: var(--law-primary-soft);
  cursor: pointer;
  font-family: inherit;
  transition: background 0.12s;
}

.compose-meta-action-btn:hover {
  background: #d1ddf9;
}

.compose-meta-action-btn--secondary {
  border-color: var(--law-border);
  color: var(--elaw-muted);
  background: var(--law-surface);
}

.compose-meta-action-btn--secondary:hover {
  background: var(--elaw-border);
}
</style>
