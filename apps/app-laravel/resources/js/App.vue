<template>
  <v-app>
   <HeaderComponent />
    <v-main>
      <v-container>
        <RouterView />
      </v-container>
    </v-main>
  </v-app>
</template>

<script setup lang="ts">
import { RouterView } from 'vue-router';
import HeaderComponent from './components/HeaderComponent.vue';
</script>
