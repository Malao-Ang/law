<template>
  <article class="comment-card">
    <header class="comment-card__head">
      <v-avatar color="primary" variant="tonal" size="36">
        <v-icon icon="mdi-account" size="20" />
      </v-avatar>
      <div class="comment-card__who">
        <div class="comment-card__name">{{ comment.author_name }}</div>
        <div class="comment-card__date">{{ formatThaiDateShort(comment.created_at) }}</div>
      </div>
      <div class="comment-card__tags">
        <v-chip size="small" variant="tonal" :color="typeColor(comment.type)">{{ comment.type_label }}</v-chip>
        <v-chip size="small" variant="tonal" :color="comment.status === 'answered' ? 'success' : 'warning'">
          {{ comment.status_label }}
        </v-chip>
        <v-chip
          size="small"
          :variant="comment.visibility === 'public' ? 'tonal' : 'outlined'"
          :color="comment.visibility === 'public' ? 'success' : 'grey'"
        >
          <v-icon start :icon="comment.visibility === 'public' ? 'mdi-earth' : 'mdi-lock-outline'" size="14" />
          {{ comment.visibility === 'public' ? 'Public' : 'Private' }}
        </v-chip>
      </div>
    </header>

    <p v-if="comment.section_ref" class="comment-card__section">อ้างอิง {{ comment.section_ref }}</p>
    <p class="comment-card__body">{{ comment.body }}</p>

    <div v-if="comment.attachments.length" class="comment-card__files">
      <a
        v-for="file in comment.attachments"
        :key="file.id"
        class="comment-card__file"
        :href="lawCommentFileUrl(comment.document_id, comment.id, file.id)"
        target="_blank"
        rel="noopener"
      >
        <v-icon icon="mdi-paperclip" size="14" />
        {{ file.name }}
      </a>
    </div>

    <div v-if="comment.reply" class="comment-card__reply">
      <div class="comment-card__reply-label">
        <v-icon icon="mdi-shield-account-outline" size="16" />
        คำตอบจากเจ้าหน้าที่
      </div>
      <p>{{ comment.reply.body }}</p>
      <div class="comment-card__reply-meta">
        {{ comment.reply.author_name }} · ตอบเมื่อ {{ formatThaiDateShort(comment.reply.created_at) }}
      </div>
    </div>
  </article>
</template>

<script setup lang="ts">
import { lawCommentFileUrl } from '../../api/lawComments';
import type { LawComment, LawCommentType } from '../../types/lawComment';
import { formatThaiDateShort } from '../../utils/thaiDate';

defineProps<{
  comment: LawComment;
}>();

function typeColor(type: LawCommentType): string {
  if (type === 'question') return 'info';
  if (type === 'suggestion') return 'warning';
  if (type === 'incorrect') return 'error';
  return 'grey-darken-1';
}
</script>

<style scoped>
.comment-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 16px;
  padding: 18px 20px;
}

.comment-card__head {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
}

.comment-card__who {
  min-width: 0;
  flex: 1 1 120px;
}

.comment-card__name {
  font-size: 0.9rem;
  font-weight: 700;
  color: #1a2e52;
}

.comment-card__date,
.comment-card__section,
.comment-card__reply-meta {
  color: #64748b;
  font-size: 0.75rem;
}

.comment-card__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-left: auto;
}

.comment-card__body {
  margin: 12px 0 0;
  color: #1e293b;
  font-size: 0.9375rem;
  line-height: 1.65;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.comment-card__section {
  margin: 10px 0 0;
}

.comment-card__files {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}

.comment-card__file {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #1d4ed8;
  font-size: 0.8125rem;
  text-decoration: none;
}

.comment-card__reply {
  margin-top: 14px;
  padding: 14px 16px;
  border-radius: 12px;
  background: #eef5ff;
  color: #1e3a5f;
}

.comment-card__reply p {
  margin: 8px 0 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  line-height: 1.6;
  font-size: 0.875rem;
}

.comment-card__reply-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.8125rem;
  font-weight: 700;
}

.comment-card__reply-meta {
  margin-top: 8px;
}
</style>
