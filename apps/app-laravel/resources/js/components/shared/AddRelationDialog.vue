<template>
  <v-dialog
    :model-value="true"
    max-width="960"
    scrollable
    @update:model-value="(val) => { if (!val) $emit('close') }"
  >
    <v-card class="add-relation-dialog">
      <v-card-title class="d-flex align-center justify-space-between pr-2">
        {{ dialogTitle }}
        <v-btn icon variant="text" @click="$emit('close')">
          <v-icon icon="mdi-close" />
        </v-btn>
      </v-card-title>

      <v-card-text>
        <section class="existing-relations mb-5">
          <div class="d-flex align-center ga-2 mb-2">
            <v-icon icon="mdi-link-variant" color="admin-primary" size="18" />
            <span class="text-subtitle-2 font-weight-bold">ความสัมพันธ์ที่มีอยู่ของกฎหมายนี้</span>
            <v-chip size="x-small" color="admin-primary" variant="tonal">
              {{ existingRelations.length }}
            </v-chip>
          </div>

          <div v-if="existingRelations.length" class="existing-relations__list">
            <div
              v-for="relation in existingRelations"
              :key="relation.id"
              class="existing-relations__row"
            >
              <div class="d-flex align-center ga-2 flex-wrap">
                <v-chip
                  size="x-small"
                  :color="RELATION_TYPE_COLORS[relation.type]"
                  variant="tonal"
                  :prepend-icon="RELATION_TYPE_ICONS[relation.type]"
                >
                  {{ relationTypeLabel(relation.type) }}
                </v-chip>
                <v-chip size="x-small" variant="outlined">
                  {{ relationSourceLabel(relation) }}
                </v-chip>
              </div>
              <div class="existing-relations__target">
                {{ formatRelationTarget(relation) }}
              </div>
              <div v-if="detailChip(relation.change_detail)" class="d-flex">
                <v-chip
                  size="x-small"
                  :color="detailChip(relation.change_detail)?.color || 'teal'"
                  variant="tonal"
                  :prepend-icon="detailChip(relation.change_detail)?.icon || 'mdi-pencil'"
                >
                  {{ detailChip(relation.change_detail)?.title }}
                </v-chip>
              </div>
              <div v-if="relation.note" class="text-caption text-medium-emphasis">
                {{ relation.note }}
              </div>
              <div v-if="proclamationDueLabel(relation)">
                <v-chip size="x-small" color="warning" variant="tonal" prepend-icon="mdi-bullhorn-outline">
                  {{ proclamationDueLabel(relation) }}
                </v-chip>
              </div>
            </div>
          </div>
          <div v-else class="existing-relations__empty text-body-2 text-medium-emphasis">
            ยังไม่มีความสัมพันธ์
          </div>
        </section>

        <v-divider class="mb-5" />

        <div v-if="showChangeDetails" class="mb-4">
          <div class="text-caption font-weight-bold text-medium-emphasis mb-2">รายละเอียดการเปลี่ยนแปลง *</div>
          <v-chip-group v-model="changeDetail" mandatory>
            <v-chip
              v-for="item in detailChoices"
              :key="item.code"
              :value="item.code"
              :color="item.color || 'teal'"
              :prepend-icon="item.icon || 'mdi-pencil'"
              filter
            >
              {{ item.title }}
            </v-chip>
          </v-chip-group>
        </div>

        <div v-if="allowFreeText" class="mb-4">
          <div class="text-caption font-weight-bold text-medium-emphasis mb-1">เป้าหมาย</div>
          <div class="d-flex ga-3">
            <v-btn
              size="small"
              :color="mode === 'picker' ? 'admin-primary' : ''"
              :variant="mode === 'picker' ? 'flat' : 'outlined'"
              @click="mode = 'picker'"
            >เลือกจากคลังกฎหมาย</v-btn>
            <v-btn
              size="small"
              :color="mode === 'text' ? 'admin-primary' : ''"
              :variant="mode === 'text' ? 'flat' : 'outlined'"
              @click="mode = 'text'"
            >พิมพ์เอง</v-btn>
          </div>
        </div>

        <LawRelationColumnPicker
          v-if="mode === 'picker'"
          v-model="pickerTarget"
          :exclude-document-id="excludeDocumentId"
          :parent-document-ids="parentDocumentIds"
          :catalog-mode="catalogMode"
          :require-section="requireSection"
          :whole-document-only="wholeDocumentOnly"
        />

        <template v-else>
          <v-text-field
            v-model="form.target_title"
            label="ชื่อกฎหมาย"
            placeholder="กฎหมายภายนอก ..."
            class="mb-3"
          />
          <v-text-field
            v-model="form.target_section"
            :label="requireSection ? 'ข้อ / มาตรา' : 'ข้อ (ไม่บังคับ)'"
            placeholder="ข้อ ๕"
            class="mb-3"
          />
          <v-text-field
            v-model="form.url"
            label="ลิงก์ (ไม่บังคับ)"
            placeholder="https://..."
            class="mb-3"
          />
        </template>

        <v-text-field
          v-model="form.note"
          label="หมายเหตุ"
          placeholder="ไม่บังคับ"
          class="mt-4"
        />

        <v-checkbox
          v-model="requiresNewProclamation"
          label="ต้องออกประกาศฉบับใหม่"
          color="admin-primary"
          hide-details
          density="comfortable"
          class="mt-2"
        />
        <template v-if="requiresNewProclamation">
          <div class="text-caption font-weight-bold text-medium-emphasis mt-2 mb-1">ขอบเขตที่ต้องออกประกาศ</div>
          <v-btn-toggle v-model="proclamationScope" mandatory density="comfortable" divided class="mb-3">
            <v-btn value="whole" class="text-none">ทั้งฉบับ</v-btn>
            <v-btn value="sections" class="text-none">บางข้อ</v-btn>
          </v-btn-toggle>
          <v-textarea
            v-if="proclamationScope === 'sections'"
            v-model="proclamationSectionsText"
            label="ข้อที่ต้องออกประกาศ"
            placeholder="เช่น ข้อ ๕, ข้อ ๗"
            hint="คั่นแต่ละข้อด้วยจุลภาคหรือขึ้นบรรทัดใหม่"
            persistent-hint
            rows="2"
            auto-grow
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-text-field
            v-model="proclamationDueDate"
            type="date"
            label="ต้องออกประกาศภายในวันที่"
            variant="outlined"
            density="comfortable"
            class="mt-2"
            :hint="dueHint"
            persistent-hint
          />
          <v-text-field
            v-model="proclamationIssuedOn"
            type="date"
            label="ออกประกาศแล้วเมื่อ (ไม่บังคับ)"
            variant="outlined"
            density="comfortable"
            class="mt-2"
            hint="ถ้าระบุวันนี้ ระบบจะไม่แจ้งเตือนซ้ำ ระบบจะหยุดเองด้วยเมื่อมีกฎหมายฉบับใหม่ที่อ้างฉบับนี้และมีผลบังคับใช้แล้ว กรณีบางข้อต้องผูกครบทุกข้อที่ระบุ"
            persistent-hint
          />
        </template>
      </v-card-text>

      <v-card-actions class="pa-4 ga-2">
        <v-spacer />
        <v-btn @click="$emit('close')">ยกเลิก</v-btn>
        <v-btn color="admin-primary" :disabled="!canSave" @click="save">เพิ่ม</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { LawRelation, LawRelationTarget, RelationScope, RelationType } from '../../types/document';
