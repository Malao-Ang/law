<template>
  <AppShell
    :breadcrumbs="['จัดการข้อมูลระบบ', 'สถานะการเปลี่ยนแปลงกฎหมาย']"
    title="สถานะการเปลี่ยนแปลงกฎหมาย"
    subtitle="จัดการสถานะการเปลี่ยนแปลงของเอกสารกฎหมาย"
    show-bell
  >
    <div class="change-status-page">
      <v-tabs v-model="tab" color="admin-primary" density="comfortable">
        <v-tab value="status">สถานะการเปลี่ยนแปลง</v-tab>
        <v-tab value="detail">รายละเอียดการเปลี่ยนแปลง</v-tab>
      </v-tabs>

      <v-window v-model="tab">
        <v-window-item value="status">
          <MasterDataPage
            kind="change_status"
            title="สถานะการเปลี่ยนแปลงกฎหมาย"
            subtitle="จัดการสถานะการเปลี่ยนแปลงของเอกสารกฎหมาย"
            add-label=""
            :stat-labels="statLabels"
            :columns="statusColumns"
            search-placeholder="ค้นหาด้วยรหัส หรือชื่อสถานะ..."
            :dialog-title="{ create: '', edit: '' }"
            dialog-subtitle=""
            :field-labels="fieldLabels"
            code-column-title="รหัสสถานะ"
            embedded
            readonly
          >
            <template #view-dialog="{ open, item, close }">
              <v-dialog :model-value="open" max-width="620" @update:model-value="close">
                <v-card rounded="xl" class="change-status-view">
                  <div class="change-status-view__header">
                    <div>
                      <h2 class="change-status-view__title">รายละเอียดสถานะการเปลี่ยนแปลงกฎหมาย</h2>
                      <p class="change-status-view__subtitle">{{ item?.code }}</p>
                    </div>
                    <v-btn icon="mdi-close" variant="text" size="small" @click="close" />
                  </div>

                  <v-card-text v-if="item" class="change-status-view__body">
                    <div class="change-status-view__grid">
                      <div class="change-status-view__field">
                        <span>รหัส</span>
                        <strong>{{ item.code }}</strong>
                      </div>
                      <div class="change-status-view__field">
                        <span>ชื่อ</span>
                        <strong>{{ item.name }}</strong>
                      </div>
                      <div class="change-status-view__field">
                        <span>ใช้กับ</span>
                        <strong>{{ sourceLabel(item.attrs.source) }}</strong>
                      </div>
                      <div class="change-status-view__field">
                        <span>มีรายละเอียดย่อย</span>
                        <strong>{{ item.attrs.has_details === true ? 'ใช่' : 'ไม่ใช่' }}</strong>
                      </div>
                      <div class="change-status-view__field">
                        <span>จำนวนเอกสารที่ใช้</span>
                        <strong>{{ (item.usage_count ?? 0).toLocaleString('th-TH') }}</strong>
                      </div>
                    </div>
                    <v-alert type="info" variant="tonal" density="compact" class="mt-4">
                      ข้อมูลนี้ใช้กับระบบความสัมพันธ์ของกฎหมาย จึงแก้ไขไม่ได้
                    </v-alert>
                  </v-card-text>

                  <v-divider />
                  <v-card-actions class="justify-end pa-4">
                    <v-btn variant="outlined" class="text-none" @click="close">ปิด</v-btn>
                  </v-card-actions>
                </v-card>
              </v-dialog>
            </template>
          </MasterDataPage>
        </v-window-item>

        <v-window-item value="detail">
          <MasterDataPage
            kind="change_detail"
            title="รายละเอียดการเปลี่ยนแปลงกฎหมาย"
            subtitle="จัดการรายละเอียดการเปลี่ยนแปลงของเอกสารกฎหมาย"
            add-label=""
            :stat-labels="statLabels"
            :columns="detailColumns"
            search-placeholder="ค้นหาด้วยรหัส หรือชื่อสถานะ..."
            :dialog-title="{ create: '', edit: '' }"
            dialog-subtitle=""
            :field-labels="fieldLabels"
            code-column-title="รหัสสถานะ"
            embedded
            readonly
          >
            <template #item.role="{ item }">
              <v-chip
                :color="roleColor(item)"
                variant="tonal"
                size="small"
                :prepend-icon="roleIcon(item)"
                class="font-weight-medium"
              >
                {{ roleLabel(item.attrs.role) }}
              </v-chip>
            </template>

            <template #view-dialog="{ open, item, close }">
              <v-dialog :model-value="open" max-width="620" @update:model-value="close">
                <v-card rounded="xl" class="change-status-view">
                  <div class="change-status-view__header">
                    <div>
                      <h2 class="change-status-view__title">รายละเอียดการเปลี่ยนแปลงกฎหมาย</h2>
                      <p class="change-status-view__subtitle">{{ item?.code }}</p>
                    </div>
                    <v-btn icon="mdi-close" variant="text" size="small" @click="close" />
                  </div>

                  <v-card-text v-if="item" class="change-status-view__body">
                    <div class="change-status-view__grid">
                      <div class="change-status-view__field">
                        <span>รหัส</span>
                        <strong>{{ item.code }}</strong>
                      </div>
                      <div class="change-status-view__field">
                        <span>ชื่อ</span>
                        <strong>{{ item.name }}</strong>
                      </div>
                      <div class="change-status-view__field">
                        <span>ใช้กับ</span>
                        <strong>{{ sourceLabel(item.attrs.source) }}</strong>
                      </div>
                      <div class="change-status-view__field">
                        <span>ประเภทความสัมพันธ์</span>
                        <v-chip
                          :color="roleColor(item)"
                          variant="tonal"
                          size="small"
                          :prepend-icon="roleIcon(item)"
                          class="align-self-start font-weight-medium"
                        >
                          {{ roleLabel(item.attrs.role) }}
                        </v-chip>
                      </div>
                      <div class="change-status-view__field">
                        <span>จำนวนเอกสารที่ใช้</span>
                        <strong>{{ (item.usage_count ?? 0).toLocaleString('th-TH') }}</strong>
                      </div>
                    </div>
                    <v-alert type="info" variant="tonal" density="compact" class="mt-4">
                      ข้อมูลนี้ใช้กับระบบความสัมพันธ์ของกฎหมาย จึงแก้ไขไม่ได้
                    </v-alert>
                  </v-card-text>

                  <v-divider />
                  <v-card-actions class="justify-end pa-4">
                    <v-btn variant="outlined" class="text-none" @click="close">ปิด</v-btn>
                  </v-card-actions>
                </v-card>
              </v-dialog>
            </template>
          </MasterDataPage>
        </v-window-item>
      </v-window>
    </div>
  </AppShell>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppShell from '../../../components/shared/AppShell.vue';
