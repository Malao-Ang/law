<template>
  <v-card>
    <v-card-text class="d-flex align-center ga-4">
      <v-avatar rounded="lg" :style="{ background: iconBg }" size="48">
        <v-icon :icon="icon" :color="iconColor" size="22" />
      </v-avatar>
      <div>
        <div class="text-h5 font-weight-bold">{{ number }}</div>
        <div class="text-caption text-medium-emphasis">{{ label }}</div>
      </div>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
defineProps<{
  icon: string;
  iconColor: string;
  iconBg: string;
  number: number;
  label: string;
}>();
</script>
