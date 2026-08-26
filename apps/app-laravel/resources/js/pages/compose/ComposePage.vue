<template>
  <DocumentComposeWorkspace :document-id="documentId" mode="compose" />
</template>

<script setup lang="ts">
import DocumentComposeWorkspace from '../../components/compose/DocumentComposeWorkspace.vue';
import { useHistoricalRedirect } from '../../composables/useHistoricalRedirect';

const props = defineProps<{
  documentId: string;
}>();
useHistoricalRedirect(props.documentId);
</script>
