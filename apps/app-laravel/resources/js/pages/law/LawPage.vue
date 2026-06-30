<template>
  <LawDocumentView :document-id="documentId" />
</template>

<script setup lang="ts">
import LawDocumentView from '../../components/law/LawDocumentView.vue';

defineProps<{ documentId: string }>();
</script>
