<template>
  <section class="panel">
    <h3>Diff</h3>
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
  </section>
</template>

<script setup lang="ts">
defineProps<{
  before: string;
  after: string;
}>();
</script>