import {
  RELATION_TYPE_COLORS,
  RELATION_TYPE_ICONS,
  formatRelationTarget,
  proclamationDueLabel,
  relationTypeLabel,
} from '../../types/lawRelation';
import { createClientId } from '../../utils/createClientId';
import { useChangeStatus } from '../../composables/useChangeStatus';
import LawRelationColumnPicker from './LawRelationColumnPicker.vue';

const props = defineProps<{
  scope: RelationScope;
  blockId?: string | null;
  defaultType?: RelationType;
  excludeDocumentId?: string | null;
  parentDocumentIds?: string[];
  catalogMode?: 'all' | 'siblings' | 'parents';
  requireSection?: boolean;
  wholeDocumentOnly?: boolean;
  existingRelations?: LawRelation[];
  sectionLabels?: Record<string, string>;
  changeStatus?: string | null;
  detailSource?: 'internal' | 'external' | null;
}>();

const emit = defineEmits<{ close: []; save: [relation: LawRelation] }>();

const mode = ref<'picker' | 'text'>('picker');
const pickerTarget = ref<LawRelationTarget | null>(null);
const changeDetail = ref<string | null>(null);
const requiresNewProclamation = ref(false);
const proclamationScope = ref<'whole' | 'sections'>('whole');
const proclamationSectionsText = ref('');
const proclamationDueDate = ref('');
const proclamationIssuedOn = ref('');
const existingRelations = computed(() => props.existingRelations ?? []);
const allowFreeText = computed(() => !props.catalogMode || props.catalogMode === 'all');
const _cs = useChangeStatus();
const showChangeDetails = computed(() =>
  props.scope === 'section'
  && _cs.isSection(props.changeStatus ?? '')
  && _cs.hasDetails(props.changeStatus ?? ''),
);
const detailChoices = computed(() =>
  _cs.detailOptions(props.detailSource ?? undefined).filter((item) => item.role === 'repeals' || item.role === 'amends'),
);

function detailChip(detail: string | null | undefined) {
  return _cs.detailMeta(detail);
}

const form = ref<LawRelation>({
  id: createClientId('relation'),
  scope: props.scope,
  block_id: props.blockId ?? null,
  type: props.defaultType ?? 'related',
  target_document_id: null,
  target_title: '',
  target_section: null,
  target_block_id: null,
  note: null,
  url: null,
  change_detail: null,
  requires_new_proclamation: false,
  proclamation_scope: null,
  proclamation_sections: [],
  proclamation_due_date: null,
  proclamation_issued_on: null,
});

