<template>
  <v-navigation-drawer
    :rail="rail"
    :model-value="true"
    rail-width="64"
    width="260"
    permanent
    class="app-drawer"
  >
    <!-- Drawer header: logo + collapse toggle -->
    <div class="app-drawer__header" :class="{ 'app-drawer__header--rail': rail }">
      <div class="d-flex align-center ga-2 min-width-0">
        <v-avatar color="admin-primary" rounded="lg" size="36" class="flex-shrink-0">
          <v-icon icon="mdi-bank-outline" size="18" />
        </v-avatar>
        <Transition name="fade-slide">
          <div v-if="!rail" class="min-width-0">
            <p class="text-subtitle-2 font-weight-bold mb-0 text-no-wrap">LAWSPACE</p>
            <p class="text-caption text-medium-emphasis mb-0 text-no-wrap">ระบบจัดการกฎหมาย</p>
          </div>
        </Transition>
      </div>
      <v-btn
        :icon="rail ? 'mdi-chevron-right' : 'mdi-chevron-left'"
        variant="text"
        size="small"
        class="flex-shrink-0"
        @click="rail = !rail"
      />
    </div>

    <v-divider />

    <v-list nav density="comfortable" class="pt-2">
      <template v-for="group in resolvedNavGroups" :key="group.label">
        <v-list-subheader v-if="!rail" class="text-caption font-weight-bold">
          {{ group.label }}
        </v-list-subheader>
        <v-divider v-else class="my-1 mx-2" />
        <v-list-item
          v-for="item in group.items"
          :key="item.label"
          :prepend-icon="item.icon"
          :title="item.label"
          :active="isActive(item)"
          color="admin-primary"
          rounded="lg"
          @click="item.to ? router.push(item.to) : undefined"
        />
      </template>
    </v-list>

    <template #append>
      <v-divider />
      <div class="app-drawer__user" :class="{ 'app-drawer__user--rail': rail }">
        <v-badge dot color="success" location="bottom end" offset-x="2" offset-y="2">
          <v-avatar color="secondary" size="36" class="flex-shrink-0">
            <span class="text-caption font-weight-bold">AD</span>
          </v-avatar>
        </v-badge>
        <Transition name="fade-slide">
          <div v-if="!rail" class="min-width-0 flex-1">
            <p class="text-body-2 font-weight-bold mb-0 text-truncate">ผู้ดูแลระบบ</p>
            <p class="text-caption text-medium-emphasis mb-0">Admin</p>
          </div>
        </Transition>
        <v-btn
          v-if="!rail"
          icon="mdi-logout"
          variant="text"
          size="small"
          class="flex-shrink-0"
        />
      </div>
    </template>
  </v-navigation-drawer>

  <v-main class="app-shell__main" :class="{ 'app-shell__main--full-height': fullHeight }">
    <div class="app-shell__topbar">
      <v-breadcrumbs
        v-if="breadcrumbs.length"
        :items="breadcrumbs"
        density="compact"
        class="app-shell__breadcrumbs pa-0"
      />
      <div class="app-shell__topbar-right">
        <slot name="actions" />
        <v-badge v-if="showBell" color="error" content="3" offset-x="4" offset-y="4">
          <v-btn icon="mdi-bell-outline" variant="text" size="small" />
        </v-badge>
      </div>
    </div>

    <div v-if="title" class="app-shell__page-title">
      <div class="min-width-0">
        <h1 class="text-h5 font-weight-black mb-0">{{ title }}</h1>
        <p v-if="subtitle" class="text-body-2 text-medium-emphasis mb-0">{{ subtitle }}</p>
      </div>
      <div v-if="$slots['title-actions']" class="flex-shrink-0">
        <slot name="title-actions" />
      </div>
    </div>

    <div v-if="$slots.banner" class="px-6 pt-2">
      <slot name="banner" />
    </div>

    <v-container
      fluid
      class="app-shell__content pa-4 pt-4"
      :class="{ 'app-shell__content--full-height': fullHeight }"
    >
      <slot />
    </v-container>
  </v-main>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const RAIL_KEY = 'lawspace.nav.rail';

interface NavItem { label: string; icon: string; to?: string; exact?: boolean; }
interface NavGroup { label: string; items: NavItem[]; }

const props = defineProps<{
  breadcrumbs: string[];
  title: string;
  subtitle?: string;
  navGroups?: NavGroup[];
  fullHeight?: boolean;
  showBell?: boolean;
  // legacy props kept for compat — no longer affect layout
  hideTopBar?: boolean;
  showTopBar?: boolean;
}>();

const router = useRouter();
const route = useRoute();
const rail = ref(localStorage.getItem(RAIL_KEY) === '1');
watch(rail, (v) => localStorage.setItem(RAIL_KEY, v ? '1' : '0'));

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
      { label: 'คิวตรวจสอบ OCR', icon: 'mdi-eye-check-outline', to: '/admin/ocr-queue' },
      { label: 'ความสัมพันธ์กฎหมาย', icon: 'mdi-graph-outline', to: '/admin/relations' },
    ],
  },
  {
    label: 'ตั้งค่า & ผู้ใช้งาน',
    items: [
      { label: 'จัดการผู้ใช้งาน', icon: 'mdi-account-multiple-outline' },
      { label: 'ตั้งค่า', icon: 'mdi-cog-outline' },
    ],
  },
  {
    label: 'ลิงก์ด่วน',
    items: [
      { label: 'กลับหน้าหลัก', icon: 'mdi-home-circle-outline', to: '/' },
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
/* ─── Drawer ─────────────────────────────────────────────── */
.app-drawer :deep(.v-navigation-drawer__content) {
  display: flex;
  flex-direction: column;
}

.app-drawer__header {
  align-items: center;
  display: flex;
  gap: 8px;
  justify-content: space-between;
  min-height: 64px;
  overflow: hidden;
  padding: 12px 12px 12px 16px;
}

.app-drawer__header--rail {
  flex-direction: column;
  gap: 4px;
  justify-content: center;
  padding: 12px 0;
}

.app-drawer__user {
  align-items: center;
  display: flex;
  gap: 10px;
  min-height: 64px;
  overflow: hidden;
  padding: 12px 12px 12px 14px;
}

.app-drawer__user--rail {
  justify-content: center;
  padding: 12px 0;
}

/* ─── Main ───────────────────────────────────────────────── */
.app-shell__main {
  background: #f8f7f5;
}

.app-shell__topbar {
  align-items: center;
  background: #fff;
  border-bottom: 1px solid rgba(0, 0, 0, 0.08);
  display: flex;
  gap: 12px;
  justify-content: space-between;
  min-height: 52px;
  padding: 8px 24px;
}

.app-shell__breadcrumbs {
  min-width: 0;
}

.app-shell__topbar-right {
  align-items: center;
  display: flex;
  flex-shrink: 0;
  gap: 4px;
}

.app-shell__page-title {
  align-items: center;
  background: #fff;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
  display: flex;
  gap: 16px;
  justify-content: space-between;
  padding: 14px 24px 16px;
}

.app-shell__content {
  background: #f8f7f5;
}

.app-shell__main--full-height {
  height: 100dvh;
  max-height: 100dvh;
  min-height: 0;
  overflow: hidden;
}

.app-shell__content--full-height {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  overflow: hidden;
}

/* ─── Transition ─────────────────────────────────────────── */
.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.fade-slide-enter-from,
.fade-slide-leave-to {
  opacity: 0;
  transform: translateX(-6px);
}

/* ─── Utilities ──────────────────────────────────────────── */
.min-width-0 { min-width: 0; }
.flex-1 { flex: 1; }
</style>
