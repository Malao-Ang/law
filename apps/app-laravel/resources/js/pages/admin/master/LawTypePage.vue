<template>
  <AppShell
    :breadcrumbs="['จัดการข้อมูลระบบ', 'ประเภทเอกสาร']"
    title="ประเภทเอกสาร"
    subtitle="จัดการประเภทเอกสาร กลุ่มประเภท และผู้ออกประกาศ"
    show-bell
  >
    <v-card flat border rounded="lg" class="law-type-tabs">
      <v-tabs v-model="tab" color="admin-primary" density="comfortable">
        <v-tab value="types">ประเภทเอกสาร</v-tab>
        <v-tab value="families">กลุ่มประเภท</v-tab>
        <v-tab value="issuers">ผู้ออกประกาศ</v-tab>
      </v-tabs>
    </v-card>

    <v-window v-model="tab" class="mt-4">
      <v-window-item value="types">
        <MasterDataPage
          kind="law_type"
          title="ประเภทเอกสาร"
          subtitle="จัดการประเภทเอกสารสำหรับข้อมูลกฎหมาย"
          add-label="เพิ่มประเภทเอกสาร"
          :stat-labels="typeStatLabels"
          :columns="typeColumns"
          search-placeholder="ค้นหาด้วยรหัส หรือชื่อประเภทเอกสาร..."
          :dialog-title="{ create: 'เพิ่มประเภทเอกสาร', edit: 'แก้ไขประเภทเอกสาร' }"
          dialog-subtitle="กำหนดประเภทเอกสาร กลุ่มประเภท และการระบุผู้ออกประกาศ"
          :field-labels="{ code: 'รหัสประเภทเอกสาร', name: 'ชื่อประเภทเอกสาร' }"
          embedded
        >
          <template #item.name="{ item }">
            <v-chip :style="familyChipStyle(item.attrs.family_code)" size="small" class="font-weight-bold">
              {{ item.name }}
            </v-chip>
          </template>
          <template #item.family="{ item }">
            {{ familyTitle(item.attrs.family_code) }}
          </template>
          <template #item.source="{ item }">
            {{ sourceLabel(familySource(item.attrs.family_code)) }}
          </template>
          <template #item.unit="{ item }">
            {{ unitWord(familySource(item.attrs.family_code)) }}
          </template>
          <template #item.requires_issuer="{ item }">
            {{ item.attrs.requires_issuer ? 'ต้องระบุ' : '-' }}
          </template>
          <template #item.usage_count="{ item }">
            <v-chip size="small" color="admin-primary" variant="tonal" :to="`/admin/laws?type=${item.code}`">
              {{ (item.usage_count ?? 0).toLocaleString('th-TH') }}
            </v-chip>
          </template>
          <template #attrs="{ form, item }">
            <v-select
              v-model="form.attrs.family_code"
              label="กลุ่มประเภท*"
              :items="familyOptions"
              item-title="title"
              item-value="value"
              :disabled="isUsageLocked(item)"
              :hint="usageLockHint(item)"
              :persistent-hint="isUsageLocked(item)"
              variant="outlined"
              density="comfortable"
              @update:model-value="syncRequiresIssuer(form)"
            />
            <v-switch
              v-model="form.attrs.requires_issuer"
              label="ต้องระบุผู้ออกประกาศ"
              color="success"
              :disabled="!isInternalFamily(form.attrs.family_code) || isUsageLocked(item)"
              :hint="requiresIssuerHint(form.attrs.family_code, item)"
              persistent-hint
              inset
            />
          </template>
        </MasterDataPage>
      </v-window-item>

      <v-window-item value="families">
        <MasterDataPage
          kind="law_family"
          title="กลุ่มประเภท"
          subtitle="จัดการกลุ่มประเภทเอกสารและที่มา"
          add-label="เพิ่มกลุ่มประเภท"
          :stat-labels="familyStatLabels"
          :columns="familyColumns"
          search-placeholder="ค้นหาด้วยรหัส หรือชื่อกลุ่มประเภท..."
          :dialog-title="{ create: 'เพิ่มกลุ่มประเภท', edit: 'แก้ไขกลุ่มประเภท' }"
          dialog-subtitle="กำหนดกลุ่มประเภท ที่มา และสีป้าย"
          :field-labels="{ code: 'รหัสกลุ่มประเภท', name: 'ชื่อกลุ่มประเภท' }"
          embedded
        >
          <template #item.name="{ item }">
            <v-chip :style="familyChipStyle(item.code)" size="small" class="font-weight-bold">
              {{ item.name }}
            </v-chip>
          </template>
          <template #item.source="{ item }">
            {{ sourceLabel(item.attrs.source) }}
          </template>
          <template #item.unit="{ item }">
            {{ unitWord(item.attrs.source) }}
          </template>
          <template #item.type_count="{ item }">
            {{ (item.usage_count ?? 0).toLocaleString('th-TH') }}
          </template>
          <template #attrs="{ form, item }">
            <v-radio-group
              v-model="form.attrs.source"
              label="ที่มา*"
              :disabled="isUsageLocked(item)"
              :hint="usageLockHint(item)"
              :persistent-hint="isUsageLocked(item)"
              inline
            >
              <v-radio label="ภายใน" value="internal" />
              <v-radio label="ภายนอก" value="external" />
            </v-radio-group>
            <v-text-field
              :model-value="unitWord(form.attrs.source)"
              label="หน่วยนับ"
              readonly
              bg-color="grey-lighten-4"
              variant="outlined"
              density="comfortable"
            />
            <v-text-field
              v-model="form.attrs.color"
              label="สี badge"
              type="color"
              variant="outlined"
              density="comfortable"
            />
          </template>
        </MasterDataPage>
      </v-window-item>

      <v-window-item value="issuers">
        <MasterDataPage
          kind="issuer"
          title="ผู้ออกประกาศ"
          subtitle="จัดการรายชื่อผู้ออกประกาศ"
          add-label="เพิ่มผู้ออกประกาศ"
          :stat-labels="issuerStatLabels"
          :columns="issuerColumns"
          search-placeholder="ค้นหาด้วยรหัส หรือชื่อผู้ออกประกาศ..."
          :dialog-title="{ create: 'เพิ่มผู้ออกประกาศ', edit: 'แก้ไขผู้ออกประกาศ' }"
          dialog-subtitle="กำหนดรายชื่อผู้ออกประกาศสำหรับประเภทเอกสารที่ต้องระบุ"
          :field-labels="{ code: 'รหัสผู้ออกประกาศ', name: 'ชื่อผู้ออกประกาศ' }"
          embedded
        >
          <template #item.example="{ item }">
            ออกโดย{{ item.name }}
          </template>
          <template #item.usage_count="{ item }">
            <v-chip size="small" color="admin-primary" variant="tonal">
              {{ (item.usage_count ?? 0).toLocaleString('th-TH') }}
            </v-chip>
          </template>
        </MasterDataPage>
      </v-window-item>
    </v-window>
  </AppShell>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppShell from '../../../components/shared/AppShell.vue';
