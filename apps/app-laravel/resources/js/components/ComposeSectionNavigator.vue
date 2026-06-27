<template>
  <section class="compose-navigator">
    <div class="compose-drawer-header">
      <div>
        <h2>สารบัญ</h2>
        <p>{{ filteredItems.length }} / {{ items.length }} บล็อก</p>
      </div>
      <v-chip size="small" variant="tonal" color="primary">{{ reviewCount }} รอตรวจ</v-chip>
    </div>

    <v-text-field
      v-model="query"
      class="compose-nav-search"
      density="compact"
      hide-details
      clearable
      prepend-inner-icon="mdi-magnify"
      placeholder="ค้นหามาตรา / เนื้อหา"
      variant="outlined"
    />

    <v-list
      class="compose-nav-vlist"
      density="compact"
      nav
      lines="two"
      :selected="selectedBlockId ? [selectedBlockId] : []"
      selected-class="compose-nav-vlist__item--active"
    >
      <v-list-item
        v-for="item in filteredItems"
        :key="item.blockId"
        :value="item.blockId"
        rounded="lg"
        @click="$emit('select', item.blockId)"
      >
        <template #prepend>
          <v-chip
            class="compose-nav-page-chip"
            size="x-small"
            variant="flat"
            :color="chipColor(item.status)"
          >
            {{ item.pageNo }}
          </v-chip>
        </template>

        <v-list-item-title class="compose-nav-title">{{ item.label }}</v-list-item-title>
        <v-list-item-subtitle>{{ item.subtitle }}</v-list-item-subtitle>

        <template #append>
          <v-chip size="x-small" variant="tonal" :color="chipColor(item.status)">
            {{ typeLabel(item.type) }}
          </v-chip>
        </template>
      </v-list-item>

      <v-list-item v-if="filteredItems.length === 0">
        <v-list-item-title>ไม่พบรายการที่ตรงกับคำค้น</v-list-item-title>
      </v-list-item>
    </v-list>
  </section>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import type { BlockType } from '../types/document';

interface NavigatorItem {
  blockId: string;
  label: string;
  subtitle: string;
  pageNo: number;
  type: BlockType;
  status: 'clean' | 'warning' | 'info';
}

const props = defineProps<{
  items: NavigatorItem[];
  selectedBlockId: string | null;
}>();

defineEmits<{
  select: [string];
}>();

const query = ref('');

const filteredItems = computed(() => {
  const needle = query.value.trim().toLowerCase();
  if (!needle) {
    return props.items;
  }

  return props.items.filter((item) =>
    `${item.label} ${item.subtitle} ${typeLabel(item.type)}`.toLowerCase().includes(needle),
  );
});

const reviewCount = computed(() => props.items.filter((item) => item.status === 'warning').length);

function chipColor(status: NavigatorItem['status']): string {
  if (status === 'warning') return 'warning';
  if (status === 'info') return 'primary';
  return 'success';
}

function typeLabel(type: BlockType): string {
  if (type === 'paragraph') return 'ย่อหน้า';
  if (type === 'list_item') return 'รายการ';
  if (type === 'section_header') return 'หัวข้อ';
  if (type === 'title') return 'หัวเอกสาร';
  if (type === 'table') return 'ตาราง';
  if (type === 'image') return 'รูปภาพ';
  if (type === 'figure_caption') return 'คำอธิบายภาพ';
  if (type === 'footnote') return 'เชิงอรรถ';
  return 'บล็อก';
}
</script>
