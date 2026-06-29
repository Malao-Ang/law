<template>
  <section class="elaw-hero">
    <div class="elaw-hero__inner">
      <h1 class="elaw-hero__heading">ค้นหากฎหมาย</h1>
      <p class="elaw-hero__sub">ฐานข้อมูลกฎหมาย ระเบียบ ประกาศ และข้อบังคับ</p>

      <div class="elaw-hero__search-wrap">
        <span class="mdi mdi-magnify elaw-hero__search-icon" aria-hidden="true"></span>
        <input v-model="query" class="elaw-hero__search-input" type="text"
          placeholder="ค้นหาชื่อกฎหมาย หน่วยงาน หรือเลขที่..." @keydown.enter="$emit('search', query)">

      </div>
      <button class="elaw-hero__search-btn" type="button" @click="$emit('search', query)">
        ค้นหา
      </button>
      <div class="elaw-hero__chips">
        <button v-for="chip in typeChips" :key="chip.value" class="elaw-hero__chip"
          :class="{ 'elaw-hero__chip--active': activeType === chip.value }" type="button"
          @click="toggleType(chip.value)">
          {{ chip.label }}
        </button>
      </div>
    </div>
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

function toggleType(value: string): void {
  activeType.value = activeType.value === value ? null : value;
}
</script>

<style scoped>
.elaw-hero {
  background: linear-gradient(160deg, var(--elaw-cream) 0%, var(--elaw-bg) 100%);
  border-bottom: 1px solid var(--elaw-border);
  padding: 56px 24px 48px;
}

.elaw-hero__inner {
  max-width: 760px;
  margin: 0 auto;
  text-align: center;
}

.elaw-hero__heading {
  font-size: 36px;
  font-weight: 800;
  color: var(--elaw-navy);
  margin: 0 0 8px;
}

.elaw-hero__sub {
  font-size: 15px;
  color: var(--elaw-muted);
  margin: 0 0 28px;
}

.elaw-hero__search-wrap {
  position: relative;
  display: flex;
  align-items: center;
  background: #fff;
  border: 2px solid var(--elaw-border);
  border-radius: 12px;
  box-shadow: 0 2px 12px rgba(26, 46, 82, 0.08);
  overflow: hidden;
  transition: border-color 0.15s;
}

.elaw-hero__search-wrap:focus-within {
  border-color: var(--elaw-warm-gold);
}

.elaw-hero__search-icon {
  position: absolute;
  left: 16px;
  font-size: 20px;
  color: var(--elaw-muted);
  pointer-events: none;
}

.elaw-hero__search-input {
  flex: 1;
  border: none;
  outline: none;
  padding: 14px 14px 14px 46px;
  font-size: 15px;
  font-family: inherit;
  color: var(--elaw-text);
  background: transparent;
}

.elaw-hero__search-btn {
  padding: 0 24px;
  min-height: 50px;
  background: var(--elaw-navy);
  color: #fff;
  border: none;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: background 0.12s;
}

.elaw-hero__search-btn:hover {
  background: var(--law-primary-deep);
}

.elaw-hero__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
  margin-top: 20px;
}

.elaw-hero__chip {
  padding: 6px 14px;
  border-radius: 20px;
  border: 1px solid var(--elaw-border);
  background: #fff;
  font-size: 13px;
  color: var(--elaw-text);
  cursor: pointer;
  font-family: inherit;
  transition: all 0.12s;
}

.elaw-hero__chip:hover {
  border-color: var(--elaw-warm-gold);
  background: var(--elaw-cream);
}

.elaw-hero__chip--active {
  background: var(--elaw-navy);
  color: #fff;
  border-color: var(--elaw-navy);
}

@media (max-width: 640px) {
  .elaw-hero__heading {
    font-size: 28px;
  }

  .elaw-hero__search-wrap {
    flex-direction: column;
    align-items: stretch;
  }

  .elaw-hero__search-btn {
    width: 100%;
  }
}
</style>
