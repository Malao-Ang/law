<template>
  <ESignDocumentWorkspace :document-id="documentId" mode="edit" />
</template>

<script setup lang="ts">
import ESignDocumentWorkspace from '../../components/esign/ESignDocumentWorkspace.vue';

defineProps<{ documentId: string }>();
</script>
