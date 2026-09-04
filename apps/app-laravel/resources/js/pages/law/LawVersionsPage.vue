<template>
  <div class="lvp">
    <ELawNavbar @go-admin="router.push('/admin')" />

    <div class="lvp-topbar">
      <v-container style="max-width:1200px" class="py-0">
        <div class="d-flex align-center ga-2 py-3">
          <v-btn variant="text" size="small" prepend-icon="mdi-arrow-left" class="text-none"
            @click="router.push(`/law/${props.documentId}`)">
            ย้อนกลับ
          </v-btn>
          <span class="text-body-2 text-medium-emphasis">เวอร์ชันและความสัมพันธ์</span>
        </div>
      </v-container>
    </div>

    <v-container style="max-width:1200px" class="py-6">
      <div v-if="versionStore.loading || documentStore.loading"
        class="d-flex justify-center align-center pa-16 ga-3 text-medium-emphasis">
        <v-progress-circular indeterminate />
        <span>กำลังโหลด...</span>
      </div>

      <template v-else>
        <div class="lvp-title mb-6">
          <h1 class="text-h6 font-weight-bold">{{ meta.title || documentStore.review?.source_file }}</h1>
          <div v-if="meta.law_type" class="text-caption text-medium-emphasis mt-1">{{ meta.law_type }}</div>
        </div>

        <div class="lvp-grid">
          <!-- VERSION TIMELINE -->
          <v-card class="lvp-timeline" elevation="0" border>
            <v-card-title class="text-body-2 font-weight-bold d-flex align-center ga-2">
              <v-icon icon="mdi-history" size="18" />
              ประวัติเวอร์ชัน
            </v-card-title>
            <v-card-text class="px-3 pb-4">
              <div v-if="!versionStore.versions.length" class="text-body-2 text-medium-emphasis">
                ไม่พบข้อมูลเวอร์ชัน
              </div>
              <div
                v-for="v in orderedVersions"
                :key="v.document_id"
                class="lvp-ver-card"
                :class="{ 'is-current': v.is_current, 'is-viewing': v.document_id === props.documentId }"
              >
                <div class="d-flex align-center justify-space-between ga-2 mb-1">
                  <span class="font-weight-bold text-body-2">{{ v.version_label }}</span>
                  <div class="d-flex ga-1">
                    <v-chip size="x-small" :color="v.is_current ? 'success' : 'default'" variant="tonal" rounded="pill">
                      {{ v.is_current ? (v.status || 'มีผลบังคับใช้') : 'ถูกแทนที่' }}
                    </v-chip>
                    <v-chip
                      v-if="v.document_id === props.documentId"
                      size="x-small"
                      color="admin-primary"
                      variant="flat"
                      rounded="pill"
                    >กำลังดู</v-chip>
                  </div>
                </div>

                <div v-if="v.title" class="text-body-2 mb-1 text-truncate">{{ v.title }}</div>
                <div class="text-caption text-medium-emphasis d-flex flex-column ga-1 mb-2">
                  <span v-if="v.promulgation_date">
                    <v-icon icon="mdi-calendar" size="11" /> ประกาศ {{ formatLawDate(v.promulgation_date) }}
                  </span>
                  <span v-if="v.issuer || v.agency">
                    <v-icon icon="mdi-office-building-outline" size="11" /> {{ v.issuer || v.agency }}
                  </span>
                  <span v-if="v.change_status" class="text-caption">{{ v.change_status }}</span>
                </div>

                <div class="d-flex ga-2 flex-wrap">
                  <v-btn
                    v-if="v.document_id !== props.documentId"
                    size="x-small"
                    variant="outlined"
                    prepend-icon="mdi-eye-outline"
                    class="text-none"
                    :to="`/law/${encodeURIComponent(v.document_id)}`"
                  >
                    ดูเอกสาร
                  </v-btn>
                  <v-btn
                    v-if="v.has_file"
                    size="x-small"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-download"
                    class="text-none"
                    :href="documentFileDownloadUrl(v.document_id)"
                    :download="downloadName(v)"
                  >
                    ดาวน์โหลด
                  </v-btn>
                </div>
              </div>
            </v-card-text>
          </v-card>

          <!-- RELATIONS PANEL -->
          <v-card class="lvp-relations" elevation="0" border>
            <v-card-title class="text-body-2 font-weight-bold d-flex align-center ga-2">
              <v-icon icon="mdi-sitemap-outline" size="18" />
              ความสัมพันธ์กฎหมาย
            </v-card-title>
            <v-card-text class="px-3 pb-4">
              <div v-if="!allRelations.length" class="text-body-2 text-medium-emphasis">
                ไม่มีความสัมพันธ์ที่บันทึกไว้
              </div>
              <template v-else>
                <div v-for="group in groupedRelations" :key="group.type" class="lvp-rel-group mb-4">
                  <div class="text-caption font-weight-bold text-medium-emphasis mb-2 text-uppercase">
                    {{ group.label }}
                  </div>
                  <div v-for="rel in group.items" :key="rel.id" class="lvp-rel-row">
                    <span class="mdi lvp-rel-icon" :class="RELATION_TYPE_ICONS[rel.type] ?? 'mdi-link-variant'" />
                    <div class="flex-1 min-width-0">
                      <div class="text-body-2 text-truncate">{{ rel.target_title }}</div>
                      <div v-if="rel.target_section" class="text-caption text-medium-emphasis">
                        {{ rel.target_section }}
                      </div>
                      <div v-if="rel.note" class="text-caption text-medium-emphasis">— {{ rel.note }}</div>
                    </div>
                    <div class="d-flex ga-1 flex-shrink-0">
                      <v-btn
                        v-if="rel.target_document_id"
                        size="x-small"
                        variant="text"
                        prepend-icon="mdi-eye-outline"
                        class="text-none"
                        :to="`/law/${encodeURIComponent(rel.target_document_id)}`"
                      >
                        ดู
                      </v-btn>
                      <v-btn
                        v-if="rel.target_document_id"
                        size="x-small"
                        variant="text"
                        prepend-icon="mdi-download"
                        class="text-none"
                        :href="relatedDocumentFileUrl(props.documentId, rel.target_document_id)"
                        :download="rel.target_title || rel.target_document_id"
                      >
                        โหลด
                      </v-btn>
                      <v-btn
                        v-else-if="safeUrl(rel.url)"
                        size="x-small"
                        variant="text"
                        prepend-icon="mdi-open-in-new"
                        class="text-none"
                        :href="safeUrl(rel.url)!"
                        target="_blank"
                        rel="noopener"
                      >
                        เปิด
                      </v-btn>
                    </div>
                  </div>
                </div>
              </template>
            </v-card-text>
          </v-card>
        </div>
      </template>
    </v-container>

    <ELawFooter />
  </div>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useVersionStore } from '../../stores/versionStore';
