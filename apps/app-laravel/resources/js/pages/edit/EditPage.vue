<template>
  <ESignDocumentWorkspace :document-id="documentId" mode="edit" />
</template>

<script setup lang="ts">
import ESignDocumentWorkspace from '../../components/esign/ESignDocumentWorkspace.vue';
import { useHistoricalRedirect } from '../../composables/useHistoricalRedirect';

const props = defineProps<{ documentId: string }>();
useHistoricalRedirect(props.documentId);
</script>