import MasterDataPage from '../../../components/master/MasterDataPage.vue';
import { useLawType } from '../../../composables/useLawType';
import type { MasterItem, UpsertMasterItemPayload } from '../../../types/masterData';
import type { LawSource } from '../../../api/client';

type TabValue = 'types' | 'families' | 'issuers';

const route = useRoute();
const router = useRouter();
const lawTypes = useLawType();
const validTabs: TabValue[] = ['types', 'families', 'issuers'];

const tab = computed<TabValue>({
  get() {
    const raw = Array.isArray(route.query.tab) ? route.query.tab[0] : route.query.tab;
    return validTabs.includes(raw as TabValue) ? raw as TabValue : 'types';
  },
  set(value) {
    void router.replace({ query: { ...route.query, tab: value === 'types' ? undefined : value } });
  },
});

const typeStatLabels = { total: 'ประเภททั้งหมด', active: 'รายการที่ใช้งาน', inactive: 'รายการที่ปิดใช้งาน' };
const familyStatLabels = { total: 'กลุ่มทั้งหมด', active: 'รายการที่ใช้งาน', inactive: 'รายการที่ปิดใช้งาน' };
const issuerStatLabels = { total: 'ผู้ออกทั้งหมด', active: 'รายการที่ใช้งาน', inactive: 'รายการที่ปิดใช้งาน' };

