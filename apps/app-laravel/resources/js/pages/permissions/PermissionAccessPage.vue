<template>
  <AppShell
    :breadcrumbs="['การจัดการข้อมูล', 'การนำเข้าข้อมูล', 'กำหนดสิทธิ์การเข้าถึง']"
    title="นำเข้าเอกสารกฎหมาย"
    subtitle="ขั้นตอนที่ 6 จาก 6: กำหนดสิทธิ์การเข้าถึง"
  >
    <WorkflowFooterBar
      :step="6"
      next-label="บันทึกและดำเนินการต่อ"
      :next-loading="documentStore.saving"
      :next-disabled="nextDisabled"
      @back="router.push(`/documents/${props.documentId}/relations`)"
      @next="saveAndPublish"
    />

    <div class="mx-auto" style="max-width:960px; padding-bottom:60px">
      <WorkflowStepper :step="6" />

      <div v-if="documentStore.loading" class="d-flex flex-column align-center justify-center pa-12 ga-3 text-medium-emphasis">
        <v-progress-circular indeterminate color="admin-primary" />
        <span>กำลังโหลด...</span>
      </div>

      <template v-else>
        <v-alert v-if="documentStore.saveError" type="error" variant="tonal" density="compact" closable class="mb-4" @click:close="documentStore.setSaveError()">
          {{ documentStore.saveError }}
        </v-alert>

        <v-card flat border rounded="xl" class="pa-6">
          <div class="d-flex align-center ga-2 mb-6">
            <v-icon icon="mdi-shield-lock-outline" color="admin-primary" size="20" />
            <span class="text-subtitle-1 font-weight-bold">จัดการกำหนดสิทธิ์การเข้าถึงสำหรับเอกสารฉบับนี้</span>
          </div>

          <v-row dense class="mb-4">
            <v-col cols="12" md="6">
              <AccessScopeCard
                title="Public"
                subtitle="ทุกคนเห็นได้"
                icon="mdi-earth"
                color="#28a56f"
                :selected="scope === 'public'"
                @select="scope = 'public'"
              />
            </v-col>
            <v-col cols="12" md="6">
              <AccessScopeCard
                title="Private"
                subtitle="เฉพาะกลุ่ม"
                icon="mdi-lock-outline"
                color="#d27c21"
                :selected="scope === 'private'"
                @select="scope = 'private'"
              />
            </v-col>
          </v-row>

          <v-card v-if="scope === 'public'" flat border rounded="xl" class="pa-8 text-center">
            <v-icon icon="mdi-earth" size="40" color="grey" class="mb-2" />
            <div class="text-subtitle-1 font-weight-bold">เปิดเผยข้อมูลสู่สาธารณะ</div>
            <div class="text-body-2 text-medium-emphasis">ทุกคนสามารถสืบค้นและเข้าถึงได้จากหน้าสาธารณะ</div>
          </v-card>

          <template v-else>
            <v-card flat border rounded="xl" class="pa-4 mb-4">
              <div class="d-flex flex-column flex-md-row align-md-center justify-space-between ga-3 mb-3">
                <div>
                  <div class="text-subtitle-1 font-weight-bold">ค้นหากลุ่มสิทธิ์</div>
                  <div class="text-body-2 text-medium-emphasis">เลือกอย่างน้อย 1 กลุ่มเพื่อกำหนดผู้มีสิทธิ์เข้าถึง</div>
                </div>
                <v-btn color="admin-primary" prepend-icon="mdi-plus" @click="createDialog = true">สร้างกลุ่มใหม่</v-btn>
              </div>

              <v-text-field
                v-model="search"
                label="ค้นหากลุ่มสิทธิ์"
                variant="outlined"
                prepend-inner-icon="mdi-magnify"
                hide-details
                class="mb-4"
              />

              <div v-if="groupsLoading" class="d-flex align-center ga-2 text-body-2 text-medium-emphasis pa-4">
                <v-progress-circular indeterminate size="18" />
                กำลังโหลดกลุ่มสิทธิ์...
              </div>

              <v-list v-else density="comfortable" class="permission-result-list">
                <v-list-item
                  v-for="group in availableGroups"
                  :key="group.id"
                  :title="group.name"
                  :subtitle="group.description || undefined"
                >
                  <template #prepend>
                    <v-avatar color="admin-primary" size="34">
                      <v-icon icon="mdi-account-key-outline" color="white" size="18" />
                    </v-avatar>
                  </template>

                  <template #append>
                    <div class="d-flex align-center ga-1">
                      <v-btn icon="mdi-plus" variant="text" color="admin-primary" size="small" @click="addGroup(group.id)" />
                      <v-btn icon="mdi-eye-outline" variant="text" size="small" @click="showGroupDetail(group.id)" />
                    </div>
                  </template>
                </v-list-item>

                <div v-if="!groupsLoading && availableGroups.length === 0" class="text-body-2 text-medium-emphasis pa-4">
                  ไม่พบกลุ่มสิทธิ์ที่ตรงกับคำค้น
                </div>
              </v-list>
            </v-card>

            <div class="d-flex align-center justify-space-between mb-3">
              <div class="text-subtitle-1 font-weight-bold">กลุ่มที่เลือกแล้ว ({{ selectedGroups.length }})</div>
              <v-chip size="small" color="admin-primary" variant="tonal">{{ selectedGroups.length }} กลุ่ม</v-chip>
            </div>

            <div v-if="selectedGroups.length === 0" class="permission-empty-state">
              <v-icon icon="mdi-lock-outline" size="36" color="grey-lighten-1" />
              <div class="text-subtitle-2 font-weight-bold mt-2">ยังไม่ได้เลือกกลุ่มสิทธิ์</div>
              <div class="text-body-2 text-medium-emphasis">ค้นหากลุ่มจากด้านบนหรือสร้างกลุ่มใหม่เพื่อเริ่มต้น</div>
            </div>

            <div v-else class="d-flex flex-column ga-3">
              <PermissionGroupCard
                v-for="group in selectedGroups"
                :key="group.id"
                :group="group"
                removable
                @detail="showGroupDetail"
                @remove="removeGroup"
              />
            </div>

            <v-alert v-if="selectedGroups.length === 0" variant="tonal" color="warning" class="mt-4">
              ต้องเลือกกลุ่มสิทธิ์อย่างน้อย 1 กลุ่มเมื่อกำหนดเป็น Private
            </v-alert>
          </template>
        </v-card>
      </template>
    </div>

    <PermissionGroupFormDialog
      v-model="createDialog"
      :directory="directory"
      :saving="groupSaving"
      @save="createGroupFromDialog"
    />
    <PermissionGroupDetailDialog
      v-model="detailDialog"
      :group="detailGroup"
    />
  </AppShell>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  createPermissionGroup,
  fetchPermissionDirectory,
  fetchPermissionGroup,
  listPermissionGroups,
} from '../../api/client';
import { useDocumentStore } from '../../stores/documentStore';
import type { PermissionDirectoryResponse, PermissionGroup, UpsertPermissionGroupPayload } from '../../types/permission';
import AppShell from '../../components/shared/AppShell.vue';
import WorkflowStepper from '../../components/shared/WorkflowStepper.vue';
import WorkflowFooterBar from '../../components/shared/WorkflowFooterBar.vue';
import AccessScopeCard from '../../components/permissions/AccessScopeCard.vue';
import PermissionGroupCard from '../../components/permissions/PermissionGroupCard.vue';
import PermissionGroupDetailDialog from '../../components/permissions/PermissionGroupDetailDialog.vue';
import PermissionGroupFormDialog from '../../components/permissions/PermissionGroupFormDialog.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const documentStore = useDocumentStore();

