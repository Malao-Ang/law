<script setup lang="ts">
// Pill badge for law type and change-status (eLaw design system).

type BadgeType =
  | 'ระเบียบ' | 'ข้อบังคับ' | 'ประกาศ' | 'ประกาศมหาวิทยาลัย' | 'ประกาศสภา'
  | 'พระราชบัญญัติ' | 'พระราชกำหนด' | 'กฎกระทรวง' | 'ประกาศกระทรวง'
  | 'กฎหมายภายนอก' | 'คำสั่ง' | 'มติ'
  | 'ใหม่ล่าสุด' | 'ปรับปรุงรายมาตรา' | 'ปรับปรุงทั้งฉบับ' | 'ยกเลิกบางส่วน' | 'ยกเลิกแล้ว';

const STYLES: Record<BadgeType, { bg: string; fg: string; icon: string }> = {
  // Internal law types
  'ระเบียบ':             { bg: '#3b82f6', fg: '#ffffff', icon: 'mdi-file-document-outline' },
  'ข้อบังคับ':            { bg: '#10b981', fg: '#ffffff', icon: 'mdi-gavel' },
  'ประกาศ':              { bg: '#fb923c', fg: '#ffffff', icon: 'mdi-bullhorn-outline' },
  'ประกาศมหาวิทยาลัย':   { bg: '#f59e0b', fg: '#ffffff', icon: 'mdi-school-outline' },
  'ประกาศสภา':           { bg: '#d97706', fg: '#ffffff', icon: 'mdi-bank-outline' },
  // External law types
  'พระราชบัญญัติ':       { bg: '#7c3aed', fg: '#ffffff', icon: 'mdi-scale-balance' },
  'พระราชกำหนด':         { bg: '#6d28d9', fg: '#ffffff', icon: 'mdi-shield-crown-outline' },
  'กฎกระทรวง':           { bg: '#2563eb', fg: '#ffffff', icon: 'mdi-office-building-outline' },
  'ประกาศกระทรวง':       { bg: '#0369a1', fg: '#ffffff', icon: 'mdi-bullhorn-variant-outline' },
  'กฎหมายภายนอก':        { bg: '#854d0e', fg: '#ffffff', icon: 'mdi-bank' },
  // Other
  'คำสั่ง':              { bg: '#64748b', fg: '#ffffff', icon: 'mdi-gavel' },
  'มติ':                 { bg: '#475569', fg: '#ffffff', icon: 'mdi-vote-outline' },
  // Change-status badges
  'ใหม่ล่าสุด':          { bg: '#eebf6d', fg: '#271900', icon: 'mdi-star' },
  'ปรับปรุงรายมาตรา':    { bg: '#eef2ff', fg: '#4f46e5', icon: 'mdi-file-document-edit-outline' },
  'ปรับปรุงทั้งฉบับ':    { bg: '#f0f9ff', fg: '#0284c7', icon: 'mdi-history' },
  'ยกเลิกบางส่วน':       { bg: '#fffbeb', fg: '#d97706', icon: 'mdi-content-cut' },
  'ยกเลิกแล้ว':          { bg: '#e11d48', fg: '#ffffff', icon: 'mdi-close-circle-outline' },
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
  font-size: 14px;
  line-height: 20px;
  font-weight: 700;
  letter-spacing: 0.28px;
  white-space: nowrap;
}
</style>
