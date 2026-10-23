<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'บันทึกการเปลี่ยนแปลง']"
    title="บันทึกการเปลี่ยนแปลง"
    subtitle="ดูบันทึกตามเวลา หรือเลือกเอกสารเพื่อดูประวัติการแก้ไข"
  >
    <template #title-actions>
      <div class="d-flex flex-wrap align-center ga-2">
      <v-btn-toggle v-model="viewMode" mandatory density="compact" divided>
        <v-btn value="log" class="text-none">แบบ log</v-btn>
        <v-btn value="group" class="text-none">รายเอกสาร</v-btn>
      </v-btn-toggle>
      <v-btn
        prepend-icon="mdi-refresh"
        variant="tonal"
        color="admin-primary"
        size="small"
        class="text-none"
        :loading="loading"
        @click="load"
      >
        รีเฟรช
      </v-btn>
      </div>
    </template>

    <div class="d-flex flex-wrap ga-3 mb-4 align-center">
      <v-text-field
        v-model="query"
        placeholder="ค้นหาชื่อ รหัสเอกสาร หรือผู้กระทำ"
        prepend-inner-icon="mdi-magnify"
        variant="outlined"
        density="compact"
        hide-details
        style="max-width:420px; flex:1 1 260px"
        @keyup.enter="load"
      />
      <v-select
        v-model="action"
        :items="actionOptions"
        item-title="label"
        item-value="value"
        label="การกระทำ"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        style="max-width:160px"
      />
      <v-select
        v-model="resource"
        :items="resourceOptions"
        item-title="label"
        item-value="value"
        label="ประเภทข้อมูล"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        style="max-width:200px"
      />
      <v-btn color="admin-primary" class="text-none" @click="load">ค้นหา</v-btn>
    </div>

    <v-progress-linear v-if="loading" indeterminate color="admin-primary" class="mb-3" />

    <v-card v-if="viewMode === 'group'" flat border rounded="lg" class="mb-4">
      <div class="pa-4 text-subtitle-1 font-weight-bold">เอกสารที่แก้ไขล่าสุด</div>
      <v-divider />
      <v-table density="comfortable">
        <thead>
          <tr>
            <th>เอกสาร</th>
            <th>แก้ไขล่าสุด</th>
            <th>จำนวน</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && groups.length === 0">
            <td colspan="4" class="text-center text-medium-emphasis py-8">ยังไม่มีบันทึกที่ตรงกับเงื่อนไข</td>
          </tr>
          <tr
            v-for="group in groups"
            :key="group.key"
            :class="selectedKey === group.key ? 'bg-blue-lighten-5' : ''"
          >
            <td>
              <div class="font-weight-medium">{{ group.title }}</div>
              <div class="text-caption text-medium-emphasis">{{ group.documentId || 'ไม่ได้ผูกกับเอกสาร' }}</div>
            </td>
            <td class="text-caption">{{ formatThaiDateTime(group.latestAt) || '—' }}</td>
            <td>
              <div class="d-flex flex-wrap ga-1">
                <v-chip v-if="countAction(group, 'create')" size="x-small" color="success" variant="tonal">เพิ่ม {{ countAction(group, 'create') }}</v-chip>
                <v-chip v-if="countAction(group, 'update')" size="x-small" color="primary" variant="tonal">แก้ไข {{ countAction(group, 'update') }}</v-chip>
                <v-chip v-if="countAction(group, 'delete')" size="x-small" color="error" variant="tonal">ลบ {{ countAction(group, 'delete') }}</v-chip>
              </div>
            </td>
            <td class="text-right">
              <v-btn size="small" variant="tonal" color="admin-primary" class="text-none" @click="selectGroup(group.key)">
                {{ selectedKey === group.key ? 'กำลังดู' : 'ดูประวัติ' }}
              </v-btn>
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <v-card v-if="viewMode === 'log' || selectedGroup" flat border rounded="lg">
      <div class="pa-4 d-flex align-center flex-wrap ga-2">
        <span class="text-subtitle-1 font-weight-bold flex-grow-1">
          {{ viewMode === 'log' ? 'บันทึกตามเวลา' : `ประวัติของ ${selectedGroup?.title}` }}
        </span>
        <v-btn v-if="viewMode === 'group'" variant="text" class="text-none" @click="selectedKey = null">ปิดประวัติ</v-btn>
      </div>
      <v-divider />
      <v-table density="comfortable">
        <thead>
          <tr>
            <th>เวลา</th>
            <th>การกระทำ</th>
            <th>ประเภท</th>
            <th>รายการ</th>
            <th>ผู้กระทำ</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && tableItems.length === 0">
            <td colspan="6" class="text-center text-medium-emphasis py-8">ยังไม่มีบันทึกที่ตรงกับเงื่อนไข</td>
          </tr>
          <tr v-for="item in tableItems" :key="item.id">
            <td class="text-caption">{{ formatThaiDateTime(item.at) || '—' }}</td>
            <td>
              <v-chip size="small" :color="actionColor(item.action)" variant="tonal">{{ item.action_label }}</v-chip>
            </td>
            <td>{{ item.resource_label }}</td>
            <td>
              <div class="font-weight-medium">{{ item.label || item.resource_id }}</div>
              <div class="text-caption text-medium-emphasis">{{ item.summary }}</div>
            </td>
            <td>
              <div>{{ item.actor_name || 'ไม่ระบุตัวตน' }}</div>
              <div v-if="item.actor_role" class="text-caption text-medium-emphasis">{{ item.actor_role }}</div>
            </td>
            <td class="text-right">
              <v-btn size="small" variant="text" class="text-none" @click="openDetail(item)">ดู</v-btn>
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <div v-else-if="viewMode === 'group' && !loading && groups.length > 0" class="text-body-2 text-medium-emphasis">
      เลือกเอกสารจากรายการด้านบนเพื่อดูว่ามีการแก้ไขอะไรบ้าง
    </div>

    <div class="text-caption text-medium-emphasis mt-2">แสดงไม่เกิน 200 รายการล่าสุด</div>

    <v-dialog v-model="dialog" max-width="640">
      <v-card v-if="selected">
        <v-card-title class="text-wrap d-flex align-center ga-2">
          <v-chip size="small" :color="actionColor(selected.action)" variant="tonal">{{ selected.action_label }}</v-chip>
          {{ selected.summary }}
        </v-card-title>
        <v-card-text>
          <div class="text-body-2 mb-1"><strong>รายการ:</strong> {{ selected.label || '—' }}</div>
          <div class="text-body-2 mb-1"><strong>ประเภท:</strong> {{ selected.resource_label }}</div>
          <div class="text-body-2 mb-1"><strong>รหัส:</strong> {{ selected.resource_id }}</div>
          <div v-if="selected.document_id" class="text-body-2 mb-1"><strong>เอกสาร:</strong> {{ selected.document_id }}</div>
          <div class="text-body-2 mb-1"><strong>ผู้กระทำ:</strong> {{ selected.actor_name || 'ไม่ระบุตัวตน' }}<span v-if="selected.actor_role"> ({{ selected.actor_role }})</span></div>
          <div class="text-body-2 mb-1"><strong>เวลา:</strong> {{ formatThaiDateTime(selected.at) || '—' }}</div>
          <div class="text-body-2 mb-3"><strong>IP:</strong> {{ selected.ip || '—' }}</div>
          <div class="text-subtitle-2 font-weight-bold mb-2">รายละเอียด</div>
          <div v-if="selected.changes.length === 0" class="text-medium-emphasis">ไม่มีรายละเอียดเพิ่มเติม</div>
          <ul v-else class="pl-5">
            <li v-for="(change, index) in selected.changes" :key="index">{{ change }}</li>
          </ul>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" class="text-none" @click="dialog = false">ปิด</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { fetchAuditLogs } from '../../api/client';
