<template>
  <!-- Errors → blocking dialog (must be acknowledged) -->
  <v-dialog :model-value="isError && store.show" max-width="420" @update:model-value="close">
    <v-card rounded="lg">
      <v-card-text class="text-center pa-6">
        <v-icon color="error" size="52" class="mb-3">mdi-alert-circle-outline</v-icon>
        <div class="text-body-1" style="white-space: pre-line">{{ store.message }}</div>
      </v-card-text>
      <v-divider />
      <v-card-actions class="justify-center py-2">
        <v-btn color="error" variant="flat" @click="close">ตกลง</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Success / info → transient snackbar -->
  <v-snackbar
    :model-value="!isError && store.show"
    :color="store.color"
    :timeout="3000"
    location="top right"
    @update:model-value="close"
  >
    {{ store.message }}
    <template #actions>
      <v-btn variant="text" @click="close">ปิด</v-btn>
    </template>
  </v-snackbar>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useSnackbarStore } from '../../stores/snackbarStore';

const store = useSnackbarStore();
const isError = computed(() => store.color === 'error');

function close(): void {
  store.show = false;
}
</script>
