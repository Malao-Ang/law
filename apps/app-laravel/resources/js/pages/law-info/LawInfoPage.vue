<template>
  <AppShell :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'ข้อมูลกฎหมาย']" title="ข้อมูลกฎหมาย (หัวเอกสาร)"
    subtitle="กรอกข้อมูลหัวเอกสารก่อนไปจัดการ RAG">
    <template #actions>
      <v-btn variant="outlined" @click="router.push(`/documents/${props.documentId}/review`)">
        ย้อนกลับ
      </v-btn>
      <v-btn color="#1a3673" :loading="documentStore.saving" @click="saveAndContinue">
        บันทึกและไปต่อ
      </v-btn>
    </template>

    <div v-if="documentStore.loading" class="d-flex flex-column align-center justify-center pa-12 ga-3 text-medium-emphasis">
      <v-progress-circular indeterminate color="primary" />
      <span>กำลังโหลด...</span>
    </div>

    <template v-else>
      <v-alert v-if="documentStore.saveError" type="error" variant="tonal" density="compact" closable class="mb-3"
        @click:close="documentStore.setSaveError()">
        {{ documentStore.saveError }}
      </v-alert>

      <v-card class="pa-4" elevation="0" style="border:1px solid #e2e8f0;border-radius:12px">
        <v-row dense>
          <v-col cols="12" sm="6">
            <v-text-field v-model="lawMetaForm.status" label="สถานะ" placeholder="มีผลใช้บังคับ" />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field v-model="lawMetaForm.law_type" label="ประเภท" placeholder="พระราชบัญญัติ" />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field v-model="lawMetaForm.law_group" label="กลุ่มกฎหมาย" placeholder="ด้านวิชาการ" />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field v-model="lawMetaForm.agency" label="หน่วยงาน" placeholder="มหาวิทยาลัยบูรพา" />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field v-model="lawMetaForm.promulgation_date" label="วันที่ประกาศ" placeholder="9 มกราคม 2551" />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field v-model="lawMetaForm.effective_date" label="วันที่มีผลบังคับ" />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field v-model="lawMetaForm.gazette_reference" label="ราชกิจจานุเบกษา" placeholder="เล่ม 125 ตอนที่ 5 ก" />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field v-model="lawMetaForm.royal_command" label="พระบรมราชโองการ" placeholder="ภูมิพลอดุลยเดช ปร." />
          </v-col>
          <v-col cols="12">
            <v-textarea v-model="repealedText" label="กฎหมายที่ถูกยกเลิก (บรรทัดละ 1 รายการ)" rows="3" />
          </v-col>
        </v-row>

        <div class="mt-3">
          <div class="d-flex align-center justify-space-between text-body-2 font-weight-bold mb-2" style="color:#1a3673">
            <span>ความสัมพันธ์ระดับเอกสาร</span>
            <v-btn size="x-small" variant="outlined" prepend-icon="mdi-plus"
              @click="openRelationDialog('document', null, 'related')">เพิ่ม</v-btn>
          </div>
          <div class="d-flex flex-wrap ga-2">
            <v-chip v-for="rel in documentRelations(relations)" :key="rel.id" size="small" closable
              :color="rel.type === 'repeals' ? 'error' : 'primary'" variant="tonal"
              :prepend-icon="rel.type === 'repeals' ? 'mdi-cancel' : 'mdi-link-variant'"
              @click:close="removeRelation(rel.id)">
              {{ rel.target_title }}
            </v-chip>
          </div>
        </div>
      </v-card>

      <AddRelationDialog v-if="dialog" :scope="dialog.scope" :block-id="dialog.blockId" :default-type="dialog.type"
        @close="dialog = null" @save="onRelationSaved" />
    </template>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useDocumentStore } from '../../stores/documentStore';
import type { LawMeta, LawRelation, RelationScope, RelationType } from '../../types/document';
import AppShell from '../../components/shared/AppShell.vue';
import AddRelationDialog from '../../components/shared/AddRelationDialog.vue';
import { documentRelations } from '../../composables/useLawSections';

const props = defineProps<{ documentId: string }>();

const router = useRouter();
const documentStore = useDocumentStore();

const repealedText = ref('');

const EMPTY_LAW_META: LawMeta = {
  status: '',
  law_type: '',
  law_group: '',
  law_groups: [],
  agency: '',
  agencies: [],
  promulgation_date: '',
  effective_date: '',
  published_date: '',
  expiry_date: null,
  section_count: null,
  title: '',
  gazette_reference: '',
  royal_command: '',
  repealed_laws: [],
};

const lawMetaForm = ref<LawMeta>({ ...EMPTY_LAW_META });
const relations = computed<LawRelation[]>(() => documentStore.review?.relations ?? []);
const dialog = ref<{ scope: RelationScope; blockId: string | null; type: RelationType } | null>(null);

function openRelationDialog(scope: RelationScope, blockId: string | null, type: RelationType): void {
  dialog.value = { scope, blockId, type };
}

async function onRelationSaved(relation: LawRelation): Promise<void> {
  dialog.value = null;
  await documentStore.saveRelations([...relations.value, relation]);
}

async function removeRelation(id: string): Promise<void> {
  await documentStore.saveRelations(relations.value.filter((r) => r.id !== id));
}

watch(() => documentStore.review?.law_meta, (meta) => {
  const nextMeta = {
    ...EMPTY_LAW_META,
    ...(meta ?? {}),
    repealed_laws: [...(meta?.repealed_laws ?? [])],
  };
  lawMetaForm.value = nextMeta;
  repealedText.value = nextMeta.repealed_laws.join('\n');
}, { immediate: true });

async function saveAndContinue(): Promise<void> {
  const repealedLaws = repealedText.value
    .split('\n')
    .map((entry) => entry.trim())
    .filter(Boolean);

  const payload: LawMeta = { ...lawMetaForm.value, repealed_laws: repealedLaws };
  const saved = await documentStore.saveLawMeta(payload);
  if (saved) {
    router.push(`/documents/${props.documentId}/rag`);
  }
}

onMounted(() => documentStore.fetch(props.documentId));
onBeforeUnmount(() => documentStore.reset());
</script>
