<template>
  <AppShell
    :breadcrumbs="['จัดการข้อมูลระบบ', 'สถานะการเปลี่ยนแปลงกฎหมาย']"
    title="สถานะการเปลี่ยนแปลงกฎหมาย"
    subtitle="จัดการสถานะการเปลี่ยนแปลงของเอกสารกฎหมาย"
    show-bell
  >
    <template #title-actions>
      <v-btn v-if="tab === 'status'" color="admin-primary" prepend-icon="mdi-plus" class="text-none" @click="statusPage?.openCreate()">
        เพิ่มสถานะการเปลี่ยนแปลง
      </v-btn>
      <v-btn v-else color="admin-primary" prepend-icon="mdi-plus" class="text-none" @click="detailPage?.openCreate()">
        เพิ่มรายละเอียดการเปลี่ยนแปลง
      </v-btn>
    </template>
    <div class="change-status-page">
      <v-tabs v-model="tab" color="admin-primary" density="comfortable">
        <v-tab value="status">สถานะการเปลี่ยนแปลง</v-tab>
        <v-tab value="detail">รายละเอียดการเปลี่ยนแปลง</v-tab>
      </v-tabs>

      <v-window v-model="tab">
        <v-window-item value="status">
          <v-alert type="info" variant="tonal" class="mb-4 role-guide" density="comfortable">
            <div class="role-guide__title">
              <v-icon icon="mdi-information-outline" size="20" />
              บทบาทคืออะไร?
            </div>
            <p class="role-guide__lead">
              บทบาทบอกระบบว่ากฎหมายฉบับนี้เกี่ยวข้องกับฉบับก่อนอย่างไร และจะเปิดหน้าจอผูกความสัมพันธ์แบบไหน
            </p>
            <div class="role-guide__list">
              <div v-for="role in statusRoleOptions" :key="role.value" class="role-guide__item">
                <v-chip size="small" :color="roleGuideColor(role.value)" variant="tonal" :prepend-icon="roleGuideIcon(role.value)">
                  {{ role.title }}
                </v-chip>
                <div class="role-guide__text">
                  <strong>{{ role.summary }}</strong>
                  <span>{{ role.detail }}</span>
                </div>
              </div>
            </div>
          </v-alert>

          <MasterDataPage
            ref="statusPage"
            kind="change_status"
            title="สถานะการเปลี่ยนแปลงกฎหมาย"
            subtitle="จัดการสถานะการเปลี่ยนแปลงของเอกสารกฎหมาย"
            add-label="เพิ่มสถานะการเปลี่ยนแปลง"
            :stat-labels="statLabels"
            :columns="statusColumns"
            search-placeholder="ค้นหาด้วยรหัส หรือชื่อสถานะ..."
            :dialog-title="{ create: 'เพิ่มสถานะการเปลี่ยนแปลง', edit: 'แก้ไขสถานะการเปลี่ยนแปลง' }"
            dialog-subtitle="เลือกบทบาทตามวิธีผูกกับกฎหมายฉบับก่อน รายการใหม่จะยังไม่แสดงจนกว่าจะเปิดใช้งาน"
            :field-labels="fieldLabels"
            code-column-title="รหัสสถานะ"
            embedded
            hide-create-action
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
            <template #attrs="{ form }">
              <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                <div class="text-body-2 font-weight-medium mb-1">{{ selectedRoleGuide(form.attrs?.role).title }}</div>
                <div class="text-caption">{{ selectedRoleGuide(form.attrs?.role).detail }}</div>
              </v-alert>
              <v-select
                :model-value="String(form.attrs?.role ?? 'section')"
                :items="statusRoleOptions"
                item-title="title"
                item-value="value"
                label="บทบาท *"
                hint="เลือกตามวิธีที่กฎหมายนี้เกี่ยวข้องกับฉบับก่อน"
                persistent-hint
                variant="outlined"
                density="comfortable"
                @update:model-value="onStatusRole(form, $event)"
              >
                <template #selection="{ item }">
                  <span class="text-truncate">{{ item?.title || roleLabel(form.attrs?.role) }}</span>
                </template>
                <template #item="{ props: itemProps, item }">
                  <v-list-item
                    v-bind="itemProps"
                    :title="item.raw?.title ?? item.title"
                    :subtitle="item.raw?.subtitle"
                  />
                </template>
              </v-select>
              <v-select
                v-model="form.attrs.source"
                :items="sourceOptions"
                item-title="title"
                item-value="value"
                label="ใช้กับ *"
                variant="outlined"
                density="comfortable"
              />
              <v-switch
                :model-value="form.attrs?.role === 'section' && form.attrs?.has_details === true"
                :disabled="form.attrs?.role !== 'section'"
                color="admin-primary"
                label="มีรายละเอียดย่อยตอนผูกทีละข้อ"
                hint="ใช้เมื่อบทบาทเป็นปรับปรุงรายข้อหรือรายมาตรา"
                persistent-hint
                inset
                @update:model-value="setHasDetails(form, $event)"
              />
            </template>
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
                        <span>บทบาท</span>
                        <v-chip
                          :color="roleColor(item)"
                          variant="tonal"
                          size="small"
                          :prepend-icon="roleIcon(item)"
                          class="align-self-start font-weight-medium"
                        >
                          {{ roleLabel(item.attrs.role) }}
                        </v-chip>
                        <div class="text-caption text-medium-emphasis mt-1">{{ selectedRoleGuide(item.attrs.role).detail }}</div>
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
                      {{ item.is_system ? 'รายการตั้งต้นของระบบ แก้บทบาทไม่ได้ ปิดใช้งานได้ถ้าไม่ต้องการให้เลือกอีก' : 'เปิดใช้งานแล้ว บทบาทนี้จะถูกใช้ตอนผูกความสัมพันธ์' }}
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
            ref="detailPage"
            kind="change_detail"
            title="รายละเอียดการเปลี่ยนแปลงกฎหมาย"
            subtitle="จัดการรายละเอียดการเปลี่ยนแปลงของเอกสารกฎหมาย"
            add-label="เพิ่มรายละเอียดการเปลี่ยนแปลง"
            :stat-labels="statLabels"
            :columns="detailColumns"
            search-placeholder="ค้นหาด้วยรหัส หรือชื่อสถานะ..."
            :dialog-title="{ create: 'เพิ่มรายละเอียดการเปลี่ยนแปลง', edit: 'แก้ไขรายละเอียดการเปลี่ยนแปลง' }"
            dialog-subtitle="บทบาทยกเลิกหรือแก้ไขจะกลายเป็นชนิดความสัมพันธ์ รายการใหม่จะยังไม่แสดงจนกว่าจะเปิดใช้งาน"
            :field-labels="fieldLabels"
            code-column-title="รหัสสถานะ"
            embedded
            hide-create-action
          >
            <template #attrs="{ form }">
              <v-select
                :model-value="String(form.attrs?.role ?? 'amends')"
                :items="detailRoleOptions"
                item-title="title"
                item-value="value"
                label="ประเภทความสัมพันธ์ *"
                variant="outlined"
                density="comfortable"
                @update:model-value="onDetailRole(form, $event)"
              />
              <v-select
                v-model="form.attrs.source"
                :items="sourceOptions"
                item-title="title"
                item-value="value"
                label="ใช้กับ *"
                variant="outlined"
                density="comfortable"
              />
              <v-select
                v-model="form.attrs.color"
                :items="colorOptions"
                item-title="title"
                item-value="value"
                label="สีป้าย"
                variant="outlined"
                density="comfortable"
              >
                <template #selection="{ item }">
                  <v-chip :color="item.value" size="small" variant="tonal">{{ item.title }}</v-chip>
                </template>
              </v-select>
              <v-select
                v-model="form.attrs.icon"
                :items="iconOptions"
                item-title="title"
                item-value="value"
                label="ไอคอน"
                variant="outlined"
                density="comfortable"
              >
                <template #selection="{ item }">
                  <span class="d-inline-flex align-center ga-2">
                    <v-icon :icon="item.value" size="18" />
                    {{ item.title }}
                  </span>
                </template>
              </v-select>
            </template>
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
                      {{ item.is_system ? 'รายการตั้งต้นของระบบ แก้บทบาทไม่ได้ ปิดใช้งานได้ถ้าไม่ต้องการให้เลือกอีก' : 'เปิดใช้งานแล้ว ป้ายนี้จะปรากฏตอนผูกความสัมพันธ์รายข้อ' }}
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
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppShell from '../../../components/shared/AppShell.vue';
import MasterDataPage from '../../../components/master/MasterDataPage.vue';
import type { MasterItem, UpsertMasterItemPayload } from '../../../types/masterData';

