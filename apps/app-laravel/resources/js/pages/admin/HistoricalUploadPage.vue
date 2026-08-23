<template>
  <AppShell>
    <div class="hist-wrap">
      <WorkflowStepper :step="1" />

      <v-card class="pa-6 mb-4" elevation="0" rounded="lg">
        <h2 class="text-h6 mb-1">กำหนดประเภทเอกสาร</h2>
        <p class="text-body-2 text-medium-emphasis mb-4">
          เลือกแหล่งที่มาและประเภทของตัวบทกฎหมายเพื่อใช้ในการจัดหมวดหมู่
        </p>

        <label class="text-body-2 font-weight-medium">1. เลือกแหล่งที่มาของเอกสาร *</label>
        <v-select
          v-model="source"
          :items="sources"
          item-title="title"
          item-value="value"
          placeholder="เลือกแหล่งที่มาของเอกสาร"
          variant="outlined"
          density="comfortable"
          rounded="lg"
          class="mt-1 mb-4"
          @update:model-value="lawType = null"
        />

        <label class="text-body-2 font-weight-medium">2. เลือกประเภทกฎหมาย *</label>
        <v-select
          v-model="lawType"
          :items="filteredTypes"
          item-title="title"
          item-value="value"
          placeholder="เลือกประเภทกฎหมาย"
          :disabled="!source"
          variant="outlined"
          density="comfortable"
          rounded="lg"
          class="mt-1"
        />
      </v-card>

      <v-card class="pa-6" elevation="0" rounded="lg">
        <h2 class="text-h6 mb-1">อัปโหลดเอกสาร</h2>
        <p class="text-body-2 text-medium-emphasis mb-4">อัปโหลดไฟล์เอกสารที่ต้องการนำเข้าสู่ระบบ</p>

        <v-alert type="info" variant="tonal" density="comfortable" class="mb-4">
          <strong>โหมดเอกสารเก่า (Historical Document)</strong><br />
          หลังจากอัปโหลดเสร็จสิ้น ระบบจะพาไปยังขั้นตอนกรอกข้อมูล และข้ามขั้นตอนการตรวจทาน
        </v-alert>

        <v-file-input
          v-model="file"
          accept="application/pdf"
          label="ลากไฟล์มาวางที่นี่ (PDF เท่านั้น)"
          variant="outlined"
          rounded="lg"
          prepend-icon="mdi-file-upload-outline"
          :error-messages="error ? [error] : []"
        />
      </v-card>

      <div class="d-flex justify-space-between mt-4">
        <v-btn variant="text" prepend-icon="mdi-arrow-left" @click="router.push('/admin/upload')">ย้อนกลับ</v-btn>
        <v-btn color="primary" :loading="submitting" :disabled="!canSubmit" append-icon="mdi-arrow-right" @click="submit">
          ถัดไป
        </v-btn>
      </div>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AppShell from '../../components/shared/AppShell.vue';
import WorkflowStepper from '../../components/shared/WorkflowStepper.vue';
import { getLookups } from '../../api/client';
import { useUploadStore } from '../../stores/uploadStore';

const router = useRouter();
const uploadStore = useUploadStore();

const sources = ref<{ title: string; value: string }[]>([]);
const types = ref<{ title: string; value: string; source?: string }[]>([]);
const source = ref<string | null>(null);
const lawType = ref<string | null>(null);
const file = ref<File | File[] | null>(null);
const submitting = ref(false);
const error = ref('');

const filteredTypes = computed(() => types.value.filter((t) => t.source === source.value));
const pickedFile = computed<File | null>(() => (Array.isArray(file.value) ? (file.value[0] ?? null) : file.value));
const canSubmit = computed(() => !!source.value && !!lawType.value && !!pickedFile.value);

onMounted(async () => {
  const lookups = await getLookups();
  sources.value = lookups.law_sources ?? [];
  types.value = lookups.document_types ?? [];
});

async function submit(): Promise<void> {
  const f = pickedFile.value;
  if (!f || !source.value || !lawType.value) return;
  submitting.value = true;
  error.value = '';
  try {
    const id = await uploadStore.upload(f, 'gemini', 'standard', {
      documentType: 'old',
      source: source.value,
      lawType: lawType.value,
    });
    router.push(`/documents/${id}/law-info`);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'อัปโหลดไม่สำเร็จ';
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.hist-wrap { max-width: 960px; margin: 0 auto; padding: 24px 16px; }
</style>
