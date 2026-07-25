<template>
  <v-card flat border rounded="lg" hover class="elaw-card" :class="`elaw-card--${docType}`" @click="$emit('click')">
    <v-card-text class="pt-3 pb-3">
      <!-- Tags row -->
      <div class="d-flex align-center ga-2 mb-3">
        <DocBadge v-if="typeBadge" :type="typeBadge" />
        <v-chip v-else size="x-small" :color="typeColor" rounded="pill">{{ typeLabel }}</v-chip>
        <DocBadge v-if="statusBadge" :type="statusBadge" />
        <v-spacer />
        <v-chip v-if="visibility" size="x-small" :color="visibilityColor" variant="outlined" rounded="pill">
          <v-icon start :icon="visibilityIcon" size="11" />
          {{ visibilityLabel }}
        </v-chip>
      </div>

      <!-- Title -->
      <p class="text-body-2 font-weight-bold mb-2 elaw-clamp-2">{{ title }}</p>

      <!-- Description -->
      <p v-if="description" class="text-caption text-medium-emphasis mb-3 elaw-clamp-2">{{ description }}</p>

      <!-- Amended sections -->
      <template v-if="changeStatus === 'amended' && amendedSections?.length">
        <div class="d-flex align-center ga-1 mb-1">
          <v-icon size="12" color="warning" icon="mdi-pencil-circle-outline" />
          <span class="text-caption">พบการแก้ไขเพิ่มเติม {{ amendedSections.length }} รายการ</span>
          <v-spacer />
          <v-btn size="x-small" variant="text" color="primary" append-icon="mdi-arrow-right">ดู Diff</v-btn>
        </div>
        <div class="d-flex flex-wrap ga-1 mb-2">
          <v-chip v-for="sec in amendedSections" :key="sec" size="x-small" color="warning" variant="tonal">{{ sec }}</v-chip>
        </div>
      </template>

      <!-- Department -->
      <div v-if="department" class="d-flex align-center ga-1 mb-1 text-caption text-medium-emphasis">
        <v-icon size="13" icon="mdi-domain" />
        <span class="elaw-clamp-1">{{ department }}</span>
      </div>

      <!-- Law group -->
      <div v-if="lawGroup" class="d-flex align-center ga-1 mb-3 text-caption text-medium-emphasis">
        <v-icon size="13" icon="mdi-tag-outline" />
        <span>{{ lawGroup }}</span>
      </div>

      <!-- Footer: date + action -->
      <div class="d-flex align-center">
        <div v-if="date" class="d-flex align-center ga-1 text-caption text-medium-emphasis">
          <v-icon size="13" icon="mdi-calendar-outline" />
          {{ date }}
        </div>
        <v-spacer />
        <v-btn
          size="x-small"
          :color="visibility === 'private' ? 'secondary' : 'primary'"
          variant="text"
          append-icon="mdi-arrow-right"
        >
          {{ visibility === 'private' ? 'เข้าสู่ระบบ' : 'เปิดอ่าน' }}
        </v-btn>
      </div>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import DocBadge from './DocBadge.vue';
import { changeStatusToBadge, docTypeToBadge, type ChangeStatus, type DocType } from './lawBadge';

type Visibility = 'public' | 'private' | 'organization';

const props = defineProps<{
  title: string;
  docType: DocType;
  docNumber?: string;
  description?: string;
  department?: string;
  lawGroup?: string;
  date?: string;
  status?: string;
  visibility?: Visibility;
  changeStatus?: ChangeStatus;
  amendedSections?: string[];
}>();

defineEmits<{ click: [] }>();

const typeLabels: Record<DocType, string> = {
  rabiap: 'ระเบียบ',
  'kho-bangkhab': 'ข้อบังคับ',
  prakat: 'ประกาศ',
  'kotmai-krung': 'กฎหมายหลัก',
  other: 'อื่น ๆ',
};

const typeColors: Record<DocType, string> = {
  rabiap: 'success',
  'kho-bangkhab': 'info',
  prakat: 'warning',
  'kotmai-krung': 'deep-purple',
  other: 'grey',
};

const typeLabel = computed(() => typeLabels[props.docType] ?? 'เอกสาร');
const typeColor = computed(() => typeColors[props.docType] ?? 'grey');
const typeBadge = computed(() => docTypeToBadge(props.docType));
const statusBadge = computed(() => (props.changeStatus ? changeStatusToBadge(props.changeStatus) : null));

const visibilityLabel = computed(() =>
  ({ public: 'สาธารณะ', private: 'ส่วนบุคคล', organization: 'องค์กร' } as Record<Visibility, string>)[props.visibility!] ?? '',
);
const visibilityColor = computed(() =>
  ({ public: 'success', private: 'error', organization: 'info' } as Record<Visibility, string>)[props.visibility!] ?? 'grey',
);
const visibilityIcon = computed(() =>
  ({
    public: 'mdi-lock-open-outline',
    private: 'mdi-lock-outline',
    organization: 'mdi-domain',
  } as Record<Visibility, string>)[props.visibility!] ?? 'mdi-eye-outline',
);
</script>

<style scoped>
.elaw-card {
  border-left-width: 4px !important;
}
.elaw-card--rabiap       { border-left-color: #3b82f6 !important; }
.elaw-card--kho-bangkhab { border-left-color: #10b981 !important; }
.elaw-card--prakat       { border-left-color: #fb923c !important; }
.elaw-card--kotmai-krung { border-left-color: #854d0e !important; }
.elaw-card--other        { border-left-color: #9e9e9e !important; }

.elaw-clamp-2 {
  -webkit-line-clamp: 2;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.elaw-clamp-1 {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
