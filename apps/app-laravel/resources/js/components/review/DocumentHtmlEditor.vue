<template>
  <section class="panel document-editor-panel">
    <div class="document-editor-header">
      <div>
        <h3>Document HTML Review</h3>
        <p class="hint">แก้ไขเอกสารทั้งฉบับในรูปแบบใกล้เคียงต้นฉบับก่อนสร้าง RAG</p>
      </div>
      <div class="editor-status" :class="{ warning: outOfSync }">
        <strong>{{ modeLabel }}</strong>
        <span class="hint">{{ outOfSync ? 'HTML draft ไม่ตรงกับ blocks ล่าสุด' : 'HTML draft พร้อมใช้งาน' }}</span>
      </div>
    </div>

    <div class="editor-toolbar">
      <button class="btn" type="button" @click="format('bold')">Bold</button>
      <button class="btn" type="button" @click="format('italic')">Italic</button>
      <button class="btn" type="button" @click="format('underline')">Underline</button>
      <button class="btn" type="button" @click="formatBlock('p')">P</button>
      <button class="btn" type="button" @click="formatBlock('h1')">H1</button>
      <button class="btn" type="button" @click="formatBlock('h2')">H2</button>
      <button class="btn" type="button" @click="format('insertUnorderedList')">UL</button>
      <button class="btn" type="button" @click="format('insertOrderedList')">OL</button>
      <button class="btn" type="button" @click="format('removeFormat')">Clear</button>
      <span class="toolbar-separator"></span>
      <button
        class="btn"
        type="button"
        :class="{ active: isPreviewMode }"
        @click="togglePreview"
      >
        {{ isPreviewMode ? 'Edit' : 'Preview' }}
      </button>
    </div>

    <div class="editor-shell">
      <div
        v-show="!isPreviewMode"
        ref="editorRef"
        class="document-rich-editor"
        contenteditable="true"
        spellcheck="false"
        @input="onInput"
        @click="onClick"
      ></div>
      <div
        v-show="isPreviewMode"
        class="document-rich-editor html-preview-mode"
        v-html="modelValue"
      ></div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps<{
  modelValue: string;
  selectedBlockId: string | null;
  htmlMode: 'generated' | 'manual';
  outOfSync: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: string];
  'select-block': [blockId: string];
}>();

const editorRef = ref<HTMLDivElement | null>(null);
const isPreviewMode = ref(false);

const modeLabel = computed(() => (props.htmlMode === 'manual' ? 'Manual draft' : 'Generated from blocks'));

function setEditorHtml(value: string): void {
  const editor = editorRef.value;
  if (!editor || editor.innerHTML === value || document.activeElement === editor) return;
  editor.innerHTML = value;
  highlightSelectedBlock();
}

function onInput(event: Event): void {
  const target = event.target as HTMLDivElement | null;
  emit('update:modelValue', target?.innerHTML ?? '');
}

function onClick(event: MouseEvent): void {
  const target = event.target as HTMLElement | null;
  const wrapper = target?.closest<HTMLElement>('[data-block-id]');
  const blockId = wrapper?.dataset.blockId;
  if (blockId) {
    emit('select-block', blockId);
  }
}

function format(command: string): void {
  editorRef.value?.focus();
  document.execCommand(command, false);
  emit('update:modelValue', editorRef.value?.innerHTML ?? '');
}

function formatBlock(tag: 'p' | 'h1' | 'h2'): void {
  editorRef.value?.focus();
  document.execCommand('formatBlock', false, tag);
  emit('update:modelValue', editorRef.value?.innerHTML ?? '');
}

function highlightSelectedBlock(): void {
  const editor = editorRef.value;
  if (!editor) return;

  editor.querySelectorAll('.is-selected-block').forEach((element) => {
    element.classList.remove('is-selected-block');
  });

  if (!props.selectedBlockId) {
    return;
  }

  const selected = editor.querySelector<HTMLElement>(`[data-block-id="${props.selectedBlockId}"]`);
  selected?.classList.add('is-selected-block');
}

function togglePreview(): void {
  isPreviewMode.value = !isPreviewMode.value;
}

watch(() => props.selectedBlockId, () => nextTick(highlightSelectedBlock));
watch(() => props.modelValue, (newValue) => setEditorHtml(newValue));

onMounted(() => {
  setEditorHtml(props.modelValue);
  highlightSelectedBlock();
});
</script>
