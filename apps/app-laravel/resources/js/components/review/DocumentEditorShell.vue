<template>
  <div class="editor-shell">
    <!-- Editor/Preview Toggle Toolbar -->
    <div class="editor-shell-header">
      <div class="editor-shell-title">
        <h1>{{ modeLabel }}</h1>
        <p v-if="isDirty" class="editor-shell-hint hint-warning">• มีการเปลี่ยนแปลงยังไม่บันทึก</p>
      </div>
      <div class="editor-shell-actions">
        <v-btn
          v-if="mode === 'edit'"
          size="small"
          variant="tonal"
          color="primary"
          prepend-icon="mdi-eye-outline"
          @click="switchMode('preview')"
        >
          ดูตัวอย่าง
        </v-btn>
        <v-btn
          v-if="mode === 'preview'"
          size="small"
          variant="tonal"
          prepend-icon="mdi-pencil-outline"
          @click="switchMode('edit')"
        >
          แก้ไขข้อมูล
        </v-btn>
        <v-btn
          size="small"
          variant="tonal"
          :disabled="!isDirty || saving"
          prepend-icon="mdi-content-save-outline"
          @click="saveDocument"
        >
          {{ saving ? 'บันทึก...' : 'บันทึก' }}
        </v-btn>
        <v-btn
          size="small"
          color="primary"
          :disabled="saving"
          :loading="saving"
          prepend-icon="mdi-arrow-right-circle-outline"
          @click="saveAndContinue"
        >
          บันทึกและไปที่ Compose
        </v-btn>
      </div>
    </div>

    <!-- Main Content Area (Edit Mode) -->
    <div v-if="mode === 'edit'" class="editor-shell-edit" @click.stop>
      <EditorContent
        v-if="editor"
        :editor="editor"
        class="editor-shell-edit-content"
      />
    </div>

    <!-- Main Content Area (Preview Mode) -->
    <div v-if="mode === 'preview'" class="editor-shell-preview" @click.stop>
      <div class="preview-container">
        <article
          class="doc-review-document"
          v-html="sanitizedHtml"
        ></article>
      </div>
    </div>

    <!-- Error/Status -->
    <div v-if="error" class="editor-shell-error">
      <v-icon icon="mdi-alert-circle-outline" size="20" />
      <span>{{ error }}</span>
      <v-btn size="x-small" variant="text" @click="error = ''">ปิด</v-btn>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import DOMPurify from 'dompurify';
import { reorderBlocks, saveDocumentReview } from '../../api/client';
import type { ReviewDocument } from '../../types/document';

const props = defineProps<{
  review: ReviewDocument;
  documentId: string;
}>();

const emit = defineEmits<{
  reload: [];
  'update:mode': [string];
}>();

const mode = ref<'edit' | 'preview'>('edit');
const isDirty = ref(false);
const saving = ref(false);
const error = ref('');
const router = useRouter();
let autoSaveTimer: ReturnType<typeof setTimeout> | null = null;

const editor = useEditor({
  extensions: [StarterKit, Underline],
  content: props.review.document_review.draft_html,
  editable: mode.value === 'edit',
  onUpdate: () => {
    isDirty.value = true;
    scheduleAutoSave();
  },
});

const modeLabel = computed(() => {
  return mode.value === 'edit' ? 'ตรวจทานเนื้อหาเอกสาร (โหมดแก้ไข)' : 'ตรวจทานเนื้อหาเอกสาร (โหมดดูตัวอย่าง)';
});

const sanitizedHtml = computed(() => {
  const html = editor.value?.getHTML() ?? props.review.document_review.draft_html;
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: [
      'p', 'br', 'strong', 'em', 'u', 's',
      'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
      'ul', 'ol', 'li', 'blockquote',
      'table', 'thead', 'tbody', 'tr', 'th', 'td',
      'span', 'div', 'sub', 'sup', 'article', 'section', 'header', 'footer',
    ],
    ALLOWED_ATTR: ['class', 'style', 'colspan', 'rowspan', 'data-block-id', 'data-page-no', 'data-block-type', 'data-reading-order'],
  });
});

watch(
  () => mode.value,
  (next) => {
    if (!editor.value) return;
    editor.value.setEditable(next === 'edit');
  },
);

onBeforeUnmount(() => {
  if (autoSaveTimer) clearTimeout(autoSaveTimer);
  editor.value?.destroy();
});

