<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'ข้อมูลเอกสาร']"
    title="นำเข้าเอกสารกฎหมาย"
    subtitle="ขั้นตอนที่ 4 จาก 5: ข้อมูลเอกสาร"
  >
    <div class="mx-auto" style="max-width:860px">

      <div v-if="documentStore.loading" class="d-flex flex-column align-center justify-center pa-12 ga-3 text-medium-emphasis">
        <v-progress-circular indeterminate color="admin-primary" />
        <span>กำลังโหลด...</span>
      </div>

      <template v-else>
        <v-alert v-if="documentStore.saveError" type="error" variant="tonal" density="compact" closable class="mb-4"
          @click:close="documentStore.setSaveError()">
          {{ documentStore.saveError }}
        </v-alert>

        <!-- ข้อมูลพื้นฐาน -->
        <v-card flat border rounded="lg" class="pa-6 mb-4">
          <div class="d-flex align-center ga-2 mb-5">
            <v-icon icon="mdi-file-document-outline" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">ข้อมูลพื้นฐาน</span>
          </div>
          <v-row dense>
            <v-col cols="12">
              <v-text-field
                v-model="form.title"
                label="ชื่อเอกสาร"
                :rules="[v => !!v || 'จำเป็นต้องกรอก']"
                required
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-select
                v-model="form.law_type"
                :items="DOC_TYPES"
                label="ประเภทเอกสาร"
                placeholder="- เลือกประเภทเอกสาร -"
                :rules="[v => !!v || 'จำเป็นต้องเลือก']"
                required
              />
            </v-col>
            <v-col cols="12" sm="6">
              <!-- placeholder col for layout -->
            </v-col>
            <v-col cols="12">
              <v-select
                v-model="form.law_groups"
                :items="LAW_GROUPS"
                label="กลุ่มกฎหมาย"
                placeholder="- เลือกกลุ่มกฎหมาย -"
                multiple
                chips
                closable-chips
                :rules="[v => v.length > 0 || 'จำเป็นต้องเลือกอย่างน้อย 1 กลุ่ม']"
              >
                <template #append-inner>
                  <v-chip v-if="form.law_groups.length" size="x-small" color="admin-primary" class="mr-1">
                    {{ form.law_groups.length }} กลุ่ม
                  </v-chip>
                </template>
              </v-select>
            </v-col>
          </v-row>
        </v-card>

        <!-- ข้อมูลการประกาศใช้ -->
        <v-card flat border rounded="lg" class="pa-6 mb-4">
          <div class="d-flex align-center ga-2 mb-5">
            <v-icon icon="mdi-calendar-check-outline" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">ข้อมูลการประกาศใช้</span>
          </div>
          <v-row dense>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="form.promulgation_date"
                label="วันที่ประกาศ"
                type="date"
                prepend-inner-icon="mdi-calendar"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="form.effective_date"
                label="วันที่มีผลบังคับใช้ *"
                type="date"
                prepend-inner-icon="mdi-calendar"
                :rules="[v => !!v || 'จำเป็นต้องระบุ']"
                required
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="form.expiry_date"
                label="วันที่สิ้นสุดการใช้"
                type="date"
                prepend-inner-icon="mdi-calendar"
                :disabled="noExpiry"
                :placeholder="noExpiry ? 'ไม่มีวันสิ้นสุด' : ''"
              />
              <v-checkbox
                v-model="noExpiry"
                label="ไม่มีวันสิ้นสุด"
                density="compact"
                hide-details
                class="mt-1"
                @update:model-value="v => { if (v) form.expiry_date = null }"
              />
            </v-col>
          </v-row>
        </v-card>

        <!-- โครงสร้างเอกสาร -->
        <v-card flat border rounded="lg" class="pa-6 mb-4">
          <div class="d-flex align-center ga-2 mb-5">
            <v-icon icon="mdi-format-list-numbered" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">โครงสร้างเอกสาร</span>
          </div>
          <v-row dense>
            <v-col cols="12" sm="4">
              <v-text-field
                v-model.number="form.section_count"
                label="จำนวนข้อ"
                type="number"
                min="0"
                placeholder="กรอกตัวเลข"
              />
            </v-col>
          </v-row>
        </v-card>

        <!-- ข้อมูลหน่วยงาน -->
        <v-card flat border rounded="lg" class="pa-6 mb-4">
          <div class="d-flex align-center ga-2 mb-5">
            <v-icon icon="mdi-office-building-outline" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">ข้อมูลหน่วยงาน</span>
          </div>
          <v-row dense>
            <v-col cols="12">
              <div class="d-flex align-center ga-2 mb-2">
                <span class="text-body-2 font-weight-medium">หน่วยงานรับผิดชอบ</span>
                <span class="text-caption text-error">*</span>
                <v-chip v-if="form.agencies.length === 0" size="x-small" color="error" class="ml-1">จำเป็น</v-chip>
              </div>
              <div class="d-flex flex-wrap ga-2 mb-3">
                <v-chip
                  v-for="(ag, idx) in form.agencies"
                  :key="ag + idx"
                  size="small"
                  closable
                  variant="tonal"
                  color="admin-primary"
                  prepend-icon="mdi-office-building-outline"
                  @click:close="form.agencies.splice(idx, 1)"
                >{{ ag }}</v-chip>
              </div>
              <div class="d-flex ga-2 align-center">
                <v-text-field
                  v-model="agencyInput"
                  placeholder="+ เพิ่มหน่วยงาน"
                  density="compact"
                  variant="outlined"
                  hide-details
                  style="max-width:320px"
                  @keydown.enter.prevent="addAgency"
                />
                <v-btn size="small" variant="tonal" color="admin-primary" prepend-icon="mdi-plus" @click="addAgency">
                  เพิ่มหน่วยงาน
                </v-btn>
              </div>
            </v-col>
            <v-col cols="12" sm="6" class="mt-2">
              <v-text-field
                v-model="form.imported_by"
                label="ผู้นำเข้าข้อมูล"
                prepend-inner-icon="mdi-account-outline"
                placeholder="ชื่อผู้นำเข้าข้อมูล"
              />
            </v-col>
          </v-row>
        </v-card>

        <div class="d-flex justify-space-between mt-6">
          <v-btn variant="outlined" prepend-icon="mdi-arrow-left"
            @click="router.push(`/documents/${props.documentId}/rag`)">
            ย้อนกลับ
          </v-btn>
          <v-btn color="admin-primary" append-icon="mdi-arrow-right"
            :loading="documentStore.saving || exporting"
            @click="saveAndExport">
            ถัดไป
          </v-btn>
        </div>
      </template>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useDocumentStore } from '../../stores/documentStore';
