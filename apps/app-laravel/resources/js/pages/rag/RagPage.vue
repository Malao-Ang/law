<template>
  <RagManageWorkspace :document-id="documentId" />
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import RagManageWorkspace from '../../components/rag/RagManageWorkspace.vue';
import { useHistoricalRedirect } from '../../composables/useHistoricalRedirect';

const props = defineProps<{ documentId: string }>();
useHistoricalRedirect(props.documentId);

onMounted(() => {
  document.body.classList.add('rag-page-lock-scroll');
});

onBeforeUnmount(() => {
  document.body.classList.remove('rag-page-lock-scroll');
});
</script>

<style>
body.rag-page-lock-scroll {
  overflow: hidden;
}

body.rag-page-lock-scroll .v-application,
body.rag-page-lock-scroll .v-layout.v-layout--full-height {
  height: 100dvh;
  max-height: 100dvh;
  overflow: hidden;
}

body.rag-page-lock-scroll .v-main {
  overflow: hidden;
}
</style>
