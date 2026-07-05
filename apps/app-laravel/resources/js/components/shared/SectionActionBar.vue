<template>
  <div class="d-flex ga-2 flex-wrap align-center py-1">
    <v-btn size="small" variant="outlined" @click="$emit('edit')">
      <v-icon icon="mdi-pencil-outline" />
      แก้ไขมาตรา
    </v-btn>
    <v-btn size="small" variant="outlined" @click="$emit('history')">
      <v-icon icon="mdi-history" />
      ประวัติ
    </v-btn>
    <v-btn size="small" variant="outlined" @click="$emit('relations')">
      <v-icon icon="mdi-graph-outline" />
      ความสัมพันธ์
    </v-btn>
    <v-btn size="small" variant="outlined" @click="$emit('copy')">
      <v-icon icon="mdi-content-copy" />
      คัดลอก
    </v-btn>
    <v-btn size="small" variant="outlined" color="error" @click="$emit('cancel')">
      <v-icon icon="mdi-cancel" />
      ยกเลิกมาตรา
    </v-btn>
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