import AppShell from '../../components/shared/AppShell.vue';
import type { AuditLogItem, AuditLogOption } from '../../types/auditLog';
import { formatThaiDateTime } from '../../utils/thaiDate';

interface AuditGroup {
  key: string;
  title: string;
  documentId: string | null;
  items: AuditLogItem[];
  latestAt: string;
}

const items = ref<AuditLogItem[]>([]);
const actions = ref<AuditLogOption[]>([]);
const resources = ref<AuditLogOption[]>([]);
const loading = ref(false);
const query = ref('');
const action = ref('');
const resource = ref('');
const viewMode = ref<'log' | 'group'>('log');
const selectedKey = ref<string | null>(null);
const dialog = ref(false);
const selected = ref<AuditLogItem | null>(null);

const groups = computed<AuditGroup[]>(() => {
  const buckets = new Map<string, AuditLogItem[]>();
  for (const item of items.value) {
    const key = item.document_id || '__none__';
    const bucket = buckets.get(key) ?? [];
    bucket.push(item);
    buckets.set(key, bucket);
  }

  return [...buckets.entries()]
    .map(([key, groupItems]) => ({
      key,
      title: key === '__none__' ? 'ไม่ผูกกับเอกสาร' : groupTitle(groupItems),
      documentId: key === '__none__' ? null : key,
      items: [...groupItems].sort((a, b) => (a.at < b.at ? 1 : -1)),
      latestAt: groupItems.reduce((latest, item) => (item.at > latest ? item.at : latest), ''),
    }))
    .sort((a, b) => {
      if (a.latestAt === b.latestAt) return a.title.localeCompare(b.title, 'th');
      return a.latestAt < b.latestAt ? 1 : -1;
    });
});

