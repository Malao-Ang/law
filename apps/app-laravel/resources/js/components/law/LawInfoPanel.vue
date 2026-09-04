<template>
  <div class="d-flex flex-column ga-4">
    <v-card tag="section" flat border rounded="lg">
      <v-card-title class="d-flex align-center ga-2 text-body-2 font-weight-bold text-elaw-navy">
        <v-icon icon="mdi-information-outline" size="small" />
        ข้อมูลกฎหมาย
      </v-card-title>
      <v-card-text>
        <div v-if="meta.status" class="law-info-row py-1">
          <span class="law-info-row__label text-medium-emphasis">สถานะ</span>
          <span class="law-info-row__value text-success font-weight-semibold d-flex align-center justify-end ga-1">
            <v-icon icon="mdi-circle" size="x-small" />
            {{ meta.status }}
          </span>
        </div>
        <div v-if="meta.promulgation_date" class="law-info-row py-1">
          <span class="law-info-row__label text-medium-emphasis">วันที่ประกาศ</span>
          <span class="law-info-row__value font-weight-semibold">{{ formatLawDate(meta.promulgation_date) }}</span>
        </div>
        <div v-if="meta.effective_date" class="law-info-row py-1">
          <span class="law-info-row__label text-medium-emphasis">วันที่มีผลบังคับ</span>
          <span class="law-info-row__value font-weight-semibold">{{ formatLawDate(meta.effective_date) }}</span>
        </div>
        <div v-if="meta.law_type" class="law-info-row py-1">
          <span class="law-info-row__label text-medium-emphasis">ประเภท</span>
          <span class="law-info-row__value font-weight-semibold">{{ meta.law_type }}</span>
        </div>
        <div v-if="meta.issuer" class="law-info-row py-1">
          <span class="law-info-row__label text-medium-emphasis">ออกโดย</span>
          <span class="law-info-row__value font-weight-semibold">{{ meta.issuer }}</span>
        </div>
        <div v-if="meta.law_group" class="law-info-row py-1">
          <span class="law-info-row__label text-medium-emphasis">กลุ่มกฎหมาย</span>
          <span class="law-info-row__value font-weight-semibold">{{ meta.law_group }}</span>
        </div>
        <div v-if="meta.agency" class="law-info-row py-1">
          <span class="law-info-row__label text-medium-emphasis">หน่วยงาน</span>
          <span class="law-info-row__value font-weight-semibold">{{ meta.agency }}</span>
        </div>
        <div v-if="meta.keywords.length" class="mt-3">
          <div class="text-caption text-medium-emphasis font-weight-bold mb-2">คำสำคัญ</div>
          <div class="d-flex flex-wrap ga-2">
            <v-chip
              v-for="keyword in meta.keywords"
              :key="keyword"
              size="small"
              variant="tonal"
              color="primary"
            >
              {{ keyword }}
            </v-chip>
          </div>
        </div>
        <div v-if="showCount" class="law-info-row py-1">
          <span class="law-info-row__label text-medium-emphasis">จำนวน{{ articleUnitLabel ?? 'ข้อ' }}</span>
          <span class="law-info-row__value font-weight-semibold">{{ articleCount }} {{ articleUnitLabel ?? 'ข้อ' }}</span>
        </div>

        <div v-if="meta.repealed_laws.length" class="mt-3">
          <div class="text-caption text-error font-weight-bold mb-2">กฎหมายที่ถูกยกเลิก</div>
          <div
            v-for="(law, index) in meta.repealed_laws"
            :key="index"
            class="d-flex align-center ga-2 text-caption text-medium-emphasis my-1"
          >
            <v-icon icon="mdi-cancel" size="small" color="error" />
            {{ law }}
          </div>
        </div>
      </v-card-text>
    </v-card>

    <v-card tag="section" flat border rounded="lg">
      <v-card-title class="d-flex align-center ga-2 text-body-2 font-weight-bold text-elaw-navy">
        <v-icon icon="mdi-briefcase-outline" size="small" />
        ดำเนินการ
      </v-card-title>
      <v-card-text class="d-flex flex-column ga-2">
        <v-btn
          flat
          variant="outlined"
          prepend-icon="mdi-history"
          class="justify-start text-none"
          :disabled="!viewedDocumentId"
          :to="viewedDocumentId ? `/law/${encodeURIComponent(viewedDocumentId)}/versions` : undefined"
        >
          ดูเวอร์ชันและความสัมพันธ์
          <v-chip
            v-if="versions && versions.length"
            size="x-small"
            color="primary"
            variant="tonal"
            rounded="pill"
            class="ml-2"
          >{{ versions.length }}</v-chip>
        </v-btn>
      </v-card-text>
    </v-card>

  </div>
</template>

<script setup lang="ts">
import type { LawMeta } from '../../types/document';
import type { VersionChainItem } from '../../types/versionChain';
import { formatThaiDate } from '../../utils/thaiDate';

defineProps<{ meta: LawMeta; articleCount: number; articleUnitLabel?: string; showCount?: boolean; versions?: VersionChainItem[]; viewedDocumentId?: string }>();

function formatLawDate(value: string | null | undefined): string {
  return formatThaiDate(value) || value || '';
}
</script>

<style scoped>
.law-info-row {
  align-items: start;
  column-gap: 16px;
  display: grid;
  grid-template-columns: max-content minmax(0, 1fr);
}

.law-info-row__label {
  min-width: 0;
}

.law-info-row__value {
  min-width: 0;
  overflow-wrap: anywhere;
  text-align: end;
}
</style>
