<template>
  <section class="elaw-hero">
    <v-container class="text-center" style="max-width: 760px">
      <h1 class="text-h4 font-weight-black text-elaw-navy mb-2">ค้นหากฎหมาย</h1>
      <p class="text-subtitle-1 text-medium-emphasis mb-7">ฐานข้อมูลกฎหมาย ระเบียบ ประกาศ และข้อบังคับ</p>

      <div class="d-flex flex-column flex-sm-row align-stretch align-sm-center ga-2">
        <v-text-field
          v-model="query"
          placeholder="ค้นหาชื่อกฎหมาย หน่วยงาน หรือเลขที่..."
          append-inner-icon="mdi-magnify"
          variant="outlined"
          density="comfortable"
          rounded="lg"
          hide-details
          class="flex-grow-1"
          @keydown.enter="$emit('search', query)"
          @click:append-inner="$emit('search', query)"
        />
        <v-btn flat color="elaw-navy" size="large" @click="$emit('search', query)">ค้นหา</v-btn>
      </div>

      <v-chip-group v-model="activeType" class="justify-center mt-5">
        <v-chip
          v-for="chip in typeChips"
          :key="chip.value"
          :value="chip.value"
          color="elaw-navy"
          variant="outlined"
          rounded="pill"
          size="small"
        >
          {{ chip.label }}
        </v-chip>
      </v-chip-group>
    </v-container>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue';

defineEmits<{ search: [string] }>();

const query = ref('');
const activeType = ref<string | null>(null);

const typeChips = [
  { label: 'ทั้งหมด', value: 'all' },
  { label: 'กฎหมายหลัก', value: 'kotmai-luang' },
  { label: 'ระเบียบ', value: 'rabiap' },
  { label: 'ข้อบังคับ', value: 'kho-bangkhab' },
  { label: 'ประกาศ', value: 'prakat' },
  { label: 'คำสั่ง', value: 'kham-sang' },
  { label: 'แนวปฏิบัติ', value: 'naeo-patibat' },
];
</script>

<style scoped>
/* ponytail: brand gradient hero background + bottom border, no Vuetify token covers this, keep as CSS */
.elaw-hero {
  background: linear-gradient(160deg, var(--elaw-cream) 0%, var(--elaw-bg) 100%);
  border-bottom: 1px solid var(--elaw-border);
  padding: 56px 24px 48px;
}

@media (max-width: 640px) {
  .elaw-hero {
    padding: 40px 16px 32px;
  }
}
</style>
