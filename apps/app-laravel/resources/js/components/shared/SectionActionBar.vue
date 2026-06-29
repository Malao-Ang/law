<template>
  <div class="section-action-bar">
    <button class="section-action-bar__btn" type="button" @click="$emit('edit')">
      <span class="mdi mdi-pencil-outline" aria-hidden="true"></span>
      แก้ไขมาตรา
    </button>
    <button class="section-action-bar__btn" type="button" @click="$emit('history')">
      <span class="mdi mdi-history" aria-hidden="true"></span>
      ประวัติ
    </button>
    <button class="section-action-bar__btn" type="button" @click="$emit('relations')">
      <span class="mdi mdi-graph-outline" aria-hidden="true"></span>
      ความสัมพันธ์
    </button>
    <button class="section-action-bar__btn" type="button" @click="$emit('copy')">
      <span class="mdi mdi-content-copy" aria-hidden="true"></span>
      คัดลอก
    </button>
    <button class="section-action-bar__btn section-action-bar__btn--danger" type="button" @click="$emit('cancel')">
      <span class="mdi mdi-cancel" aria-hidden="true"></span>
      ยกเลิกมาตรา
    </button>
  </div>
</template>

<script setup lang="ts">
defineEmits<{
  edit: [];
  history: [];
  relations: [];
  copy: [];
  cancel: [];
}>();
</script>