import MasterDataPage from '../../../components/master/MasterDataPage.vue';
import type { MasterItem } from '../../../types/masterData';

type TabValue = 'status' | 'detail';

const route = useRoute();
const router = useRouter();

const tab = computed<TabValue>({
  get: () => route.query.tab === 'detail' ? 'detail' : 'status',
  set: (value) => {
    void router.replace({
      query: {
        ...route.query,
        tab: value === 'detail' ? 'detail' : undefined,
      },
    });
  },
});

const statLabels = {
  total: 'สถานะทั้งหมด',
  active: 'รายการที่ใช้งาน',
  inactive: 'รายการที่ปิดใช้งาน',
};

const fieldLabels = {
  code: 'รหัส',
  name: 'ชื่อ',
};

const statusColumns = [
  { key: 'name', title: 'ชื่อสถานะ', width: '320px' },
];

const detailColumns = [
  { key: 'name', title: 'ชื่อสถานะ', width: '280px' },
  { key: 'role', title: 'ประเภทความสัมพันธ์', width: '220px' },
];

function sourceLabel(source: unknown): string {
  if (source === 'internal') return 'กฎหมายภายใน';
  if (source === 'external') return 'กฎหมายภายนอก';
  return 'ทั้งหมด';
}

function roleLabel(role: unknown): string {
  return role === 'repeals' ? 'ยกเลิก' : 'แก้ไข';
}

function roleColor(item: MasterItem | null | undefined): string {
  return typeof item?.attrs.color === 'string' && item.attrs.color ? item.attrs.color : 'teal';
}

function roleIcon(item: MasterItem | null | undefined): string {
  return typeof item?.attrs.icon === 'string' && item.attrs.icon ? item.attrs.icon : 'mdi-pencil';
}
</script>

<style scoped>
.change-status-page {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.change-status-view {
  overflow: hidden;
}

.change-status-view__header {
  align-items: flex-start;
  display: flex;
  justify-content: space-between;
  padding: 20px 22px 12px;
}

.change-status-view__title {
  color: #1f2933;
  font-size: 18px;
  font-weight: 800;
  line-height: 1.35;
  margin: 0;
}

.change-status-view__subtitle {
  color: #667085;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  font-size: 13px;
  margin: 4px 0 0;
}

.change-status-view__body {
  padding: 8px 22px 22px;
}

.change-status-view__grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.change-status-view__field {
  background: #f9fafb;
  border: 1px solid #eaecf0;
  border-radius: 10px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  padding: 12px;
}

.change-status-view__field span {
  color: #667085;
  font-size: 12px;
}

.change-status-view__field strong {
  color: #1f2933;
  font-size: 14px;
  overflow-wrap: anywhere;
}

@media (max-width: 640px) {
  .change-status-view__grid {
    grid-template-columns: 1fr;
  }
}
</style>