function switchMode(next: 'edit' | 'preview'): void {
  if (next === 'preview' && isDirty.value) {
    error.value = 'บันทึกการเปลี่ยนแปลงก่อนดูตัวอย่าง';
    return;
  }
  mode.value = next;
  emit('update:mode', next);
}

function scheduleAutoSave(): void {
  if (autoSaveTimer) clearTimeout(autoSaveTimer);
  autoSaveTimer = setTimeout(saveDocument, 2000);
}

async function saveDocument(): Promise<void> {
  if (!editor.value || !isDirty.value) return;

  saving.value = true;
  error.value = '';

  try {
    const html = editor.value.getHTML();
    await saveDocumentReview(props.documentId, {
      draft_html: html,
    });
    isDirty.value = false;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'บันทึกไม่สำเร็จ';
  } finally {
    saving.value = false;
  }
}

async function saveAndContinue(): Promise<void> {
  if (saving.value) return;
  if (!editor.value) return;
  await saveDocument();
  if (!error.value) {
    router.push(`/documents/${props.documentId}/compose`);
  }
}
</script>

<style scoped>
.editor-shell {
  background: #f5f5f5;
  overflow: hidden;
  height: 100vh;
  width: 100vw;
  display: flex;
  flex-direction: column;
}

.editor-shell-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 24px;
  background: white;
  border-bottom: 1px solid #ddd;
  gap: 16px;
  flex-shrink: 0;
}

.editor-shell-title {
  flex: 1;
}

.editor-shell-title h1 {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
}

.editor-shell-title .editor-shell-hint {
  margin: 4px 0 0 0;
  font-size: 12px;
}

.hint-warning {
  color: #d97706;
}

.editor-shell-actions {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}

.editor-shell-edit,
.editor-shell-preview {
  flex: 1;
  overflow: auto;
  padding: 24px;
}

.editor-shell-edit :deep(.ProseMirror) {
  background: white;
  padding: 40px;
  border-radius: 4px;
  min-height: 600px;
  font-family: 'Sarabun', sans-serif;
  font-size: 16px;
  line-height: 1.6;
  color: #333;
}

.editor-shell-edit :deep(.ProseMirror:focus) {
  outline: 2px solid #1976d2;
  outline-offset: -1px;
}

.editor-shell-edit :deep(.ProseMirror h1),
.editor-shell-edit :deep(.ProseMirror h2),
.editor-shell-edit :deep(.ProseMirror h3) {
  margin: 16px 0 8px 0;
  font-weight: 600;
}

.editor-shell-edit :deep(.ProseMirror p) {
  margin: 0 0 8px 0;
}

.editor-shell-edit :deep(.ProseMirror ul),
.editor-shell-edit :deep(.ProseMirror ol) {
  margin: 8px 0;
  padding-left: 24px;
}

.editor-shell-edit :deep(.ProseMirror li) {
  margin: 4px 0;
}

.editor-shell-preview {
  display: flex;
  justify-content: center;
}

.preview-container {
  background: white;
  padding: 40px;
  border-radius: 4px;
  max-width: 900px;
  width: 100%;
}

.preview-container :deep(.doc-review-document) {
  font-family: 'Sarabun', sans-serif;
  font-size: 16px;
  line-height: 1.6;
  color: #333;
}

.preview-container :deep(.doc-review-document h1),
.preview-container :deep(.doc-review-document h2),
.preview-container :deep(.doc-review-document h3) {
  margin: 16px 0 8px 0;
  font-weight: 600;
}

.preview-container :deep(.doc-review-document p) {
  margin: 0 0 8px 0;
}

.preview-container :deep(.doc-review-document ul),
.preview-container :deep(.doc-review-document ol) {
  margin: 8px 0;
  padding-left: 24px;
}

.preview-container :deep(.doc-review-document li) {
  margin: 4px 0;
}

.preview-container :deep(.doc-review-document table) {
  border-collapse: collapse;
  width: 100%;
  margin: 8px 0;
}

.preview-container :deep(.doc-review-document th),
.preview-container :deep(.doc-review-document td) {
  border: 1px solid #ddd;
  padding: 8px;
  text-align: left;
}

.preview-container :deep(.doc-review-document th) {
  background: #f9f9f9;
  font-weight: 600;
}

.editor-shell-error {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  background: #fef2f2;
  color: #d97706;
  border-top: 1px solid #fed7aa;
  font-size: 14px;
  flex-shrink: 0;
}
</style>