const dueHint = computed(() => {
  if (!proclamationDueDate.value) return 'ระบุวันที่ที่ต้องออกประกาศ ระบบจะใช้วันนี้แจ้งเตือนจนกว่าจะออกประกาศแล้ว';
  const due = new Date(`${proclamationDueDate.value}T00:00:00`);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const days = Math.round((due.getTime() - today.getTime()) / 86400000);
  if (Number.isNaN(days)) return 'ระบุวันที่ที่ต้องออกประกาศ';
  if (days < 0) return `เกินกำหนด ${Math.abs(days).toLocaleString('th-TH')} วัน และจะถูกใส่ในอีเมลแจ้งเตือน`;
  if (days === 0) return 'ต้องออกภายในวันนี้ และจะถูกใส่ในอีเมลแจ้งเตือน';
  return `ต้องออกภายใน ${days.toLocaleString('th-TH')} วัน และจะถูกใส่ในอีเมลแจ้งเตือน`;
});

const dialogTitle = computed(() => {
  const label = relationTypeLabel(form.value.type);
  return form.value.type === 'related' ? 'เพิ่มความสัมพันธ์' : `เพิ่มความสัมพันธ์ — ${label}`;
});

const canSave = computed(() => {
  if (showChangeDetails.value && !(changeDetail.value?.trim())) return false;
  if (requiresNewProclamation.value && !proclamationDueDate.value) return false;
  if (requiresNewProclamation.value && proclamationScope.value === 'sections' && proclamationSectionList().length === 0) return false;
  if (mode.value === 'text') {
    if (props.requireSection && !(form.value.target_section?.trim())) return false;
    return form.value.target_title.trim() !== '';
  }
  if (!pickerTarget.value || pickerTarget.value.title.trim() === '') return false;
  if (props.requireSection && !pickerTarget.value.section) return false;
  return true;
});

watch(requiresNewProclamation, (checked) => {
  if (!checked) {
    proclamationDueDate.value = '';
    proclamationIssuedOn.value = '';
    proclamationSectionsText.value = '';
    proclamationScope.value = 'whole';
  }
});

watch(changeDetail, (detail) => {
  const role = _cs.detailRole(detail);
  if (role === 'repeals' || role === 'amends') form.value.type = role;
});

function relationSourceLabel(relation: LawRelation): string {
  if (relation.scope === 'document') return 'ทั้งเอกสาร';
  if (!relation.block_id) return 'ข้อ';
  return props.sectionLabels?.[relation.block_id] ?? 'ข้อ';
}

watch(mode, () => {
  form.value.target_title = '';
  form.value.target_document_id = null;
  form.value.target_section = null;
  form.value.target_block_id = null;
  form.value.url = null;
  pickerTarget.value = null;
});

watch(pickerTarget, (target) => {
  if (!target) {
    form.value.target_document_id = null;
    form.value.target_title = '';
    form.value.target_section = null;
    form.value.target_block_id = null;
    return;
  }

  form.value.target_document_id = target.document_id;
  form.value.target_title = target.title;
  form.value.target_section = target.section;
  form.value.target_block_id = target.block_id;
});

function save(): void {
  if (!canSave.value) return;

  const targetSection = form.value.target_section?.trim() ?? '';
  emit('save', {
    ...form.value,
    target_title: form.value.target_title.trim(),
    target_section: targetSection !== '' ? targetSection : null,
    target_block_id: mode.value === 'picker' ? (form.value.target_block_id || null) : null,
    target_document_id: form.value.target_document_id || null,
    url: form.value.url?.trim() || null,
    note: form.value.note?.trim() || null,
    change_detail: showChangeDetails.value ? (_cs.normalizeDetail(changeDetail.value) || null) : null,
    requires_new_proclamation: requiresNewProclamation.value && proclamationDueDate.value !== '' && (proclamationScope.value !== 'sections' || proclamationSectionList().length > 0),
    proclamation_scope: requiresNewProclamation.value ? proclamationScope.value : null,
    proclamation_sections: requiresNewProclamation.value && proclamationScope.value === 'sections' ? proclamationSectionList() : [],
    proclamation_due_date: requiresNewProclamation.value ? proclamationDueDate.value || null : null,
    proclamation_issued_on: requiresNewProclamation.value ? proclamationIssuedOn.value || null : null,
  });
}

function proclamationSectionList(): string[] {
  const seen: string[] = [];
  for (const part of proclamationSectionsText.value.split(/[,،\n]+/)) {
    const text = part.trim();
    if (text !== '' && !seen.includes(text)) seen.push(text);
  }
  return seen;
}
</script>

<style scoped>
.add-relation-dialog :deep(.v-card-text) {
  padding-top: 8px;
}

.existing-relations {
  padding: 14px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #f8fafc;
}

.existing-relations__list {
  display: flex;
  max-height: 220px;
  flex-direction: column;
  gap: 8px;
  overflow-y: auto;
}

.existing-relations__row {
  display: flex;
  flex-direction: column;
  gap: 5px;
  padding: 10px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #fff;
}

.existing-relations__target {
  color: #334155;
  font-size: 14px;
}

.existing-relations__empty {
  padding: 8px 2px 2px;
}
</style>
