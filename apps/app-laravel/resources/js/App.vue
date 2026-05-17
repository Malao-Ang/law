<template>
  <v-app>
    <HeaderComponent v-if="route.name !== 'review'" />
    <v-main :class="{ 'review-main': route.name === 'review' }">
      <v-container v-if="route.name !== 'review'">
        <RouterView />
      </v-container>
      <RouterView v-else />
    </v-main>
  </v-app>
</template>

<script setup lang="ts">
import { RouterView, useRoute } from 'vue-router';
import HeaderComponent from './components/HeaderComponent.vue';

const route = useRoute();
</script>