import { exportDocument } from '../../api/client';
import type { LawMeta } from '../../types/document';
import AppShell from '../../components/shared/AppShell.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();

const DOC_TYPES = ['พ.ร.บ.', 'ข้อบังคับ', 'ระเบียบ', 'ประกาศ', 'คำสั่ง', 'มติ'];
const LAW_GROUPS = [
  'ด้านวิชาการ',
  'การผลิตบัณฑิต',
  'การเรียนรู้ตลอดชีวิต',
  'การบริหารหลักสูตร',
  'ด้านกิจการนิสิต',
  'ด้านบริหารบุคคล',
  'ด้านการเงินและงบประมาณ',
  'ด้านทรัพย์สินและจัดซื้อจัดจ้าง',
  'ด้านเทคโนโลยีสารสนเทศ',
  'ด้านกฎหมายและนิติการ',
  'ด้านความร่วมมือระหว่างประเทศ',
  'อื่นๆ',
];

const EMPTY: LawMeta = {
  status: '', law_type: '', law_group: '', law_groups: [],
  agency: '', agencies: [], promulgation_date: '', effective_date: '',
  published_date: '', expiry_date: null, section_count: null,
  title: '', gazette_reference: '', royal_command: '', repealed_laws: [],
  imported_by: '',
};

const form = ref<LawMeta>({ ...EMPTY, law_groups: [], agencies: [], repealed_laws: [] });
const noExpiry = ref(false);
const agencyInput = ref('');
const exporting = ref(false);

watch(() => documentStore.review, (review) => {
  const meta = review?.law_meta;
  form.value = {
    ...EMPTY,
    ...(meta ?? {}),
    law_groups: [...(meta?.law_groups ?? [])],
    agencies: [...(meta?.agencies ?? [])],
    repealed_laws: [...(meta?.repealed_laws ?? [])],
    title: meta?.title || review?.source_file || '',
    expiry_date: meta?.expiry_date ?? null,
  };
  noExpiry.value = meta?.expiry_date === null && !!meta?.title;
}, { immediate: true });

function addAgency(): void {
  const trimmed = agencyInput.value.trim();
  if (trimmed && !form.value.agencies.includes(trimmed)) {
    form.value.agencies.push(trimmed);
  }
  agencyInput.value = '';
}

async function saveAndExport(): Promise<void> {
  const saved = await documentStore.saveLawMeta({ ...form.value });
  if (!saved) return;
  exporting.value = true;
  try {
    await exportDocument(props.documentId);
    router.push(`/law/${props.documentId}`);
  } catch {
    documentStore.setSaveError('ส่งออกไม่สำเร็จ — ลองอีกครั้ง');
  } finally {
    exporting.value = false;
  }
}

onMounted(() => documentStore.fetch(props.documentId));
onBeforeUnmount(() => documentStore.reset());
</script>
