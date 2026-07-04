<template>
  <v-app-bar color="surface" flat border="b" height="60">
    <v-container class="d-flex align-center ga-8 py-0">
      <div class="d-flex align-center ga-2">
        <v-icon icon="mdi-scale-balance" color="elaw-gold" />
        <span class="text-h6 font-weight-bold text-elaw-navy">e-Law</span>
        <span class="text-caption text-medium-emphasis pl-2 border-s">ระบบฐานข้อมูลกฎหมาย</span>
      </div>

      <div class="d-flex ga-1 flex-grow-1">
        <v-btn v-for="link in navLinks" :key="link.label" variant="text" size="small">
          {{ link.label }}
        </v-btn>
      </div>

      <v-btn color="elaw-navy" prepend-icon="mdi-shield-account-outline" @click="$emit('go-admin')">
        สำหรับเจ้าหน้าที่
      </v-btn>
    </v-container>
  </v-app-bar>
</template>

<script setup lang="ts">
defineEmits<{ 'go-admin': [] }>();

const navLinks = [
  { label: 'หน้าแรก' },
  { label: 'เกี่ยวกับระบบ' },
  { label: 'ฐานข้อมูลกฎหมาย' },
  { label: 'ความรู้ทางกฎหมาย' },
];
</script>
