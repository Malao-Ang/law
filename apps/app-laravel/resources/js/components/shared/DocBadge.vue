<script setup lang="ts">
// Pill badge from Figma "eLaw_MainPage" design system (nodes 67:5859 MainType, 67:5876 EditStatus).
// Two families in one component: law type (solid bg / white text) + edit status (soft bg / colored text).
// Colors are the exact design hex — the 4 law-type values also equal the Vuetify doc-* theme tokens.
// ponytail: MDI icons stand in for the design's one-off SVGs (glyphs match closely); swap to exported
// SVGs if pixel-exact icons are required.

type BadgeType =
  | 'พ.ร.บ.' | 'ระเบียบ' | 'ข้อบังคับ' | 'ประกาศ'
  | 'ใหม่ล่าสุด' | 'ปรับปรุงรายมาตรา' | 'ปรับปรุงทั้งฉบับ' | 'ยกเลิกบางส่วน' | 'ยกเลิกแล้ว';

const STYLES: Record<BadgeType, { bg: string; fg: string; icon: string }> = {
  'พ.ร.บ.':            { bg: '#854d0e', fg: '#ffffff', icon: 'mdi-bank' },
  'ระเบียบ':           { bg: '#3b82f6', fg: '#ffffff', icon: 'mdi-file-document-outline' },
  'ข้อบังคับ':          { bg: '#10b981', fg: '#ffffff', icon: 'mdi-gavel' },
  'ประกาศ':            { bg: '#fb923c', fg: '#ffffff', icon: 'mdi-bullhorn-outline' },
  'ใหม่ล่าสุด':         { bg: '#eebf6d', fg: '#271900', icon: 'mdi-star' },
  'ปรับปรุงรายมาตรา':   { bg: '#eef2ff', fg: '#4f46e5', icon: 'mdi-file-document-edit-outline' },
  'ปรับปรุงทั้งฉบับ':    { bg: '#f0f9ff', fg: '#0284c7', icon: 'mdi-history' },
  'ยกเลิกบางส่วน':      { bg: '#fffbeb', fg: '#d97706', icon: 'mdi-content-cut' },
  'ยกเลิกแล้ว':         { bg: '#e11d48', fg: '#ffffff', icon: 'mdi-close-circle-outline' },
};

const props = defineProps<{ type: BadgeType; label?: string }>();
</script>

<template>
  <span
    class="doc-badge"
    :style="{ backgroundColor: STYLES[type].bg, color: STYLES[type].fg }"
  >
    <v-icon :icon="STYLES[type].icon" size="14" />
    <span class="doc-badge__label">{{ label ?? type }}</span>
  </span>
</template>

<style scoped>
.doc-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 10px;
  border-radius: 9999px;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 16px;
  line-height: 20px;
  font-weight: 700;
  letter-spacing: 0.28px;
  white-space: nowrap;
}
</style>
