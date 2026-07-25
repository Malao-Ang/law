<script setup lang="ts">
// Top nav from Figma eLaw_MainPage (node 67:5748). TH Sarabun New Bold 22px #4e4538.
import { computed } from 'vue';
import { RouterLink, useRoute, type RouteLocationRaw } from 'vue-router';

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
  if (item.hash) {
    return activeRoutePath.value === item.activePath && route.hash === item.hash;
  }

  if (item.activePath === '/database') {
    return activeRoutePath.value === '/database' || activeRoutePath.value.startsWith('/law/');
  }

  return activeRoutePath.value === item.activePath;
}
</script>

<template>
  <nav class="main-nav">
    <RouterLink
      v-for="item in items"
      :key="item.label"
      :to="item.to"
      class="main-nav__item"
      :class="{ 'main-nav__item--active': isActive(item) }"
    >
      {{ item.label }}
    </RouterLink>
  </nav>
</template>

<style scoped>
.main-nav {
  display: flex;
  gap: 19px;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
}

.main-nav__item {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 37px;
  padding: 10px;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-weight: 700;
  font-size: 22px;
  line-height: 30px;
  letter-spacing: 0;
  color: #4e4538;
  text-decoration: none;
  white-space: nowrap;
}

.main-nav__item--active {
  color: #7b580d;
  border-bottom: 1px solid #7b580d;
}
</style>
