<template>
  <div class="lip">
    <v-card tag="section" class="lip-card" elevation="0">
      <p class="lip-card__title"><span class="mdi mdi-information-outline" /> ข้อมูลกฎหมาย</p>

      <div v-if="meta.status" class="lip-row">
        <span class="lip-row__k">สถานะ</span>
        <span class="lip-row__v lip-status"><span class="lip-status__dot" /> {{ meta.status }}</span>
      </div>
      <div v-if="meta.promulgation_date" class="lip-row"><span class="lip-row__k">วันที่ประกาศ</span><span class="lip-row__v">{{ meta.promulgation_date }}</span></div>
      <div v-if="meta.effective_date" class="lip-row"><span class="lip-row__k">วันที่มีผลบังคับ</span><span class="lip-row__v">{{ meta.effective_date }}</span></div>
      <div v-if="meta.law_type" class="lip-row"><span class="lip-row__k">ประเภท</span><span class="lip-row__v">{{ meta.law_type }}</span></div>
      <div v-if="meta.law_group" class="lip-row"><span class="lip-row__k">กลุ่มกฎหมาย</span><span class="lip-row__v">{{ meta.law_group }}</span></div>
      <div v-if="meta.agency" class="lip-row"><span class="lip-row__k">หน่วยงาน</span><span class="lip-row__v">{{ meta.agency }}</span></div>
      <div class="lip-row"><span class="lip-row__k">จำนวนข้อ</span><span class="lip-row__v">{{ articleCount }} มาตรา</span></div>

      <div v-if="meta.repealed_laws.length" class="lip-repealed">
        <p class="lip-repealed__title">กฎหมายที่ถูกยกเลิก</p>
        <p v-for="(law, index) in meta.repealed_laws" :key="index" class="lip-repealed__item">
          <span class="mdi mdi-cancel" /> {{ law }}
        </p>
      </div>
    </v-card>

    <v-card tag="section" class="lip-card" elevation="0">
      <p class="lip-card__title"><span class="mdi mdi-briefcase-outline" /> ดำเนินการ</p>
      <button class="lip-action" disabled><span class="mdi mdi-history" /> ดูประวัติการแก้ไข</button>
      <button class="lip-action" disabled><span class="mdi mdi-sitemap-outline" /> ความสัมพันธ์กฎหมาย</button>
    </v-card>

    <v-card v-if="(relations ?? []).length" tag="section" class="lip-card" elevation="0">
      <p class="lip-card__title"><span class="mdi mdi-link-variant" /> กฎหมายที่เกี่ยวข้อง</p>
      <p v-for="rel in relations" :key="rel.id" class="lip-rel" :class="{ 'lip-rel--repeal': rel.type === 'repeals' }">
        <span class="mdi" :class="rel.type === 'repeals' ? 'mdi-cancel' : 'mdi-link-variant'" />
        {{ rel.target_title }}<span v-if="rel.target_section"> · {{ rel.target_section }}</span>
      </p>
    </v-card>
  </div>
</template>

<script setup lang="ts">
import type { LawMeta, LawRelation } from '../../types/document';

defineProps<{ meta: LawMeta; articleCount: number; relations?: LawRelation[] }>();
</script>

<style scoped>
.lip { display: flex; flex-direction: column; gap: 14px; }
.lip-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; }
.lip-card__title { font-weight: 700; font-size: 14px; margin: 0 0 12px; display: flex; align-items: center; gap: 6px; color: #1e2a4a; }
.lip-row { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
.lip-row__k { color: #94a3b8; }
.lip-row__v { color: #1e293b; font-weight: 600; text-align: right; }
.lip-status { color: #047857; display: inline-flex; align-items: center; gap: 6px; }
.lip-status__dot { width: 8px; height: 8px; border-radius: 50%; background: #10b981; display: inline-block; }
.lip-repealed { margin-top: 10px; }
.lip-repealed__title { font-size: 12px; color: #dc2626; font-weight: 700; margin: 0 0 6px; }
.lip-repealed__item { font-size: 12px; color: #475569; margin: 4px 0; display: flex; align-items: center; gap: 6px; }
.lip-repealed__item .mdi { color: #dc2626; }
.lip-action { width: 100%; display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; font-size: 13px; color: #475569; margin-top: 8px; font-family: inherit; cursor: not-allowed; }
.lip-rel { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #475569; margin: 6px 0; }
.lip-rel--repeal { color: #dc2626; }
</style>
