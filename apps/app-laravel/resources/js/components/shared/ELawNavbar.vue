<template>
  <v-app-bar color="surface" flat border="b" height="60">
    <v-container class="d-flex align-center ga-8 py-0 elaw-navbar">
      <div class="d-flex align-center ga-2">
        <v-icon icon="mdi-scale-balance" color="elaw-gold" />
        <span class="text-h6 font-weight-bold text-elaw-navy">e-Law</span>
        <span class="text-caption text-medium-emphasis pl-2 border-s">ระบบฐานข้อมูลกฎหมาย</span>
      </div>

      <div class="flex-grow-1 d-flex justify-center">
        <MainNav />
      </div>

      <v-btn color="elaw-navy" prepend-icon="mdi-shield-account-outline" @click="$emit('go-admin')">
        สำหรับเจ้าหน้าที่
      </v-btn>
    </v-container>
  </v-app-bar>
</template>

<script setup lang="ts">
import MainNav from './MainNav.vue';

defineEmits<{ 'go-admin': [] }>();
</script>

<style scoped>
.elaw-navbar {
  flex-wrap: wrap;
}
</style>
