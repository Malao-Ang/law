<template>
  <v-card flat border rounded="lg" class="document-editor-panel">
    <div class="document-editor-header pa-3 pb-0">
      <div>
        <p class="text-subtitle-2 font-weight-bold mb-0">Document HTML Review</p>
        <p class="text-caption text-medium-emphasis mb-0">แก้ไขเอกสารทั้งฉบับในรูปแบบใกล้เคียงต้นฉบับก่อนจัดลำดับเนื้อหา</p>
      </div>
      <v-chip
        :color="outOfSync ? 'warning' : 'success'"
        variant="tonal"
        size="small"
        class="align-self-start mt-1"
      >
        <strong>{{ modeLabel }}</strong>
        <span class="ml-1 text-caption">{{ outOfSync ? 'HTML draft ไม่ตรงกับ blocks ล่าสุด' : 'HTML draft พร้อมใช้งาน' }}</span>
      </v-chip>
    </div>

    <div class="editor-toolbar px-3 pt-2">
      <v-btn flat size="small" class="mr-1 mb-1" @click="format('bold')">Bold</v-btn>
      <v-btn flat size="small" class="mr-1 mb-1" @click="format('italic')">Italic</v-btn>
      <v-btn flat size="small" class="mr-1 mb-1" @click="format('underline')">Underline</v-btn>
      <v-btn flat size="small" class="mr-1 mb-1" @click="formatBlock('p')">P</v-btn>
      <v-btn flat size="small" class="mr-1 mb-1" @click="formatBlock('h1')">H1</v-btn>
      <v-btn flat size="small" class="mr-1 mb-1" @click="formatBlock('h2')">H2</v-btn>
      <v-btn flat size="small" class="mr-1 mb-1" @click="format('insertUnorderedList')">UL</v-btn>
      <v-btn flat size="small" class="mr-1 mb-1" @click="format('insertOrderedList')">OL</v-btn>
      <v-btn flat size="small" class="mr-1 mb-1" @click="format('removeFormat')">Clear</v-btn>
      <span class="toolbar-separator"></span>
      <v-btn
        flat
        size="small"
        class="mr-1 mb-1"
        :color="isPreviewMode ? 'primary' : undefined"
        :variant="isPreviewMode ? 'tonal' : 'elevated'"
        @click="togglePreview"
      >
        {{ isPreviewMode ? 'Edit' : 'Preview' }}
      </v-btn>
    </div>

    <div class="editor-shell ma-3 mt-2">
      <!-- ponytail: load-bearing for contenteditable HTML editor, keep -->
      <div
        v-show="!isPreviewMode"
        ref="editorRef"
        class="document-rich-editor"
        contenteditable="true"
        spellcheck="false"
        @input="onInput"
        @click="onClick"
      ></div>
      <!-- ponytail: load-bearing for v-html preview render surface, keep -->
      <div
        v-show="isPreviewMode"
        class="document-rich-editor html-preview-mode"
        v-html="modelValue"
      ></div>
    </div>
  </v-card>
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
