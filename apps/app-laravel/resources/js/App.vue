<template>
  <div class="layout">
    <header class="topbar">
      <div class="brand">Thai Legal OCR POC</div>
      <nav class="nav">
        <RouterLink to="/">Upload</RouterLink>
      </nav>
    </header>

    <main class="container">
      <RouterView />
    </main>
  </div>
</template>

<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router';
</script>
