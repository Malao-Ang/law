<script setup lang="ts">
// Colored info strip from Figma eLaw_MainPage (LawData1-5).
// Body content comes from the default slot.
import { computed } from 'vue';

type Tone = 'agency' | 'amend' | 'version' | 'warning' | 'repealed';

const props = withDefaults(defineProps<{ tone: Tone; label: string; icon?: string }>(), {
  icon: '',
});

const TONES: Record<Tone, { accent: string; bg: string; label: string; icon: string }> = {
  agency: { accent: '#10b981', bg: '#ecfdf5', label: '#059669', icon: 'mdi-domain' },
  amend: { accent: '#6366f1', bg: '#eef2ff', label: '#6366f1', icon: 'mdi-file-document-edit-outline' },
  version: { accent: '#0ea5e9', bg: '#f0f9ff', label: '#0ea5e9', icon: 'mdi-history' },
  warning: { accent: '#f59e0b', bg: '#fffbeb', label: '#92400e', icon: 'mdi-alert-outline' },
  repealed: { accent: '#e11d48', bg: '#ffe4e6', label: '#e11d48', icon: 'mdi-close-circle-outline' },
};

const toneStyles = computed(() => TONES[props.tone]);
const iconName = computed(() => props.icon || toneStyles.value.icon);
</script>

<template>
  <div
    class="law-strip"
    :style="{
      backgroundColor: toneStyles.bg,
      borderColor: toneStyles.accent,
      borderLeftColor: toneStyles.accent,
    }"
  >
    <v-icon :icon="iconName" size="20" :color="toneStyles.accent" class="law-strip__icon" />
    <div class="law-strip__content">
      <div class="law-strip__label" :style="{ color: toneStyles.label }">{{ label }}</div>
      <div class="law-strip__body">
        <slot />
      </div>
    </div>
  </div>
</template>

<style scoped>
.law-strip {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding: 15px 15px 15px 18px;
  border: 1px solid;
  border-left-width: 4px;
  border-radius: 0 12px 12px 0;
}

.law-strip__icon {
  margin-top: 2px;
  flex: 0 0 auto;
}

.law-strip__content {
  min-width: 0;
}

.law-strip__label {
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-weight: 700;
  font-size: 11px;
  line-height: 16.5px;
  letter-spacing: 0.55px;
  text-transform: uppercase;
}

.law-strip__body {
  margin-top: 2px;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-size: 12px;
  line-height: 16px;
  color: #1e293b;
}
</style>