const selectedGroup = computed(() => groups.value.find((group) => group.key === selectedKey.value) ?? null);
const tableItems = computed(() => (viewMode.value === 'log' ? items.value : selectedGroup.value?.items ?? []));
const actionOptions = computed(() => [{ value: '', label: 'ทั้งหมด' }, ...actions.value]);
const resourceOptions = computed(() => [{ value: '', label: 'ทั้งหมด' }, ...resources.value]);

onMounted(() => {
  void load();
});

watch([action, resource], () => {
  void load();
});

watch(groups, (next) => {
  if (selectedKey.value && !next.some((group) => group.key === selectedKey.value)) {
    selectedKey.value = null;
  }
});

async function load(): Promise<void> {
  loading.value = true;
  try {
    const result = await fetchAuditLogs({
      action: action.value || undefined,
      resource: resource.value || undefined,
      q: query.value.trim() || undefined,
    });
    items.value = result.items;
    actions.value = result.actions;
    resources.value = result.resources;
  } finally {
    loading.value = false;
  }
}

function groupTitle(groupItems: AuditLogItem[]): string {
  const oversight = groupItems.find((item) => item.resource === 'oversight' && item.label);
  if (oversight) return oversight.label;
  const renamed = groupItems.find((item) => item.resource === 'document' && item.summary.startsWith('แก้ไข') && item.label);
  if (renamed) return renamed.label;
  for (const item of groupItems) {
    const line = item.changes.find((change) => change.startsWith('เอกสาร: '));
    const title = line?.slice('เอกสาร: '.length).trim();
    if (title && title !== '—') return title;
  }
  const named = groupItems.find((item) => item.label && item.label !== item.document_id);
  return named?.label || groupItems[0]?.document_id || 'ไม่ระบุเอกสาร';
}

function countAction(group: AuditGroup, actionName: string): number {
  return group.items.filter((item) => item.action === actionName).length;
}

function selectGroup(key: string): void {
  selectedKey.value = key;
}

function openDetail(item: AuditLogItem): void {
  selected.value = item;
  dialog.value = true;
}

function actionColor(value: string): string {
  if (value === 'create') return 'success';
  if (value === 'delete') return 'error';
  return 'primary';
}
</script>