const typeColumns = [
  { key: 'name', title: 'ชื่อประเภทเอกสาร', width: '210px' },
  { key: 'family', title: 'กลุ่ม', width: '160px' },
  { key: 'source', title: 'ที่มา', width: '110px' },
  { key: 'unit', title: 'หน่วยนับ', width: '110px' },
  { key: 'requires_issuer', title: 'ผู้ออกประกาศ', width: '130px' },
  { key: 'usage_count', title: 'จำนวนเอกสาร', width: '130px', align: 'end' as const },
];

const familyColumns = [
  { key: 'name', title: 'ชื่อกลุ่ม', width: '220px' },
  { key: 'source', title: 'ที่มา', width: '120px' },
  { key: 'unit', title: 'หน่วยนับ', width: '110px' },
  { key: 'type_count', title: 'จำนวนประเภท/เอกสาร', width: '170px', align: 'end' as const },
];

const issuerColumns = [
  { key: 'name', title: 'ชื่อผู้ออกประกาศ', width: '240px' },
  { key: 'example', title: 'ตัวอย่าง' },
  { key: 'usage_count', title: 'จำนวนเอกสาร', width: '130px', align: 'end' as const },
];

const familyOptions = computed(() =>
  lawTypes.familiesOrdered.value.map((family) => ({
    title: `${family.title} (${sourceLabel(family.source)})`,
    value: family.code,
  })),
);

watch(tab, () => {
  window.scrollTo({ top: 0, behavior: 'smooth' });
});

function familyTitle(value: unknown): string {
  return lawTypes.familyItem(String(value ?? ''))?.title ?? '-';
}

function familySource(value: unknown): LawSource {
  return lawTypes.familyItem(String(value ?? ''))?.source ?? 'internal';
}

function sourceLabel(value: unknown): string {
  return value === 'external' ? 'ภายนอก' : 'ภายใน';
}

function unitWord(value: unknown): string {
  return value === 'external' ? 'มาตรา' : 'ข้อ';
}

function familyChipStyle(value: unknown): Record<string, string> {
  const color = lawTypes.familyItem(String(value ?? ''))?.color ?? '#6B7280';
  return { backgroundColor: color, color: '#ffffff' };
}

function isUsageLocked(item?: MasterItem | null): boolean {
  return (item?.usage_count ?? 0) > 0;
}

function usageLockHint(item?: MasterItem | null): string | undefined {
  if (!isUsageLocked(item)) return undefined;
  return `มีเอกสารใช้งานอยู่ ${(item?.usage_count ?? 0).toLocaleString('th-TH')} รายการ แก้ไขไม่ได้`;
}

function isInternalFamily(value: unknown): boolean {
  return familySource(value) === 'internal';
}

function requiresIssuerHint(value: unknown, item?: MasterItem | null): string {
  return usageLockHint(item) ?? (isInternalFamily(value) ? 'ใช้กับประเภทเอกสารภายในที่ต้องระบุผู้ออกประกาศ' : 'ใช้ได้เฉพาะกลุ่มภายใน');
}

function syncRequiresIssuer(form: UpsertMasterItemPayload): void {
  if (!isInternalFamily(form.attrs?.family_code)) {
    form.attrs = { ...(form.attrs ?? {}), requires_issuer: false };
  }
}
</script>

<style scoped>
.law-type-tabs {
  overflow: hidden;
}
</style>
