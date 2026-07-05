<template>
  <v-app>
    <RouterView v-if="isBareLayout" />
    <v-main v-else>
      <v-container>
        <RouterView />
      </v-container>
    </v-main>
    <GlobalSnackbar />
  </v-app>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';
import GlobalSnackbar from './components/shared/GlobalSnackbar.vue';

const route = useRoute();
const isBareLayout = computed(() => Boolean(route.meta.bareLayout));
</script>
