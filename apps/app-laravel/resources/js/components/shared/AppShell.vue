<template>
  <v-app-bar v-if="showTopNavigation" color="surface" flat border="b">
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
    </template>
  </v-app-bar>

  <v-navigation-drawer v-model="drawer" width="290">
    <div class="pa-4 d-flex align-center ga-3">
      <v-avatar color="admin-primary" rounded="lg"><v-icon icon="mdi-bank-outline" /></v-avatar>
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
          color="admin-primary"
          @click="item.to ? router.push(item.to) : undefined"
        />
      </template>
    </v-list>

    <template #append>
      <v-list-item class="ma-2" rounded="lg">
        <template #prepend>
          <v-badge dot color="success" location="bottom end" offset-x="2" offset-y="2">
            <v-avatar color="secondary" size="40"><span class="text-caption font-weight-bold">AD</span></v-avatar>
          </v-badge>
        </template>
        <v-list-item-title class="text-body-2 font-weight-bold">ผู้ดูแลระบบ (Admin)</v-list-item-title>
        <v-list-item-subtitle class="text-caption">ลงชื่อออก</v-list-item-subtitle>
        <template #append>
          <v-btn icon="mdi-logout" variant="text" size="small" />
        </template>
      </v-list-item>
    </template>
  </v-navigation-drawer>

  <v-main class="app-shell__main" :class="{ 'app-shell__main--full-height': fullHeight }">
    <div v-if="$slots.banner" class="px-6 pt-4">
      <slot name="banner" />
    </div>
    <v-container fluid class="pa-6 app-shell__container" :class="{ 'app-shell__container--full-height': fullHeight }">
      <div v-if="!showTopNavigation" class="app-shell__page-header">
        <div class="app-shell__page-title">
          <div class="d-flex align-center ga-3 mb-2">
            <v-btn
              icon="mdi-menu"
              size="small"
              variant="text"
              class="app-shell__drawer-toggle"
              @click="drawer = !drawer"
            />
            <v-breadcrumbs
              v-if="breadcrumbs.length"
              :items="breadcrumbs"
              density="compact"
              class="app-shell__breadcrumbs pa-0"
            />
          </div>
          <h1 v-if="title" class="text-h5 font-weight-black mb-1">{{ title }}</h1>
          <p v-if="subtitle" class="text-body-2 text-medium-emphasis mb-0">{{ subtitle }}</p>
        </div>

        <div class="app-shell__page-actions">
          <slot name="actions" />
          <v-badge color="error" content="3" offset-x="4" offset-y="4">
            <v-btn icon="mdi-bell-outline" variant="text" size="small" />
          </v-badge>
        </div>
      </div>

      <slot />
    </v-container>
  </v-main>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

interface NavItem { label: string; icon: string; to?: string; exact?: boolean; }
interface NavGroup { label: string; items: NavItem[]; }

const props = defineProps<{
  breadcrumbs: string[];
  title: string;
  subtitle?: string;
  navGroups?: NavGroup[];
  fullHeight?: boolean;
  hideTopBar?: boolean;
  showTopBar?: boolean;
}>();

const router = useRouter();
const route = useRoute();
const drawer = ref(true);
const showTopNavigation = computed(() => props.showTopBar === true && props.hideTopBar !== true);

const defaultNavGroups: NavGroup[] = [
  {
    label: 'เมนูหลัก',
    items: [
      { label: 'หน้าแรก', icon: 'mdi-home-outline', to: '/admin' },
      { label: 'จัดการตัวบทกฎหมาย', icon: 'mdi-file-document-multiple-outline', to: '/admin/laws' },
    ],
  },
  {
    label: 'การจัดการข้อมูล',
    items: [
      { label: 'การนำเข้าข้อมูล', icon: 'mdi-cloud-upload-outline', to: '/admin/upload' },
      { label: 'คิวตรวจสอบ OCR', icon: 'mdi-eye-check-outline' },
      { label: 'แผนผังความเชื่อมโยง', icon: 'mdi-graph-outline' },
    ],
  },
  {
    label: 'ตั้งค่า & ผู้ใช้งาน',
    items: [
      { label: 'จัดการผู้ใช้งาน', icon: 'mdi-account-multiple-outline' },
      { label: 'ตั้งค่า', icon: 'mdi-cog-outline' },
    ],
  },
];

const resolvedNavGroups = computed(() => props.navGroups ?? defaultNavGroups);

function isActive(item: NavItem): boolean {
  if (!item.to) return false;
  if (item.exact) return route.path === item.to;
  if (item.to === '/admin') return route.path === item.to;
  return route.path === item.to || route.path.startsWith(`${item.to}/`);
}
</script>

<style scoped>
.app-shell__main,
.app-shell__container {
  background: #fff;
}

.app-shell__page-header {
  align-items: flex-start;
  background: #fff;
  display: flex;
  flex: 0 0 auto;
  gap: 16px;
  justify-content: space-between;
  margin-bottom: 24px;
  padding: 18px 20px;
}

.app-shell__page-title {
  min-width: 0;
}

.app-shell__breadcrumbs {
  min-width: 0;
}

.app-shell__drawer-toggle {
  flex: 0 0 auto;
}

.app-shell__page-actions {
  align-items: center;
  display: flex;
  flex: 0 0 auto;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
}

.app-shell__main--full-height {
  height: 100dvh;
  max-height: 100dvh;
  min-height: 0;
  overflow: hidden;
}

.app-shell__container--full-height {
  display: flex;
  flex-direction: column;
  height: 100dvh;
  max-height: 100dvh;
  min-height: 0;
  overflow: hidden;
}

@media (max-width: 720px) {
  .app-shell__page-header {
    flex-direction: column;
  }

  .app-shell__page-actions {
    justify-content: flex-start;
    width: 100%;
  }
}
</style>