import { useDocumentStore } from '../../stores/documentStore';
import { documentFileDownloadUrl, relatedDocumentFileUrl } from '../../api/client';
import { RELATION_TYPE_ICONS } from '../../types/lawRelation';
import type { LawMeta, LawRelation, RelationType } from '../../types/document';
import type { VersionChainItem } from '../../types/versionChain';
import { formatThaiDate } from '../../utils/thaiDate';
import ELawNavbar from '../../components/shared/ELawNavbar.vue';
import ELawFooter from '../../components/shared/ELawFooter.vue';

const props = defineProps<{ documentId: string }>();
const router = useRouter();
const versionStore = useVersionStore();
const documentStore = useDocumentStore();

watch(() => props.documentId, (id) => {
  void versionStore.fetch(id);
  if (documentStore.documentId !== id || !documentStore.review) {
    void documentStore.fetch(id);
  }
}, { immediate: true });

const EMPTY_META: LawMeta = {
  status: '', law_type: '', law_group: '', change_status: null, law_groups: [],
  agency: '', signer_group: null, agencies: [], keywords: [],
  promulgation_date: '', effective_date: '', published_date: '', expiry_date: null,
  section_count: null, title: '', gazette_reference: '', royal_command: '',
  repealed_laws: [], imported_by: '', parent_document_id: null,
  parent_document_ids: [], access_scope: 'public', permission_group_ids: [],
};
const meta = computed<LawMeta>(() => documentStore.review?.law_meta ?? EMPTY_META);
const orderedVersions = computed(() => [...versionStore.versions].reverse());

const allRelations = computed<LawRelation[]>(() => documentStore.review?.relations ?? []);

const RELATION_GROUP_LABELS: Record<RelationType, string> = {
  repeals: 'กฎหมายที่ถูกยกเลิก',
  amends: 'กฎหมายที่แก้ไขเพิ่มเติม',
  supersedes: 'กฎหมายที่ถูกแทนที่',
  issued_under: 'ออกตามอำนาจของ',
  related: 'กฎหมายที่เกี่ยวข้อง',
};
const RELATION_GROUP_ORDER: RelationType[] = ['repeals', 'supersedes', 'amends', 'issued_under', 'related'];

const groupedRelations = computed(() =>
  RELATION_GROUP_ORDER
    .map((type) => ({
      type,
      label: RELATION_GROUP_LABELS[type],
      items: allRelations.value.filter((r) => r.type === type),
    }))
    .filter((g) => g.items.length > 0),
);

function formatLawDate(value: string): string {
  return formatThaiDate(value) || value || '';
}

function downloadName(v: VersionChainItem): string {
  const base = v.source_file || v.title || v.version_label || v.document_id;
  return base.trim() || 'document';
}

function safeUrl(url: string | null | undefined): string | null {
  if (!url) return null;
  const t = url.trim();
  return /^https?:\/\//i.test(t) ? t : null;
}
</script>

<style scoped>
.lvp { min-height: 100vh; background: #f8fafc; }
.lvp-topbar { background: #fff; border-bottom: 1px solid #e5e7eb; }
.lvp-grid {
  display: grid;
  grid-template-columns: 340px 1fr;
  gap: 20px;
  align-items: start;
}
@media (max-width: 700px) {
  .lvp-grid { grid-template-columns: 1fr; }
}
.lvp-ver-card {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 12px 14px;
  margin-bottom: 10px;
  background: #fff;
  transition: border-color 0.15s;
}
.lvp-ver-card.is-viewing {
  border-color: rgb(var(--v-theme-admin-primary));
  box-shadow: 0 0 0 1px rgb(var(--v-theme-admin-primary));
}
.lvp-ver-card.is-current { background: #f0fdf4; }
.lvp-rel-row {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid #f1f5f9;
}
.lvp-rel-row:last-child { border-bottom: none; }
.lvp-rel-icon { font-size: 16px; margin-top: 2px; flex-shrink: 0; }
</style>