type TabValue = 'status' | 'detail';

const statusPage = ref<InstanceType<typeof MasterDataPage> | null>(null);
const detailPage = ref<InstanceType<typeof MasterDataPage> | null>(null);

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
  { key: 'name', title: 'ชื่อสถานะ', width: '280px' },
  { key: 'role', title: 'บทบาท', width: '240px' },
];

const statusRoleOptions = [
  {
    title: 'กฎหมายใหม่',
    value: 'new',
    subtitle: 'ฉบับแรกของเรื่องนี้ ไม่ต้องเลือกรายละเอียดย่อย',
    summary: 'ใช้เมื่อออกกฎหมายเรื่องนี้ครั้งแรก',
    detail: 'ระบบจะมองว่าเป็นฉบับตั้งต้น ไม่เปิดโหมดปรับปรุงทั้งฉบับหรือรายข้อ และไม่ต้องเลือกรายละเอียดย่อย เช่น ยกเลิก/เพิ่ม/แก้ไข',
  },
  {
    title: 'ปรับปรุงทั้งฉบับ',
    value: 'whole',
    subtitle: 'แทนที่หรือปรับปรุงทั้งเอกสาร ไม่ต้องเลือกรายละเอียดย่อย',
    summary: 'ใช้เมื่อแก้หรือแทนที่กฎหมายทั้งฉบับ',
    detail: 'ระบบจะให้ผูกความสัมพันธ์แบบทั้งเอกสาร กับฉบับก่อน/ฉบับอ้างอิง โดยไม่ต้องเลือกรายละเอียดย่อยทีละข้อ',
  },
  {
    title: 'ปรับปรุงรายข้อหรือรายมาตรา',
    value: 'section',
    subtitle: 'แก้ทีละข้อ/มาตรา และเลือกประเภทย่อยได้ เช่น ยกเลิก เพิ่ม แก้ไข',
    summary: 'ใช้เมื่อแก้เฉพาะบางข้อหรือบางมาตรา',
    detail: 'ระบบจะเปิดให้ผูกทีละข้อ/มาตรา และเลือกประเภทย่อยได้ เช่น ยกเลิกข้อ เพิ่มข้อความ หรือแก้ไขข้อความ',
  },
];