const scope = ref<'public' | 'private'>('public');
const selectedGroupIds = ref<string[]>([]);
const search = ref('');
const groups = ref<PermissionGroup[]>([]);
const directory = ref<PermissionDirectoryResponse | null>(null);
const groupsLoading = ref(false);
const groupSaving = ref(false);
const createDialog = ref(false);
const detailDialog = ref(false);
const detailGroup = ref<PermissionGroup | null>(null);

const selectedGroups = computed(() =>
  selectedGroupIds.value
    .map((id) => groups.value.find((group) => group.id === id) ?? null)
    .filter((group): group is PermissionGroup => group !== null),
);

const availableGroups = computed(() => {
  const needle = search.value.trim().toLowerCase();
  return groups.value
    .filter((group) => !selectedGroupIds.value.includes(group.id))
    .filter((group) => {
      if (needle === '') return true;
      return group.name.toLowerCase().includes(needle) || (group.description ?? '').toLowerCase().includes(needle);
    });
});

const nextDisabled = computed(() => scope.value === 'private' && selectedGroups.value.length === 0);

function addGroup(groupId: string): void {
  if (!selectedGroupIds.value.includes(groupId)) {
    selectedGroupIds.value = [...selectedGroupIds.value, groupId];
  }
}

function removeGroup(groupId: string): void {
  selectedGroupIds.value = selectedGroupIds.value.filter((id) => id !== groupId);
}

