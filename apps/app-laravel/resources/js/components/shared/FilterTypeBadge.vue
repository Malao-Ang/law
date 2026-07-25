<script setup lang="ts">
// Pill filter row from Figma eLaw_MainPage (node 67:5758).
// v-model holds the selected label.
const model = defineModel<string>({ default: 'ทั้งหมด' });

withDefaults(defineProps<{ options?: string[] }>(), {
  options: () => ['ทั้งหมด', 'พ.ร.บ.', 'ข้อบังคับ', 'ระเบียบ', 'ประกาศ'],
});
</script>

<template>
  <div class="filter-row">
    <button
      v-for="opt in options"
      :key="opt"
      type="button"
      class="filter-pill"
      :class="{ 'filter-pill--active': model === opt }"
      @click="model = opt"
    >
      {{ opt }}
    </button>
  </div>
</template>

<style scoped>
.filter-row {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
}

.filter-pill {
  background: #ffffff;
  border: 1px solid #d2c5b3;
  border-radius: 9999px;
  padding: 9px 21px;
  font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
  font-weight: 700;
  font-size: 18px;
  line-height: 24px;
  letter-spacing: 0.36px;
  color: #3c2900;
  cursor: pointer;
  white-space: nowrap;
  transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.filter-pill:focus-visible {
  outline: 2px solid #ab7f29;
  outline-offset: 2px;
}

/* ponytail: active fill falls back to the current gold primary until the missing Figma node is fetched. */
.filter-pill--active {
  background: #ab7f29;
  border-color: #ab7f29;
  color: #ffffff;
}
</style>
