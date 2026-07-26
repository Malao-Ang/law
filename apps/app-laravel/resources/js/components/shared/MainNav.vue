<script setup lang="ts">
// Top nav from Figma eLaw_MainPage (node 67:5748).
import { computed } from 'vue';
import { useRoute, type RouteLocationRaw } from 'vue-router';

interface NavItem {
  label: string;
  to: RouteLocationRaw;
  activePath: string;
  hash?: string;
}

const route = useRoute();

const items: NavItem[] = [
  { label: 'หน้าแรก', to: '/', activePath: '/' },
  { label: 'เกี่ยวกับระบบ', to: { path: '/', hash: '#about' }, activePath: '/', hash: '#about' },
  { label: 'ฐานข้อมูลกฎหมาย', to: '/database', activePath: '/database' },
  { label: 'ความรู้', to: { path: '/', hash: '#knowledge' }, activePath: '/', hash: '#knowledge' },
];

const activeRoutePath = computed(() => route.path);

function isActive(item: NavItem): boolean {
  const path = activeRoutePath.value;

  if (item.activePath === '/database') {
    return path === '/database' || path.startsWith('/law/');
  }

  if (item.hash) {
    return path === item.activePath && route.hash === item.hash;
  }

  return path === item.activePath && route.hash === '';
}
</script>

<template>
  <nav class="main-nav">
    <v-btn
      v-for="item in items"
      :key="item.label"
      :to="item.to"
      :active="false"
      variant="text"
      rounded="0"
      height="40"
      :ripple="false"
      class="main-nav__item text-none"
      :class="{ 'main-nav__item--active': isActive(item) }"
    >
      {{ item.label }}
    </v-btn>
  </nav>
</template>

<style scoped>
.main-nav {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  flex-wrap: wrap;
}

.main-nav__item {
  padding-inline: 16px;
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-weight: 700;
  font-size: 16px;
  letter-spacing: 0;
  color: #4e4538;
  position: relative;
}

.main-nav__item--active {
  color: #7b580d;
}

.main-nav__item--active::after {
  content: '';
  position: absolute;
  left: 16px;
  right: 16px;
  bottom: 8px;
  height: 2px;
  border-radius: 2px;
  background: #7b580d;
}
</style>
