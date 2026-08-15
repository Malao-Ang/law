<template>
  <v-toolbar class="compose-toolbar-card" density="comfortable" flat>
    <template #prepend>
      <v-btn icon="mdi-menu" variant="text" @click="emit('toggle:navigator')" />
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
        @update:model-value="emit('update:font', $event as ThaiFont)"
      />

      <v-select
        class="compose-toolbar-select compose-toolbar-select--size"
        density="compact"
        hide-details
        :items="fontSizes"
        :model-value="fontSize"
        variant="outlined"
        @update:model-value="emit('update:fontSize', Number($event))"
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
        @click="emit('action', button.action)"
      />

      <v-btn-toggle
        :model-value="props.editorState.alignment"
        density="compact"
        variant="text"
        @update:model-value="$event !== undefined && emit('action', 'setAlignment', $event)"
      >
        <v-btn
          value="left"
          icon="mdi-format-align-left"
          size="small"
          title="ชิดซ้าย"
          :disabled="!props.editorState.active"
        />
        <v-btn
          value="center"
          icon="mdi-format-align-center"
          size="small"
          title="กึ่งกลาง"
          :disabled="!props.editorState.active"
        />
        <v-btn
          value="right"
          icon="mdi-format-align-right"
          size="small"
          title="ชิดขวา"
          :disabled="!props.editorState.active"
        />
        <v-btn
          value="justify"
          icon="mdi-format-align-justify"
          size="small"
          title="เต็มบรรทัด"
          :disabled="!props.editorState.active"
        />
      </v-btn-toggle>

      <v-btn
        icon="mdi-format-indent-decrease"
        size="small"
        title="ลดย่อหน้า"
        variant="text"
        :disabled="!props.editorState.active"
        @click="emit('action', 'outdent')"
      />
      <v-btn
        icon="mdi-format-indent-increase"
        size="small"
        title="เพิ่มย่อหน้า"
        variant="text"
        :disabled="!props.editorState.active"
        @click="emit('action', 'indent')"
      />

      <v-divider vertical class="compose-toolbar-divider" />

      <v-btn
        :disabled="props.correctionInProgress"
        prepend-icon="mdi-download-outline"
        size="small"
        variant="tonal"
        color="primary"
        :title="props.correctionInProgress ? 'รอ AI correction เสร็จ' : 'ส่งออก JSON จัดลำดับเนื้อหา'"
        @click="emit('action', 'export')"
      >
        Export
      </v-btn>

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
        @click="emit('reload')"
      />
      <v-btn
        icon="mdi-file-document-edit-outline"
        size="small"
        title="ข้อมูลหนังสือ"
        variant="text"
        @click="emit('toggle:details')"
      />

      <v-divider vertical class="compose-toolbar-divider" />

      <v-btn
        v-if="!props.editMode"
        size="small"
        variant="tonal"
        color="primary"
        prepend-icon="mdi-pencil-outline"
        title="เข้าสู่โหมดแก้ไขเอกสาร"
        @click="emit('toggle:editMode')"
      >
        แก้ไข
      </v-btn>

      <template v-else>
        <v-btn
          size="small"
          variant="tonal"
          color="success"
          prepend-icon="mdi-content-save-outline"
          title="บันทึกทุก block ที่แก้ไข"
          @click="emit('action', 'saveAll')"
        >
          บันทึกทั้งหมด
        </v-btn>
        <v-btn
          size="small"
          variant="tonal"
          prepend-icon="mdi-scissors-cutting"
          title="แยก block ที่ตำแหน่ง cursor"
          :disabled="!props.editorState.active"
          @click="emit('action', 'splitBlock')"
        >
          แยกบล็อก
        </v-btn>
        <v-btn
          size="small"
          variant="outlined"
          prepend-icon="mdi-close"
          title="ออกจากโหมดแก้ไข"
          @click="emit('action', 'cancelAll')"
        >
          ออก
        </v-btn>
      </template>
    </div>
  </v-toolbar>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { ThaiFont } from '../../types/document';

interface EditorStateSnapshot {
  active: boolean;
  canUndo: boolean;
  canRedo: boolean;
  isBold: boolean;
  isItalic: boolean;
  isUnderline: boolean;
  isBulletList: boolean;
  isOrderedList: boolean;
  alignment: 'left' | 'center' | 'right' | 'justify';
}

type ToolbarAction = 'undo' | 'redo' | 'bold' | 'italic' | 'underline' | 'bulletList' | 'orderedList' | 'export' | 'saveAll' | 'cancelAll' | 'indent' | 'outdent' | 'setAlignment' | 'splitBlock';

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
  correctionInProgress?: boolean;
  editMode: boolean;
}>();

const emit = defineEmits<{
  reload: [];
  action: [type: ToolbarAction, value?: string];
  'toggle:navigator': [];
  'toggle:details': [];
  'toggle:editMode': [];
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
  if (action === 'export') return false;
  if (action === 'bold') return props.editorState.isBold;
  if (action === 'italic') return props.editorState.isItalic;
  if (action === 'underline') return props.editorState.isUnderline;
  if (action === 'bulletList') return props.editorState.isBulletList;
  if (action === 'orderedList') return props.editorState.isOrderedList;
  return false;
}

function isDisabled(action: ToolbarAction): boolean {
  if (action === 'export') return !!props.correctionInProgress;
  if (action === 'undo') return !props.editorState.active || !props.editorState.canUndo;
  if (action === 'redo') return !props.editorState.active || !props.editorState.canRedo;
  return !props.editorState.active;
}
</script>
