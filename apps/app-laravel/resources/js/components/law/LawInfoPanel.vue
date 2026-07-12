<template>
  <div class="d-flex flex-column ga-4">
    <v-card tag="section" flat border rounded="lg">
      <v-card-title class="d-flex align-center ga-2 text-body-2 font-weight-bold text-elaw-navy">
        <v-icon icon="mdi-information-outline" size="small" />
        ข้อมูลกฎหมาย
      </v-card-title>
      <v-card-text>
        <div v-if="meta.status" class="d-flex justify-space-between ga-4 py-1">
          <span class="text-medium-emphasis">สถานะ</span>
          <span class="text-success font-weight-semibold d-flex align-center ga-1">
            <v-icon icon="mdi-circle" size="x-small" />
            {{ meta.status }}
          </span>
        </div>
        <div v-if="meta.promulgation_date" class="d-flex justify-space-between ga-4 py-1">
          <span class="text-medium-emphasis">วันที่ประกาศ</span>
          <span class="font-weight-semibold">{{ meta.promulgation_date }}</span>
        </div>
        <div v-if="meta.effective_date" class="d-flex justify-space-between ga-4 py-1">
          <span class="text-medium-emphasis">วันที่มีผลบังคับ</span>
          <span class="font-weight-semibold">{{ meta.effective_date }}</span>
        </div>
        <div v-if="meta.law_type" class="d-flex justify-space-between ga-4 py-1">
          <span class="text-medium-emphasis">ประเภท</span>
          <span class="font-weight-semibold">{{ meta.law_type }}</span>
        </div>
        <div v-if="meta.law_group" class="d-flex justify-space-between ga-4 py-1">
          <span class="text-medium-emphasis">กลุ่มกฎหมาย</span>
          <span class="font-weight-semibold">{{ meta.law_group }}</span>
        </div>
        <div v-if="meta.agency" class="d-flex justify-space-between ga-4 py-1">
          <span class="text-medium-emphasis">หน่วยงาน</span>
          <span class="font-weight-semibold">{{ meta.agency }}</span>
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
        <div class="d-flex justify-space-between ga-4 py-1">
          <span class="text-medium-emphasis">จำนวนข้อ</span>
          <span class="font-weight-semibold">{{ articleCount }} {{ articleUnitLabel ?? 'ข้อ/มาตรา' }}</span>
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
        <v-btn flat variant="outlined" disabled prepend-icon="mdi-history" class="justify-start text-none">ดูประวัติการแก้ไข</v-btn>
        <v-btn flat variant="outlined" disabled prepend-icon="mdi-sitemap-outline" class="justify-start text-none">ความสัมพันธ์กฎหมาย</v-btn>
      </v-card-text>
    </v-card>

    <v-card v-if="(relations ?? []).length" tag="section" flat border rounded="lg">
      <v-card-title class="d-flex align-center ga-2 text-body-2 font-weight-bold text-elaw-navy">
        <v-icon icon="mdi-link-variant" size="small" />
        กฎหมายที่เกี่ยวข้อง
      </v-card-title>
      <v-card-text>
        <div
          v-for="rel in relations"
          :key="rel.id"
          class="d-flex align-center ga-2 text-caption my-2"
          :class="relationRowClass(rel.type)"
        >
          <v-icon
            :icon="RELATION_TYPE_ICONS[rel.type] ?? 'mdi-link-variant'"
            size="small"
          />
          <span class="font-weight-medium">{{ relationTypeLabel(rel.type) }}</span>
          <span>{{ rel.target_title }}<span v-if="rel.target_section"> · {{ rel.target_section }}</span></span>
        </div>
      </v-card-text>
    </v-card>
  </div>
</template>

<script setup lang="ts">
import type { LawMeta, LawRelation, RelationType } from '../../types/document';
import {
  RELATION_TYPE_ICONS,
  relationTypeLabel,
} from '../../types/lawRelation';

defineProps<{ meta: LawMeta; articleCount: number; articleUnitLabel?: string; relations?: LawRelation[] }>();

function relationRowClass(type: RelationType): string {
  if (type === 'repeals') return 'text-error';
  if (type === 'supersedes') return 'text-orange-darken-2';
  if (type === 'amends') return 'text-teal';
  if (type === 'issued_under') return 'text-deep-purple';
  return 'text-medium-emphasis';
}
</script>
