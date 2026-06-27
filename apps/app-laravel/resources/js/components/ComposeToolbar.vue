<template>
  <v-toolbar class="compose-toolbar-card" density="comfortable" flat>
    <template #prepend>
      <v-btn icon="mdi-menu" variant="text" @click="$emit('toggle:navigator')" />
    </template>

    <v-toolbar-title class="compose-toolbar-title">
      <span>{{ title }}</span>
      <small>{{ subtitle }}</small>
    </v-toolbar-title>

    <div class="compose-toolbar-controls">
      <v-select
        class="compose-toolbar-select compose-toolbar-select--font"
        density="compact"
        hide-details
        item-title="label"
        item-value="value"
        :items="fontOptions"
        :model-value="font"
        variant="outlined"
        @update:model-value="$emit('update:font', $event as ThaiFont)"
      />

      <v-select
        class="compose-toolbar-select compose-toolbar-select--size"
        density="compact"
        hide-details
        :items="fontSizes"
        :model-value="fontSize"
        variant="outlined"
        @update:model-value="$emit('update:fontSize', Number($event))"
      />

      <v-chip class="compose-toolbar-status" :color="statusColor" variant="tonal" size="small">
        {{ autoSaveLabel }}
      </v-chip>
    </div>

    <div class="compose-toolbar-actions">
      <v-btn
        v-for="button in commandButtons"
        :key="button.action"
        :active="isButtonActive(button.action)"
        :disabled="isDisabled(button.action)"
        :icon="button.icon"
        :title="button.label"
        size="small"
        variant="text"
        @click="$emit('action', button.action)"
      />

      <v-divider vertical class="compose-toolbar-divider" />

      <v-btn
        :to="alternateRoute"
        :text="alternateRouteLabel"
        prepend-icon="mdi-swap-horizontal"
        size="small"
        variant="tonal"
      />
      <v-btn
        icon="mdi-refresh"
        size="small"
        title="รีโหลดข้อมูล"
        variant="text"
        @click="$emit('reload')"
      />
      <v-btn
        icon="mdi-file-document-edit-outline"
        size="small"
        title="ข้อมูลหนังสือ"
        variant="text"
        @click="$emit('toggle:details')"
      />
    </div>
  </v-toolbar>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { ThaiFont } from '../types/document';

interface EditorStateSnapshot {
  active: boolean;
  canUndo: boolean;
  canRedo: boolean;
  isBold: boolean;
  isItalic: boolean;
  isUnderline: boolean;
  isBulletList: boolean;
  isOrderedList: boolean;
}

type ToolbarAction = 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList';

const props = defineProps<{
  title: string;
  subtitle: string;
  font: ThaiFont;
  fontSize: number;
  editorState: EditorStateSnapshot;
  autoSaveState: 'idle' | 'saving' | 'saved' | 'error';
  autoSaveLabel: string;
  alternateRouteLabel: string;
  alternateRoute: string;
}>();

defineEmits<{
  reload: [];
  action: [ToolbarAction];
  'toggle:navigator': [];
  'toggle:details': [];
  'update:font': [ThaiFont];
  'update:fontSize': [number];
}>();

const fontOptions: Array<{ label: string; value: ThaiFont }> = [
  { label: 'Sarabun', value: 'sarabun' },
  { label: 'PSK Sarabun', value: 'psk-sarabun' },
  { label: 'Angsana New', value: 'angsana' },
];

const fontSizes = [10, 12, 14, 16, 18, 20, 24, 28, 32];

const commandButtons: Array<{ action: ToolbarAction; label: string; icon: string }> = [
  { action: 'undo', label: 'ย้อนกลับ', icon: 'mdi-undo-variant' },
  { action: 'redo', label: 'ทำซ้ำ', icon: 'mdi-redo-variant' },
  { action: 'bold', label: 'หนา', icon: 'mdi-format-bold' },
  { action: 'italic', label: 'เอียง', icon: 'mdi-format-italic' },
  { action: 'underline', label: 'ขีดเส้นใต้', icon: 'mdi-format-underline' },
  { action: 'bulletList', label: 'รายการ', icon: 'mdi-format-list-bulleted' },
  { action: 'orderedList', label: 'ลำดับ', icon: 'mdi-format-list-numbered' },
];

const statusColor = computed(() => {
  if (props.autoSaveState === 'saving') return 'warning';
  if (props.autoSaveState === 'saved') return 'success';
  if (props.autoSaveState === 'error') return 'error';
  return 'default';
});

function isButtonActive(action: ToolbarAction): boolean {
  if (action === 'bold') return props.editorState.isBold;
  if (action === 'italic') return props.editorState.isItalic;
  if (action === 'underline') return props.editorState.isUnderline;
  if (action === 'bulletList') return props.editorState.isBulletList;
  if (action === 'orderedList') return props.editorState.isOrderedList;
  return false;
}

function isDisabled(action: ToolbarAction): boolean {
  if (action === 'undo') return !props.editorState.active || !props.editorState.canUndo;
  if (action === 'redo') return !props.editorState.active || !props.editorState.canRedo;
  return !props.editorState.active;
}
</script>
