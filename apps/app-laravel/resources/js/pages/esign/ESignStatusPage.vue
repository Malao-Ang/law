<template>
  <ESignStatusWorkspace :document-id="documentId" />
</template>

<script setup lang="ts">
import ESignStatusWorkspace from '../../components/esign/ESignStatusWorkspace.vue';

defineProps<{ documentId: string }>();
</script>
