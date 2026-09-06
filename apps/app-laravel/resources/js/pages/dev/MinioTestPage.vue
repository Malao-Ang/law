<template>
  <div class="minio-test">
    <AppShell :breadcrumbs="['Dev', 'MinIO Test']" title="MinIO Test" hide-top-bar>
      <p class="text-caption text-medium-emphasis mb-4">Base: {{ base }}</p>

      <!-- List -->
      <v-card flat border rounded="lg" class="mb-4 pa-5">
        <div class="text-subtitle-2 font-weight-bold mb-3">1. List files (GET /api/test/minio)</div>
        <v-btn color="admin-primary" size="small" :loading="listing" @click="listFiles">List</v-btn>
        <pre v-if="listResult" class="mt-3 result-box" :class="listResult.error ? 'is-error' : 'is-ok'">{{ JSON.stringify(listResult, null, 2) }}</pre>
      </v-card>

      <!-- Upload -->
      <v-card flat border rounded="lg" class="mb-4 pa-5">
        <div class="text-subtitle-2 font-weight-bold mb-3">2. Upload file (POST /api/test/minio/upload)</div>
        <v-file-input v-model="uploadFiles" label="เลือกไฟล์" variant="outlined" density="compact" hide-details class="mb-3" />
        <v-btn color="admin-primary" size="small" :loading="uploading" :disabled="!uploadFiles?.length" @click="uploadFile">Upload</v-btn>
        <pre v-if="uploadResult" class="mt-3 result-box" :class="uploadResult.error ? 'is-error' : 'is-ok'">{{ JSON.stringify(uploadResult, null, 2) }}</pre>
      </v-card>

      <!-- Presign -->
      <v-card flat border rounded="lg" class="mb-4 pa-5">
        <div class="text-subtitle-2 font-weight-bold mb-3">3. Presign URL (POST /api/test/minio/presign)</div>
        <v-text-field v-model="presignFilename" label="MinIO filename" placeholder="doc_xxx/GcGEQx.pdf" variant="outlined" density="compact" hide-details class="mb-3" />
        <v-btn color="admin-primary" size="small" :loading="presigning" :disabled="!presignFilename" @click="presign">Get URL</v-btn>
        <pre v-if="presignResult" class="mt-3 result-box" :class="presignResult.error ? 'is-error' : 'is-ok'">{{ JSON.stringify(presignResult, null, 2) }}</pre>
      </v-card>
    </AppShell>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import AppShell from '../../components/shared/AppShell.vue';

const base = window.location.origin;

const listing = ref(false);
const uploading = ref(false);
const presigning = ref(false);

const listResult = ref<Record<string, unknown> | null>(null);
const uploadResult = ref<Record<string, unknown> | null>(null);
const presignResult = ref<Record<string, unknown> | null>(null);

const uploadFiles = ref<File[] | null>(null);
const presignFilename = ref('');

async function api(method: string, path: string, body?: FormData | Record<string, unknown>) {
  const opts: RequestInit = { method, headers: {} };
  if (body instanceof FormData) {
    opts.body = body;
  } else if (body) {
    (opts.headers as Record<string, string>)['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  const res = await fetch(base + path, opts);
  return res.json() as Promise<Record<string, unknown>>;
}

async function listFiles() {
  listing.value = true;
  try { listResult.value = await api('GET', '/api/test/minio'); }
  catch (e) { listResult.value = { error: (e as Error).message }; }
  finally { listing.value = false; }
}

async function uploadFile() {
  const file = uploadFiles.value?.[0];
  if (!file) return;
  uploading.value = true;
  const fd = new FormData();
  fd.append('file', file);
  try {
    uploadResult.value = await api('POST', '/api/test/minio/upload', fd);
    if (uploadResult.value?.minio_filename) {
      presignFilename.value = String(uploadResult.value.minio_filename);
    }
  } catch (e) { uploadResult.value = { error: (e as Error).message }; }
  finally { uploading.value = false; }
}

async function presign() {
  if (!presignFilename.value) return;
  presigning.value = true;
  try { presignResult.value = await api('POST', '/api/test/minio/presign', { filename: presignFilename.value }); }
  catch (e) { presignResult.value = { error: (e as Error).message }; }
  finally { presigning.value = false; }
}
</script>

<style scoped>
.result-box {
  background: #1e1e1e;
  color: #d4d4d4;
  padding: 12px;
  border-radius: 6px;
  font-size: 12px;
  overflow: auto;
  max-height: 280px;
}
.is-error { border-left: 4px solid #ef4444; }
.is-ok { border-left: 4px solid #22c55e; }
</style>
