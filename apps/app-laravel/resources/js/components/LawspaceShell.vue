<template>
  <section class="lawspace-shell">
    <aside class="lawspace-sidebar">
      <div class="lawspace-brand">
        <div class="lawspace-brand__mark">
          <i class="mdi mdi-bank-outline"></i>
        </div>
        <div>
          <p class="lawspace-brand__title">LAWSPACE</p>
          <p class="lawspace-brand__subtitle">ระบบจัดการฐานข้อมูลกฎหมาย</p>
        </div>
      </div>

      <div
        v-for="group in navGroups"
        :key="group.label"
        class="lawspace-nav-group"
      >
        <p class="lawspace-nav-group__label">{{ group.label }}</p>
        <button
          v-for="item in group.items"
          :key="item.label"
          type="button"
          class="lawspace-nav-item"
          :class="{ 'is-active': item.active }"
        >
          <i class="mdi lawspace-nav-item__icon" :class="item.icon"></i>
          <span>{{ item.label }}</span>
        </button>
      </div>

      <div class="lawspace-user-card">
        <div class="lawspace-user-card__avatar">AD</div>
        <div>
          <p class="lawspace-user-card__name">ผู้ดูแลระบบ (Admin)</p>
          <p class="lawspace-user-card__role">สายจัดการข้อมูล</p>
        </div>
      </div>
    </aside>

    <div class="lawspace-content">
      <header class="lawspace-header">
        <div class="lawspace-header__top">
          <nav class="lawspace-breadcrumbs" aria-label="Breadcrumb">
            <span
              v-for="(crumb, index) in breadcrumbs"
              :key="`${crumb}-${index}`"
              class="lawspace-breadcrumbs__item"
            >
              {{ crumb }}
            </span>
          </nav>

          <button type="button" class="lawspace-notify-button" aria-label="Notifications">
            <i class="mdi mdi-bell-outline"></i>
          </button>
        </div>

        <div class="lawspace-header__main">
          <div>
            <h1 class="lawspace-page-title">{{ title }}</h1>
            <p v-if="subtitle" class="lawspace-page-subtitle">{{ subtitle }}</p>
          </div>

          <div class="lawspace-header__actions">
            <slot name="actions" />
          </div>
        </div>
      </header>

      <div v-if="$slots.banner" class="lawspace-banner">
        <slot name="banner" />
      </div>

      <main class="lawspace-main">
        <slot />
      </main>
    </div>
  </section>
</template>

<script setup lang="ts">
defineProps<{
  breadcrumbs: string[];
  title: string;
  subtitle?: string;
}>();

const navGroups = [
  {
    label: 'เมนูหลัก',
    items: [
      { label: 'หน้าแรก', icon: 'mdi-home-outline', active: false },
      { label: 'จัดการฉบับกฎหมาย', icon: 'mdi-file-document-multiple-outline', active: false },
    ],
  },
  {
    label: 'การจัดการข้อมูล',
    items: [
      { label: 'การนำเข้าข้อมูล', icon: 'mdi-cloud-upload-outline', active: true },
      { label: 'การจัดการเอกสารเก่า', icon: 'mdi-archive-outline', active: false },
      { label: 'แผนผังความเชื่อมโยง', icon: 'mdi-graph-outline', active: false },
    ],
  },
  {
    label: 'พื้นที่งาน',
    items: [
      { label: 'จัดการผู้ใช้งาน', icon: 'mdi-account-multiple-outline', active: false },
      { label: 'ตั้งค่า', icon: 'mdi-cog-outline', active: false },
    ],
  },
];
</script>
