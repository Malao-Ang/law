<template>
  <v-snackbar v-model="store.show" :color="store.color" :timeout="3000" location="top right">
    {{ store.message }}
    <template #actions>
      <v-btn variant="text" @click="store.show = false">ปิด</v-btn>
    </template>
  </v-snackbar>
</template>

<script setup lang="ts">
import { useSnackbarStore } from '../../stores/snackbarStore';

const store = useSnackbarStore();
</script>
