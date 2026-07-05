<template>
  <v-app-bar color="surface" flat border="b" height="60">
    <v-container class="d-flex align-center ga-8 py-0">
      <div class="d-flex align-center ga-2">
        <v-icon icon="mdi-scale-balance" color="elaw-gold" />
        <span class="text-h6 font-weight-bold text-elaw-navy">e-Law</span>
        <span class="text-caption text-medium-emphasis pl-2 border-s">ระบบฐานข้อมูลกฎหมาย</span>
      </div>

      <div class="d-flex ga-1 flex-grow-1">
        <v-btn
          v-for="link in navLinks"
          :key="link.label"
          variant="text"
          size="small"
          :to="link.to"
          :active="isActive(link)"
          :color="isActive(link) ? 'elaw-gold' : undefined"
        >
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
import { computed } from 'vue';
import { useRoute } from 'vue-router';

defineEmits<{ 'go-admin': [] }>();

interface NavLink {
  label: string;
  to: string;
  activePath: string;
  hash?: string;
}

const route = useRoute();

const navLinks = [
  { label: 'หน้าแรก', to: '/', activePath: '/' },
  { label: 'เกี่ยวกับระบบ', to: '/#about', activePath: '/', hash: '#about' },
  { label: 'ฐานข้อมูลกฎหมาย', to: '/database', activePath: '/database' },
  { label: 'ความรู้ทางกฎหมาย', to: '/#knowledge', activePath: '/', hash: '#knowledge' },
] satisfies NavLink[];

const activeRoutePath = computed(() => route.path);

function isActive(link: NavLink): boolean {
  if (link.hash) {
    return activeRoutePath.value === link.activePath && route.hash === link.hash;
  }

  if (link.activePath === '/database') {
    return activeRoutePath.value === '/database' || activeRoutePath.value.startsWith('/law/');
  }

  return activeRoutePath.value === link.activePath;
}
</script>