const detailRoleOptions = [
  { title: 'ยกเลิก', value: 'repeals' },
  { title: 'แก้ไขเพิ่มเติม', value: 'amends' },
];

const sourceOptions = [
  { title: 'ทั้งหมด', value: 'both' },
  { title: 'กฎหมายภายใน', value: 'internal' },
  { title: 'กฎหมายภายนอก', value: 'external' },
];

const colorOptions = [
  { title: 'แดง', value: 'error' },
  { title: 'เขียว', value: 'success' },
  { title: 'เขียวอมฟ้า', value: 'teal' },
  { title: 'ส้ม', value: 'warning' },
  { title: 'น้ำเงิน', value: 'info' },
];

const iconOptions = [
  { title: 'ยกเลิก', value: 'mdi-cancel' },
  { title: 'เพิ่ม', value: 'mdi-plus' },
  { title: 'แก้ไข', value: 'mdi-pencil' },
  { title: 'แทนที่', value: 'mdi-file-replace-outline' },
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
  if (role === 'repeals') return 'ยกเลิก';
  if (role === 'amends') return 'แก้ไขเพิ่มเติม';
  return statusRoleOptions.find((item) => item.value === role)?.title ?? 'กฎหมายใหม่';
}

function selectedRoleGuide(role: unknown): { title: string; detail: string } {
  const found = statusRoleOptions.find((item) => item.value === role) ?? statusRoleOptions[0];
  return { title: found.title, detail: found.detail };
}

function roleGuideColor(role: string): string {
  if (role === 'new') return 'info';
  if (role === 'whole') return 'warning';
  if (role === 'section') return 'teal';
  return 'grey';
}

function roleGuideIcon(role: string): string {
  if (role === 'new') return 'mdi-file-plus-outline';
  if (role === 'whole') return 'mdi-file-replace-outline';
  if (role === 'section') return 'mdi-format-list-numbered';
  return 'mdi-tag-outline';
}

function roleColor(item: MasterItem | null | undefined): string {
  if (typeof item?.attrs.color === 'string' && item.attrs.color) return item.attrs.color;
  switch (item?.attrs.role) {
    case 'new': return 'info';
    case 'whole': return 'warning';
    case 'section': return 'teal';
    case 'repeals': return 'error';
    case 'amends': return 'success';
    default: return 'grey';
  }
}

function roleIcon(item: MasterItem | null | undefined): string {
  if (typeof item?.attrs.icon === 'string' && item.attrs.icon) return item.attrs.icon;
  switch (item?.attrs.role) {
    case 'new': return 'mdi-file-plus-outline';
    case 'whole': return 'mdi-file-replace-outline';
    case 'section': return 'mdi-format-list-numbered';
    case 'repeals': return 'mdi-cancel';
    case 'amends': return 'mdi-pencil';
    default: return 'mdi-tag-outline';
  }
}

function setHasDetails(form: UpsertMasterItemPayload, value: unknown): void {
  form.attrs = { ...(form.attrs ?? {}), has_details: Boolean(value) };
}

function onStatusRole(form: UpsertMasterItemPayload, role: unknown): void {
  const next = role === 'new' || role === 'whole' || role === 'section' ? role : 'new';
  form.attrs = {
    ...(form.attrs ?? {}),
    role: next,
    source: form.attrs?.source ?? 'both',
    has_details: next === 'section',
  };
}

function onDetailRole(form: UpsertMasterItemPayload, role: unknown): void {
  const next = role === 'repeals' ? 'repeals' : 'amends';
  form.attrs = {
    ...(form.attrs ?? {}),
    role: next,
    source: form.attrs?.source ?? 'both',
    color: next === 'repeals' ? 'error' : 'teal',
    icon: next === 'repeals' ? 'mdi-cancel' : 'mdi-pencil',
  };
}
</script>

<style scoped>
.change-status-page {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.role-guide__title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 700;
  margin-bottom: 6px;
}

.role-guide__lead {
  margin: 0 0 12px;
  font-size: 0.875rem;
  line-height: 1.5;
}

.role-guide__list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.role-guide__item {
  display: grid;
  grid-template-columns: minmax(160px, 220px) 1fr;
  gap: 10px 14px;
  align-items: start;
}

.role-guide__text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 0.8125rem;
  line-height: 1.5;
}

.role-guide__text strong {
  font-weight: 700;
}

@media (max-width: 720px) {
  .role-guide__item {
    grid-template-columns: 1fr;
  }
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
