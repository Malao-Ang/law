<template>
  <v-app>
    <HeaderComponent v-if="!isFullScreenRoute" />
    <v-main :class="{ 'review-main': isFullScreenRoute }">
      <v-container v-if="!isFullScreenRoute">
        <RouterView />
      </v-container>
      <RouterView v-else />
    </v-main>
  </v-app>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';
import HeaderComponent from './components/HeaderComponent.vue';

const route = useRoute();
const isFullScreenRoute = computed(() => ['review', 'compose'].includes(String(route.name ?? '')));
</script>
