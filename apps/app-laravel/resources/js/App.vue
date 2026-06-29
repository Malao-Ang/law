<template>
  <v-app>
    <v-main :class="{ 'review-main': isBareLayout }">
      <v-container v-if="!isBareLayout">
        <RouterView />
      </v-container>
      <RouterView v-else />
    </v-main>
  </v-app>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';

const route = useRoute();
const isBareLayout = computed(() => Boolean(route.meta.bareLayout));
</script>