async function loadPermissionResources(): Promise<void> {
  groupsLoading.value = true;
  try {
    const [groupResponse, directoryResponse] = await Promise.all([
      listPermissionGroups(),
      fetchPermissionDirectory(),
    ]);
    groups.value = groupResponse.groups;
    directory.value = directoryResponse;
    selectedGroupIds.value = selectedGroupIds.value.filter((id) => groupResponse.groups.some((group) => group.id === id));
  } catch (error) {
    documentStore.setSaveError(error instanceof Error ? error.message : 'โหลดข้อมูลสิทธิ์ไม่สำเร็จ');
  } finally {
    groupsLoading.value = false;
  }
}

async function showGroupDetail(groupId: string): Promise<void> {
  try {
    detailGroup.value = await fetchPermissionGroup(groupId);
    detailDialog.value = true;
  } catch (error) {
    documentStore.setSaveError(error instanceof Error ? error.message : 'โหลดรายละเอียดกลุ่มสิทธิ์ไม่สำเร็จ');
  }
}

async function createGroupFromDialog(payload: UpsertPermissionGroupPayload): Promise<void> {
  groupSaving.value = true;
  try {
    const group = await createPermissionGroup(payload);
    groups.value = [group, ...groups.value.filter((entry) => entry.id !== group.id)];
    addGroup(group.id);
    createDialog.value = false;
  } catch (error) {
    documentStore.setSaveError(error instanceof Error ? error.message : 'สร้างกลุ่มสิทธิ์ไม่สำเร็จ');
  } finally {
    groupSaving.value = false;
  }
}

async function saveAndPublish(): Promise<void> {
  documentStore.setSaveError();

  const saved = await documentStore.saveLawMeta({
    access_scope: scope.value,
    permission_group_ids: scope.value === 'private' ? selectedGroupIds.value : [],
  });
  if (!saved) return;

  router.push(`/documents/${props.documentId}/result`);
}

onMounted(async () => {
  await documentStore.fetch(props.documentId);
  scope.value = documentStore.review?.law_meta?.access_scope === 'private' ? 'private' : 'public';
  selectedGroupIds.value = [...(documentStore.review?.law_meta?.permission_group_ids ?? [])];
  await loadPermissionResources();
});

onBeforeUnmount(() => documentStore.reset());
</script>

<style scoped>
.permission-result-list {
  border: 1px solid #e2e8f0;
  border-radius: 18px;
  max-height: 320px;
  overflow: auto;
}

.permission-empty-state {
  align-items: center;
  border: 1px dashed #d7dfeb;
  border-radius: 18px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  min-height: 170px;
  padding: 24px;
  text-align: center;
}
</style>
