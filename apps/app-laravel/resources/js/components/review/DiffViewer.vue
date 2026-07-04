<template>
  <v-card flat border rounded="lg" class="pa-3">
    <p class="text-subtitle-2 font-weight-bold mb-2">Diff</p>
    <div class="diff-grid">
      <div>
        <p class="hint">Before</p>
        <pre class="code">{{ before }}</pre>
      </div>
      <div>
        <p class="hint">After</p>
        <pre class="code">{{ after }}</pre>
      </div>
    </div>
  </v-card>
</template>

<script setup lang="ts">
defineProps<{
  before: string;
  after: string;
}>();
</script>
