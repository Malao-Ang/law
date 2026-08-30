<template>
  <div
    class="elaw-card"
    :class="`elaw-card--${docType}`"
    role="article"
    tabindex="0"
    @click="$emit('click')"
    @keydown.enter="$emit('click')"
  >
    <!-- Tags row -->
    <div class="elaw-card__tags">
      <div class="d-flex align-center ga-1">
        <DocBadge v-if="typeBadge" :type="typeBadge" />
        <span v-else class="elaw-tag elaw-tag--fallback">{{ typeLabel }}</span>
        <DocBadge v-if="statusBadge" :type="statusBadge" />
      </div>
      <!-- Visibility pill -->
      <div v-if="visibility" class="elaw-visibility" :class="`elaw-visibility--${visibility}`">
        <v-icon :icon="visibilityIcon" size="12" />
        {{ visibilityLabel }}
      </div>
    </div>

    <!-- Title -->
    <p class="elaw-card__title">{{ title }}</p>

    <!-- Description -->
    <p v-if="description" class="elaw-card__desc">{{ description }}</p>

    <!-- Issuer (ประกาศ: ออกโดย…) -->
    <div v-if="issuer" class="elaw-card__issuer">
      <v-icon icon="mdi-account-tie-outline" size="13" color="#6b7280" />
      ออกโดย{{ issuer }}
    </div>

    <!-- Agency strip (LawInfoStrip style) -->
    <div v-if="department" class="elaw-agency-strip">
      <div class="elaw-agency-strip__label">หน่วยงานที่รับผิดชอบ</div>
      <div class="elaw-agency-strip__value">{{ department }}</div>
    </div>

    <div class="elaw-card__divider" />

    <!-- Group chip -->
    <div v-if="lawGroup" class="elaw-card__group">
      <v-icon icon="mdi-folder-outline" size="13" color="#6b7280" />
      <span>{{ lawGroup }}</span>
    </div>

    <div v-if="visibility === 'private'" class="elaw-card__private-panel">
      <div>
        <div class="elaw-card__private-label">สิทธิ์การเข้าถึง</div>
        <div class="elaw-card__private-title">
          <v-icon icon="mdi-lock-outline" size="16" />
          Private
        </div>
        <div class="elaw-card__private-note">เฉพาะผู้ได้รับสิทธิ์</div>
      </div>
    </div>

    <!-- Footer: date + link -->
    <div class="elaw-card__footer">
      <div v-if="date" class="elaw-card__date">
        <v-icon icon="mdi-calendar-outline" size="13" color="#6b7280" />
        เผยแพร่เมื่อ {{ date }}
      </div>
      <button type="button" class="elaw-card__read-btn">
        {{ visibility === 'private' ? 'เข้าสู่ระบบ' : 'เปิดอ่าน' }}
        <v-icon icon="mdi-arrow-right" size="14" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import DocBadge from './DocBadge.vue';
import { changeStatusToBadge, docTypeToBadge, type ChangeStatus, type DocType } from './lawBadge';

type Visibility = 'public' | 'private' | 'organization';

const props = defineProps<{
  title: string;
  docType: DocType;
  description?: string;
  issuer?: string;
  department?: string;
  lawGroup?: string;
  date?: string;
  visibility?: Visibility;
  changeStatus?: ChangeStatus;
  amendedSections?: string[];
}>();

defineEmits<{ click: [] }>();

const typeLabels: Record<DocType, string> = {
  rabiap: 'ระเบียบ',
  'kho-bangkhab': 'ข้อบังคับ',
  prakat: 'ประกาศ',
  'kotmai-phaainok': 'กฎหมายภายนอก',
  other: 'อื่น ๆ',
};

const typeLabel = computed(() => typeLabels[props.docType] ?? 'เอกสาร');
const typeBadge = computed(() => docTypeToBadge(props.docType));
const statusBadge = computed(() => (props.changeStatus ? changeStatusToBadge(props.changeStatus) : null));

const visibilityLabel = computed(() =>
  ({ public: 'Public', private: 'Private', organization: 'Org' } as Record<Visibility, string>)[props.visibility!] ?? '',
);
const visibilityIcon = computed(() =>
  ({ public: 'mdi-earth', private: 'mdi-lock-outline', organization: 'mdi-domain' } as Record<Visibility, string>)[props.visibility!] ?? 'mdi-eye-outline',
);
</script>

