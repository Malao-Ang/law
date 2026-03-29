<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()

const props = defineProps({
  title: { type: String, default: '' },
  breadcrumbs: { type: Array, default: () => [] },
  showBack: { type: Boolean, default: false },
  backTo: { type: [String, Object], default: null },
  rightTitle: { type: String, default: 'ระบบฐานข้อมูลกฎหมาย' },
  rightSubtitle: { type: String, default: 'มหาวิทยาลัยบูรพา (Burapha University)' },
  showStaffSubheader: { type: Boolean, default: true },
  staffName: { type: String, default: 'นางสาวสมศรี ใจดี' },
  staffRole: { type: String, default: 'เจ้าหน้าที่บริหาร' },
  staffUnit: { type: String, default: 'สำนักงานอธิการบดี' },
})

const normalizedCrumbs = computed(() => {
  const crumbs = (props.breadcrumbs || [])
    .map((c) => ({
      text: typeof c === 'string' ? c : c?.text,
      to: typeof c === 'object' ? c?.to : null,
    }))
    .filter((c) => c.text)

  if (props.title && crumbs.length > 1 && crumbs[0]?.text === props.title) {
    return crumbs.slice(1)
  }

  return crumbs
})

const handleBack = () => {
  if (props.backTo) router.push(props.backTo)
  else router.back()
}
</script>

<template>
  <v-app-bar class="app-header" density="comfortable" height="110" flat>
    <div class="app-header__bg"></div>

    <v-container class="app-header__container" fluid>
      <div class="app-header__row">

        <div class="app-header__left">

          <div class="app-header__titleRow">
            <v-btn
              v-if="showBack"
              class="app-header__backBtn"
              icon
              variant="text"
              @click="handleBack"
            >
              <v-icon>mdi-arrow-left</v-icon>
            </v-btn>
            <div class="app-header__title">{{ title }}</div>
          </div>

          <div class="app-header__breadcrumbs" v-if="normalizedCrumbs.length">
            <template v-for="(c, idx) in normalizedCrumbs" :key="idx">
              <span class="app-header__crumb">
                <a
                  v-if="c.to"
                  class="app-header__crumbLink"
                  href="#"
                  @click.prevent="router.push(c.to)"
                >
                  {{ c.text }}
                </a>
                <span v-else>{{ c.text }}</span>
              </span>
              <span v-if="idx < normalizedCrumbs.length - 1" class="app-header__sep">›</span>
            </template>
          </div>

        </div>

        <div class="app-header__right">
          <div class="app-header__rightText">
            <div class="app-header__rightTitle">{{ rightTitle }}</div>
            <div class="app-header__rightSubtitle">
              {{ rightSubtitle }}
            </div>
          </div>

          <img
            class="app-header__logo"
            src="/Buu-logo11.png"
            alt="Burapha University logo"
          />

          <div class="app-header__rightSlot">
            <slot name="actions"></slot>
          </div>
        </div>

      </div>
    </v-container>

    <template v-if="showStaffSubheader" #extension>
      <div class="app-header__subheader">
        <v-container class="app-header__subheaderInner" fluid>
          <div class="app-header__subheaderRow">
            <div class="app-header__staff">
              <v-avatar class="app-header__staffAvatar" color="rgba(255,255,255,0.15)">
                <v-icon color="white" size="14">mdi-account</v-icon>
              </v-avatar>
              <div class="app-header__staffText">
                <span class="app-header__staffName">{{ staffName }}</span>
                <span class="app-header__staffMeta">
                  {{ staffRole }}
                  <span v-if="staffUnit" class="app-header__staffDot">·</span>
                  {{ staffUnit }}
                </span>
              </div>
            </div>
          </div>
        </v-container>
      </div>
    </template>
  </v-app-bar>
</template>

<style scoped>
.app-header {
  position: relative;
  overflow: hidden;
}

.app-header__bg {
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, #ffffff 0%, #eecb57 40%, #9b8d3f 100%);
  opacity: 0.95;
  z-index: 0;
}

.app-header__container {
  position: relative;
  z-index: 1;
  padding-top: 16px;
  padding-bottom: 16px;
}

.app-header__row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.app-header__left {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.app-header__titleRow {
  display: flex;
  align-items: center;
  gap: 8px;
}

.app-header__title {
  font-size: 24px;
  font-weight: 700;
  color: #111827;
}

.app-header__breadcrumbs {
  font-size: 12px;
  color: #6b7280;
}

.app-header__crumbLink {
  color: inherit;
  text-decoration: none;
}

.app-header__crumbLink:hover {
  text-decoration: underline;
}

.app-header__sep {
  margin: 0 6px;
}

.app-header__backBtn {
  margin-right: 4px;
}

.app-header__right {
  display: flex;
  align-items: center;
  gap: 14px;
}

.app-header__rightText {
  text-align: right;
}

.app-header__rightTitle {
  font-size: 16px;
  font-weight: 600;
  color: #ffffff;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
}

.app-header__rightSubtitle {
  font-size: 13px;
  color: rgba(255, 255, 255, 0.9);
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.app-header__logo {
  max-height: 60px;
  width: auto;
  object-fit: contain;
}

.app-header__subheader {
  width: 100%;
  background: linear-gradient(90deg, #ffffff 0%, #f7e4a7 40%, #9b8d3f 100%);
  border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.app-header__subheaderInner {
  padding-top: 8px;
  padding-bottom: 8px;
}

.app-header__subheaderRow {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  flex-wrap: wrap;
}

.app-header__staff {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}


.app-header__staffText {
  text-align: right;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.app-header__staffLabel {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgba(255, 255, 255, 0.55);
}

.app-header__staffName {
  font-size: 14px;
  font-weight: 600;
  color: #ffffff;
  line-height: 1.3;
}

.app-header__staffMeta {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.75);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.app-header__staffDot {
  margin: 0 6px;
  opacity: 0.6;
}

.app-header__logoutBtn {
  color: rgba(255, 255, 255, 0.85) !important;
  flex-shrink: 0;
  opacity: 0.85;
}

.app-header__logoutBtn :deep(.v-btn__prepend) {
  margin-inline-end: 4px;
}
</style>
