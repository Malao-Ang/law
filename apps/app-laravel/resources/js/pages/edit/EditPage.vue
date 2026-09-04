<template>
  <EditHubWorkspace :document-id="documentId" />
</template>

<script setup lang="ts">
import EditHubWorkspace from '../../components/edit/EditHubWorkspace.vue';

defineProps<{ documentId: string }>();
</script>
