<template>
  <v-card
    flat
    border
    rounded="lg"
    hover
    class="document-version-card"
    :class="[
      `document-version-card--${version.metadata.documentType}`,
      `document-version-card--${variant}`,
    ]"
    @click="$emit('click')"
  >
    <v-card-text>
      <div class="document-version-card__layout">
        <div class="document-version-card__main">
          <div class="d-flex align-center flex-wrap ga-2 mb-2">
            <v-chip size="x-small" :color="documentTypeColor" rounded="pill">
              {{ documentTypeLabel }}
            </v-chip>
            <v-chip size="x-small" :color="scopeColor" variant="tonal" rounded="pill">
              {{ publicationScopeLabel }}
            </v-chip>
            <v-chip v-if="version.isCurrent" size="x-small" color="success" variant="tonal" rounded="pill">
              มีผลบังคับใช้
            </v-chip>
          </div>

          <p class="text-body-1 font-weight-bold mb-1 document-version-card__title">
            {{ version.metadata.title }}
          </p>
          <p class="text-caption text-medium-emphasis mb-2 document-version-card__summary">
            {{ version.metadata.summary || version.changeSummary || 'ไม่มีสรุปย่อสำหรับเอกสารฉบับนี้' }}
          </p>

          <div class="d-flex flex-wrap ga-4 text-caption text-medium-emphasis">
            <span class="document-version-card__meta-item"><v-icon size="11" icon="mdi-calendar-check" /> {{ publishedDateLabel }}</span>
            <span class="document-version-card__meta-item"><v-icon size="11" icon="mdi-domain" /> {{ ownerAgencyLabel }}</span>
            <span class="document-version-card__meta-item"><v-icon size="11" icon="mdi-tag-outline" /> {{ documentGroupLabel }}</span>
            <span class="document-version-card__meta-item"><v-icon size="11" icon="mdi-source-branch" /> เวอร์ชัน {{ version.versionNo }}</span>
          </div>
        </div>
      </div>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { DocumentType, DocumentVersion, PublicationScope } from '../../types/document-version';

const props = defineProps<{
  version: DocumentVersion;
  groupLabel?: string;
  ownerAgencyLabel?: string;
  variant?: 'default' | 'rectangle';
}>();

defineEmits<{ click: [] }>();

const documentTypeLabelMap: Record<DocumentType, string> = {
  phrb: 'พ.ร.บ.',
  rabiap: 'ระเบียบ',
  'kho-bangkhab': 'ข้อบังคับ',
  prakat: 'ประกาศ',
  other: 'อื่น ๆ',
};

const documentTypeColorMap: Record<DocumentType, string> = {
  phrb: 'doc-phrb',
  rabiap: 'doc-rabiap',
  'kho-bangkhab': 'doc-kho-bangkhab',
  prakat: 'doc-prakat',
  other: 'secondary',
};

const publicationScopeLabelMap: Record<PublicationScope, string> = {
  public: 'Public',
  private: 'Private',
  organization: 'Organization',
};

const scopeColorMap: Record<PublicationScope, string> = {
  public: 'success',
  private: 'secondary',
  organization: 'primary',
};

const documentTypeLabel = computed(() => documentTypeLabelMap[props.version.metadata.documentType] ?? props.version.metadata.documentType);
const documentTypeColor = computed(() => documentTypeColorMap[props.version.metadata.documentType] ?? 'secondary');
const publicationScopeLabel = computed(() => publicationScopeLabelMap[props.version.metadata.publicationScope] ?? props.version.metadata.publicationScope);
const scopeColor = computed(() => scopeColorMap[props.version.metadata.publicationScope] ?? 'secondary');
const variant = computed(() => props.variant ?? 'default');

const documentGroupLabel = computed(() => props.groupLabel || props.version.metadata.documentGroupId || 'ไม่ระบุกลุ่มกฎหมาย');
const ownerAgencyLabel = computed(() => props.ownerAgencyLabel || props.version.metadata.ownerAgencyId || 'ไม่ระบุหน่วยงาน');
const publishedDateLabel = computed(() => formatDate(
  props.version.metadata.publishedDate
  || props.version.metadata.effectiveDate
  || props.version.metadata.announcementDate
  || props.version.publishedAt
  || props.version.updatedAt,
));

function formatDate(value?: Date): string {
  if (!value) return 'ไม่ระบุวันที่';

  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return 'ไม่ระบุวันที่';

  return new Intl.DateTimeFormat('th-TH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  }).format(date);
}
</script>

<style scoped>
.document-version-card {
  border-left-width: 4px !important;
}

.document-version-card--rectangle {
  height: 100%;
  border-radius: 20px !important;
  box-shadow: 0 12px 30px rgba(26, 46, 82, 0.06);
}

.document-version-card--phrb {
  border-left-color: rgb(var(--v-theme-doc-phrb)) !important;
}

.document-version-card--rabiap {
  border-left-color: rgb(var(--v-theme-doc-rabiap)) !important;
}

.document-version-card--kho-bangkhab {
  border-left-color: rgb(var(--v-theme-doc-kho-bangkhab)) !important;
}

.document-version-card--prakat {
  border-left-color: rgb(var(--v-theme-doc-prakat)) !important;
}

.document-version-card__layout {
  display: flex;
  flex-direction: column;
  align-items: start;
  width: 100%;
}

.document-version-card__main {
  min-width: 0;
  width: 100%;
}

.document-version-card__title {
  line-height: 1.45;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  display: -webkit-box;
  overflow: hidden;
  text-overflow: ellipsis;
}

.document-version-card__summary {
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  display: -webkit-box;
  overflow: hidden;
  text-overflow: ellipsis;
}

.document-version-card__meta-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.document-version-card--rectangle .document-version-card__summary {
  -webkit-line-clamp: 3;
}
</style>
