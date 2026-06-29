<template>
  <nav class="elaw-navbar">
    <div class="elaw-navbar__inner">
      <div class="elaw-navbar__brand">
        <span class="elaw-navbar__logo-icon">⚖</span>
        <span class="elaw-navbar__brand-name">e-Law</span>
        <span class="elaw-navbar__brand-sub">ระบบฐานข้อมูลกฎหมาย</span>
      </div>

      <ul class="elaw-navbar__links">
        <li v-for="link in navLinks" :key="link.label">
          <a class="elaw-navbar__link" href="#">{{ link.label }}</a>
        </li>
      </ul>

      <button class="elaw-navbar__admin-btn" type="button" @click="$emit('go-admin')">
        <span class="mdi mdi-shield-account-outline" aria-hidden="true"></span>
        สำหรับเจ้าหน้าที่
      </button>
    </div>
  </nav>
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

<style scoped>
.elaw-navbar {
  background: #fff;
  border-bottom: 1px solid var(--elaw-border);
  position: sticky;
  top: 0;
  z-index: 100;
}

.elaw-navbar__inner {
  max-width: 1280px;
  margin: 0 auto;
  padding: 0 24px;
  min-height: 60px;
  display: flex;
  align-items: center;
  gap: 32px;
}

.elaw-navbar__brand {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.elaw-navbar__logo-icon {
  font-size: 22px;
  color: var(--elaw-warm-gold-deep);
}

.elaw-navbar__brand-name {
  font-size: 18px;
  font-weight: 800;
  color: var(--elaw-navy);
}

.elaw-navbar__brand-sub {
  font-size: 12px;
  color: var(--elaw-muted);
  padding-left: 8px;
  border-left: 1px solid var(--elaw-border);
}

.elaw-navbar__links {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  gap: 4px;
  flex: 1;
}

.elaw-navbar__link {
  font-size: 14px;
  color: var(--elaw-text);
  text-decoration: none;
  padding: 6px 12px;
  border-radius: 6px;
  transition: background 0.12s;
}

.elaw-navbar__link:hover {
  background: var(--elaw-cream);
  color: var(--elaw-navy);
}

.elaw-navbar__admin-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  background: var(--elaw-navy);
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.12s;
  font-family: inherit;
}

.elaw-navbar__admin-btn:hover {
  background: var(--law-primary-deep);
}

@media (max-width: 900px) {
  .elaw-navbar__inner {
    flex-wrap: wrap;
    padding-top: 12px;
    padding-bottom: 12px;
    gap: 16px;
  }

  .elaw-navbar__links {
    order: 3;
    width: 100%;
    overflow-x: auto;
    padding-bottom: 2px;
  }
}
</style>