<style scoped>
.elaw-card {
  background: #ffffff;
  border-radius: 16px;
  border-left: 4px solid #9e9e9e;
  box-shadow: 0 1px 1px rgba(0, 0, 0, 0.05);
  padding: 24px 24px 24px 28px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 0;
  transition: box-shadow 0.15s ease;
  height: 100%;
}

.elaw-card:hover {
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.10);
}

.elaw-card--rabiap       { border-left-color: #3b82f6; }
.elaw-card--kho-bangkhab { border-left-color: #10b981; }
.elaw-card--prakat       { border-left-color: #fb923c; }
.elaw-card--kotmai-phaainok { border-left-color: #854d0e; }
.elaw-card--other        { border-left-color: #9e9e9e; }

/* Tags row */
.elaw-card__tags {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
  flex-wrap: wrap;
  gap: 6px;
}

.elaw-tag--fallback {
  font-family: 'Sarabun', 'Noto Sans Thai', sans-serif;
  font-size: 14px;
  font-weight: 700;
  color: #6b7280;
  background: #f3f4f6;
  border-radius: 9999px;
  padding: 2px 10px;
}

/* Visibility badge */
.elaw-visibility {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 11px;
  border-radius: 9999px;
  border: 1px solid #bbf7d0;
  background: #f0fdf4;
  color: #15803d;
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
}

.elaw-visibility--private {
  border-color: #fecdd3;
  background: #fff1f2;
  color: #be123c;
}

.elaw-visibility--organization {
  border-color: #bfdbfe;
  background: #eff6ff;
  color: #1d4ed8;
}

/* Title */
.elaw-card__title {
  font-size: 18px;
  font-weight: 700;
  color: #1e293b;
  line-height: 28px;
  margin: 0 0 12px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Description */
.elaw-card__desc {
  font-size: 14px;
  font-weight: 400;
  color: #4b5563;
  line-height: 20px;
  margin: 0 0 16px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.elaw-card__issuer {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #475569;
  margin-bottom: 10px;
}

/* Agency strip (matches LawInfoStrip agency tone) */
.elaw-agency-strip {
  background: #ecfdf5;
  border: 1px solid #10b981;
  border-left-width: 4px;
  border-radius: 0 12px 12px 0;
  padding: 10px 15px 10px 18px;
  margin-bottom: 16px;
}

.elaw-agency-strip__label {
  font-size: 11px;
  font-weight: 700;
  color: #059669;
  text-transform: uppercase;
  letter-spacing: 0.55px;
  line-height: 16.5px;
}

.elaw-agency-strip__value {
  font-size: 12px;
  font-weight: 700;
  color: #1e293b;
  line-height: 16px;
  margin-top: 2px;
}

.elaw-card__divider {
  border-top: 1px solid #f3f4f6;
  margin-bottom: 12px;
}

/* Group chip */
.elaw-card__group {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #f9fafb;
  border-radius: 8px;
  padding: 6px 8px;
  font-size: 12px;
  font-weight: 400;
  color: #6b7280;
  margin-bottom: 12px;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.elaw-card__private-panel {
  border-top: 1px solid #e9dfcf;
  margin: 0 0 14px;
  padding-top: 14px;
}

.elaw-card__private-label {
  color: #64748b;
  font-size: 12px;
  line-height: 1.2;
}

.elaw-card__private-title {
  align-items: center;
  color: #0f172a;
  display: inline-flex;
  font-size: 15px;
  font-weight: 800;
  gap: 5px;
  margin-top: 2px;
}

.elaw-card__private-note {
  color: #b45309;
  font-size: 12px;
  font-weight: 700;
  margin-top: 2px;
}

/* Footer */
.elaw-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: auto;
}

.elaw-card__date {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 400;
  color: #6b7280;
}

.elaw-card__read-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 14px;
  font-weight: 500;
  color: #3b82f6;
  padding: 0;
  white-space: nowrap;
}

.elaw-card:has(.elaw-card__private-panel) .elaw-card__read-btn {
  background: #b7790b;
  border-radius: 8px;
  color: #fff;
  padding: 9px 13px;
}
</style>
