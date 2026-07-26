<template>
  <ESignPreviewWorkspace :document-id="documentId" />
</template>

<script setup lang="ts">
import ESignPreviewWorkspace from '../../components/esign/ESignPreviewWorkspace.vue';

defineProps<{ documentId: string }>();
</script>
