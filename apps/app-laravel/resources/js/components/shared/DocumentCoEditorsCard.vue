<template>
  <v-card flat border rounded="lg" class="pa-4 mb-4">
    <div class="text-subtitle-1 font-weight-bold mb-3">ผู้รับผิดชอบเอกสาร</div>
    <div class="d-flex align-center ga-2 mb-3">
      <span>ผู้รับผิดชอบหลัก: {{ meta?.responsible?.name || meta?.responsible?.username || 'ดูแลโดยผู้ดูแลระบบสูงสุด' }}</span>
      <v-chip size="small" color="admin-primary">หลัก</v-chip>
    </div>
    <div v-for="person in meta?.co_editors ?? []" :key="person.username" class="d-flex flex-wrap align-center ga-3 mb-2">
      <span>{{ person.name || person.username }} ({{ person.username }})</span>
      <v-checkbox v-for="action in actions" :key="action.value" :label="action.label"
        :model-value="person.actions.includes(action.value)" :disabled="!can.manage_co_editors || saving"
        color="admin-primary" density="compact" hide-details
        @update:model-value="toggle(person, action.value, $event === true)" />
      <v-btn v-if="can.manage_co_editors" icon="mdi-delete-outline" variant="text" size="small" color="error"
        aria-label="ถอดผู้รับผิดชอบร่วม" :disabled="saving" @click="remove(person)" />
    </div>
    <v-btn v-if="can.manage_co_editors" color="admin-primary" variant="outlined" prepend-icon="mdi-account-plus-outline"
      :disabled="saving || !meta?.owner_unit_code" :loading="membersLoading" @click="openAdd">เพิ่มผู้รับผิดชอบ</v-btn>
  </v-card>
  <v-dialog v-model="dialog" max-width="640">
    <v-card title="เพิ่มผู้รับผิดชอบ">
      <v-card-text>
        <v-autocomplete v-model="selected" :items="candidates" item-value="username" :item-title="memberTitle"
          label="ค้นหาชื่อหรือชื่อผู้ใช้" variant="outlined" density="compact" :disabled="saving" clearable hide-details />
        <div class="d-flex flex-wrap ga-3 mt-4">
          <v-tooltip v-for="action in actions" :key="action.value" :disabled="allowed(action.value)" text="ไม่มีสิทธิ์นี้ในหน่วยงาน">
            <template #activator="{ props: tooltipProps }">
              <span v-bind="tooltipProps">
                <v-checkbox v-model="selectedActions" :value="action.value" :label="action.label" color="admin-primary"
                  density="compact" hide-details :disabled="saving || !allowed(action.value)" />
              </span>
            </template>
          </v-tooltip>
        </div>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn :disabled="saving" @click="dialog = false">ยกเลิก</v-btn>
        <v-btn color="admin-primary" :disabled="!selected || selectedActions.length === 0 || !can.manage_co_editors" :loading="saving" @click="add">บันทึก</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Swal from 'sweetalert2';
import { jsonRequest, type ApiRequestError } from '../../api/client';
import { alertError } from '../master/masterDialogs';
import { useDocumentStore } from '../../stores/documentStore';
import { invalidateReview } from '../../stores/reviewCache';
import type { CoEditorAction, DocumentCapabilities, DocumentCoEditor } from '../../types/document';
import type { Membership } from '../../types/unit';

type Member = Membership & { username: string; name: string | null; is_disabled: boolean };
type ChangeResponse = { co_editors: DocumentCoEditor[]; can: DocumentCapabilities };
const props = defineProps<{ documentId: string; can: DocumentCapabilities }>();
const emit = defineEmits<{ updated: [can: DocumentCapabilities] }>();
const store = useDocumentStore();
const meta = computed(() => store.review?.law_meta);
const actions: { value: CoEditorAction; label: string }[] = [
  { value: 'edit', label: 'แก้ไข' }, { value: 'delete', label: 'ลบ' },
  { value: 'esign', label: 'ส่ง e-sign' }, { value: 'publish', label: 'เผยแพร่' },
];
const saving = ref(false);
const membersLoading = ref(false);
const dialog = ref(false);
const members = ref<Member[]>([]);
const selected = ref<string | null>(null);
const selectedActions = ref<CoEditorAction[]>([]);
const candidates = computed(() => members.value.filter(member => !member.is_disabled
  && ['editor', 'inner_admin', 'outer_admin'].includes(member.role)
  && member.username !== meta.value?.responsible?.username
  && !meta.value?.co_editors?.some(person => person.username === member.username)));
const memberTitle = (member: Member): string => `${member.name || member.username} (${member.username})`;
function allowed(action: CoEditorAction): boolean {
  return candidates.value.find(member => member.username === selected.value)?.actions.includes(action) ?? false;
}
watch(selected, () => { selectedActions.value = []; });

async function openAdd(): Promise<void> {
  if (!props.can.manage_co_editors || !meta.value?.owner_unit_code) return;
  membersLoading.value = true;
  try {
    members.value = (await jsonRequest<{ items: Member[] }>(`/api/units/${encodeURIComponent(meta.value.owner_unit_code)}/members`)).items;
    selected.value = null;
    selectedActions.value = [];
    dialog.value = true;
  } catch (error) {
    await showError(error);
  } finally {
    membersLoading.value = false;
  }
}

async function showError(error: unknown): Promise<void> {
  if ((error as ApiRequestError).status !== 403) {
    await alertError(error instanceof Error ? error.message : 'บันทึกผู้รับผิดชอบร่วมไม่สำเร็จ');
  }
}

async function change(username: string, granted: CoEditorAction[] | null): Promise<boolean> {
  if (saving.value || !props.can.manage_co_editors) return false;
  saving.value = true;
  try {
    const response = await jsonRequest<ChangeResponse>(`/api/documents/${encodeURIComponent(props.documentId)}/co-editors/${encodeURIComponent(username)}`, {
      method: granted === null ? 'DELETE' : 'PUT',
      ...(granted === null ? {} : { body: JSON.stringify({ actions: granted }) }),
    });
    if (store.review) {
      store.review.law_meta.co_editors = response.co_editors;
      store.review.can = response.can;
    }
    invalidateReview(props.documentId);
    emit('updated', response.can);
    return true;
  } catch (error) {
    await showError(error);
    return false;
  } finally {
    saving.value = false;
  }
}
async function add(): Promise<void> {
  if (selected.value && await change(selected.value, selectedActions.value.filter(allowed))) dialog.value = false;
}
async function toggle(person: DocumentCoEditor, action: CoEditorAction, checked: boolean): Promise<void> {
  await change(person.username, checked ? [...new Set([...person.actions, action])] : person.actions.filter(value => value !== action));
}
async function remove(person: DocumentCoEditor): Promise<void> {
  const result = await Swal.fire({ icon: 'warning', title: 'ถอดผู้รับผิดชอบร่วม?', text: person.name || person.username,
    showCancelButton: true, confirmButtonText: 'ถอดผู้รับผิดชอบ', cancelButtonText: 'ยกเลิก' });
  if (result.isConfirmed) await change(person.username, null);
}
</script>
