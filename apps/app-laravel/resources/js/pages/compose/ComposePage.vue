<template>
  <DocumentComposeWorkspace :document-id="documentId" mode="compose" />
</template>

<script setup lang="ts">
import DocumentComposeWorkspace from '../../components/compose/DocumentComposeWorkspace.vue';

defineProps<{
  documentId: string;
}>();
</script>
