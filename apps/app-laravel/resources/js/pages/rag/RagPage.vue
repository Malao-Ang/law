<template>
  <RagManageWorkspace :document-id="documentId" />
</template>

<script setup lang="ts">
import RagManageWorkspace from '../../components/rag/RagManageWorkspace.vue';

defineProps<{ documentId: string }>();
</script>
