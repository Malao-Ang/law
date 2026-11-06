<template>
  <v-app>
    <RouterView :key="auth.scopeRevision" v-if="isBareLayout" />
    <v-main v-else>
      <v-container>
        <RouterView :key="auth.scopeRevision" />
      </v-container>
    </v-main>
    <GlobalSnackbar />
    <RoleSelectDialog />
  </v-app>
</template>

<script setup lang="ts">
import { useAuthStore } from './stores/authStore';
import { computed, watch } from 'vue';
import { useDocumentStore } from './stores/documentStore';
import { usePreviewStore } from './stores/previewStore';
import { useVersionStore } from './stores/versionStore';
import { clearReviewCache } from './stores/reviewCache';
import { RouterView, useRoute } from 'vue-router';
import GlobalSnackbar from './components/shared/GlobalSnackbar.vue';
import RoleSelectDialog from './components/auth/RoleSelectDialog.vue';

const route = useRoute();
const auth = useAuthStore();
watch(() => `${auth.scopeRevision}:${/^\/(admin|documents)(\/|$)/.test(route.path)}`, () => {
  clearReviewCache();
  useDocumentStore().reset();
  usePreviewStore().reset();
  useVersionStore().reset();
}, { flush: 'sync' });
const isBareLayout = computed(() => Boolean(route.meta.bareLayout));
</script>
