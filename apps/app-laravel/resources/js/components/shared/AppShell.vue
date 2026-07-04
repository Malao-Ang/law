<template>
  <v-app-bar color="surface" flat border="b">
    <v-app-bar-nav-icon @click="drawer = !drawer" />
    <v-app-bar-title>
      <div class="d-flex flex-column">
        <span class="text-subtitle-1 font-weight-bold">{{ title }}</span>
        <span v-if="subtitle" class="text-caption text-medium-emphasis">{{ subtitle }}</span>
      </div>
    </v-app-bar-title>

    <v-breadcrumbs v-if="breadcrumbs.length" :items="breadcrumbs" density="compact" class="d-none d-md-flex" />

    <template #append>
      <slot name="actions" />
      <v-btn icon="mdi-bell-outline" variant="text" />
    </template>
  </v-app-bar>

  <v-navigation-drawer v-model="drawer" width="290">
    <div class="pa-4 d-flex align-center ga-3">
      <v-avatar color="primary" rounded="lg"><v-icon icon="mdi-bank-outline" /></v-avatar>
      <div>
        <p class="text-subtitle-2 font-weight-bold mb-0">LAWSPACE</p>
        <p class="text-caption text-medium-emphasis mb-0">ระบบจัดการฐานข้อมูลกฎหมาย</p>
      </div>
    </div>

    <v-divider />

    <v-list nav density="comfortable">
      <template v-for="group in resolvedNavGroups" :key="group.label">
        <v-list-subheader>{{ group.label }}</v-list-subheader>
        <v-list-item
          v-for="item in group.items"
          :key="item.label"
          :prepend-icon="item.icon"
          :title="item.label"
          :active="isActive(item)"
          color="primary"
          @click="item.to ? router.push(item.to) : undefined"
        />
      </template>
    </v-list>

    <template #append>
      <v-list-item
        prepend-icon="mdi-account-circle-outline"
        title="ผู้ดูแลระบบ (Admin)"
        subtitle="สายจัดการข้อมูล"
        class="ma-2"
      />
    </template>
  </v-navigation-drawer>

  <v-main>
    <div v-if="$slots.banner" class="px-6 pt-4">
      <slot name="banner" />
    </div>
    <v-container fluid class="pa-6">
      <slot />
    </v-container>
  </v-main>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

interface NavItem { label: string; icon: string; to?: string; }
interface NavGroup { label: string; items: NavItem[]; }

const props = defineProps<{
  breadcrumbs: string[];
  title: string;
  subtitle?: string;
  navGroups?: NavGroup[];
}>();

const router = useRouter();
const route = useRoute();
const drawer = ref(true);

const defaultNavGroups: NavGroup[] = [
  {
    label: 'เมนูหลัก',
    items: [
      { label: 'หน้าแรก', icon: 'mdi-home-outline', to: '/admin' },
      { label: 'จัดการฉบับกฎหมาย', icon: 'mdi-file-document-multiple-outline' },
    ],
  },
  {
    label: 'การจัดการข้อมูล',
    items: [
      { label: 'การนำเข้าข้อมูล', icon: 'mdi-cloud-upload-outline', to: '/admin/upload' },
      { label: 'การจัดการเอกสารเก่า', icon: 'mdi-archive-outline' },
      { label: 'แผนผังความเชื่อมโยง', icon: 'mdi-graph-outline' },
    ],
  },
  {
    label: 'พื้นที่งาน',
    items: [
      { label: 'จัดการผู้ใช้งาน', icon: 'mdi-account-multiple-outline' },
      { label: 'ตั้งค่า', icon: 'mdi-cog-outline' },
    ],
  },
];

const resolvedNavGroups = computed(() => props.navGroups ?? defaultNavGroups);

function isActive(item: NavItem): boolean {
  if (!item.to) return false;
  if (item.to === '/admin') return route.path === item.to;
  return route.path === item.to || route.path.startsWith(`${item.to}/`);
}
</script>
