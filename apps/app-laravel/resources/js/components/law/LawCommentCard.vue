<template>
  <article class="comment-card" :class="{ 'is-compact': compact }">
    <header class="comment-card__head">
      <v-avatar color="primary" variant="tonal" :size="compact ? 28 : 36">
        <v-icon icon="mdi-account" :size="compact ? 16 : 20" />
      </v-avatar>
      <div class="comment-card__who">
        <div class="comment-card__name">{{ comment.author_name }}</div>
        <div class="comment-card__date">{{ formatThaiDateShort(comment.created_at) }}</div>
      </div>
      <v-chip size="x-small" variant="tonal" :color="typeColor(comment.type)">{{ comment.type_label }}</v-chip>
      <v-chip size="x-small" variant="flat" :color="comment.status === 'answered' ? 'success' : 'warning'">
        {{ comment.status === 'pending' && compact ? 'รอตอบ' : comment.status_label }}
      </v-chip>
      <v-chip size="x-small" variant="text" :color="comment.visibility === 'public' ? 'success' : 'grey-darken-1'">
        <v-icon start :icon="comment.visibility === 'public' ? 'mdi-earth' : 'mdi-lock-outline'" size="12" />
        {{ comment.visibility === 'public' ? 'Public' : 'Private' }}
      </v-chip>
    </header>

    <p v-if="comment.section_ref && !compact" class="comment-card__section">อ้างอิง {{ comment.section_ref }}</p>
    <p class="comment-card__body">{{ comment.body }}</p>

    <div v-if="comment.attachments.length && !compact" class="comment-card__files">
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

    <div v-if="comment.reply && !compact" class="comment-card__reply">
      <div class="comment-card__reply-label">
        <v-icon icon="mdi-shield-account-outline" size="16" />
        คำตอบจากเจ้าหน้าที่
      </div>
      <p>{{ comment.reply.body }}</p>
      <div class="comment-card__reply-meta">{{ comment.reply.author_name }} · ตอบเมื่อ {{ formatThaiDateShort(comment.reply.created_at) }}</div>
    </div>

    <form v-if="canReply && !compact && comment.status === 'pending'" class="comment-card__reply-form" @submit.prevent="submitReply">
      <v-textarea
        v-model="replyBody"
        label="คำตอบจากเจ้าหน้าที่"
        variant="outlined"
        density="comfortable"
        rows="2"
        auto-grow
        hide-details="auto"
        :disabled="replying"
      />
      <div class="d-flex justify-end mt-2">
        <v-btn type="submit" color="primary" size="small" :loading="replying" :disabled="!replyBody.trim()">ตอบกลับ</v-btn>
      </div>
      <p v-if="replyError" class="text-error text-caption mt-1">{{ replyError }}</p>
    </form>
  </article>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { lawCommentFileUrl, replyLawComment } from '../../api/lawComments';
import type { LawComment, LawCommentType } from '../../types/lawComment';
import { formatThaiDateShort } from '../../utils/thaiDate';

const props = defineProps<{
  comment: LawComment;
  compact?: boolean;
  canReply?: boolean;
}>();

const emit = defineEmits<{ replied: [] }>();

const replyBody = ref('');
const replying = ref(false);
const replyError = ref('');

function typeColor(type: LawCommentType): string {
  if (type === 'question') return 'info';
  if (type === 'suggestion') return 'warning';
  if (type === 'incorrect') return 'error';
  return 'grey-darken-1';
}

async function submitReply(): Promise<void> {
  const body = replyBody.value.trim();
  if (!body || replying.value) return;
  replying.value = true;
  replyError.value = '';
  try {
    await replyLawComment(props.comment.document_id, props.comment.id, body);
    replyBody.value = '';
    emit('replied');
  } catch (error) {
    replyError.value = error instanceof Error ? error.message : 'ตอบกลับไม่สำเร็จ';
  } finally {
    replying.value = false;
  }
}
</script>

<style scoped>
.comment-card {
  background: #fff;
  border: 1px solid rgba(210, 197, 179, 0.7);
  border-radius: 16px;
  padding: 16px;
}

.comment-card.is-compact {
  padding: 10px 12px;
  border-radius: 12px;
}

.comment-card__head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.comment-card__who {
  min-width: 0;
  flex: 1 1 80px;
}

.comment-card__name {
  font-size: 13px;
  font-weight: 700;
  color: #1a2e52;
}

.comment-card__date,
.comment-card__section,
.comment-card__reply-meta {
  color: #64748b;
  font-size: 12px;
}

.comment-card__body {
  margin: 10px 0 0;
  color: #1e293b;
  font-size: 14px;
  line-height: 1.6;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.is-compact .comment-card__body {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  margin-top: 8px;
  font-size: 13px;
}

.comment-card__section { margin: 8px 0 0; }

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
  font-size: 13px;
  text-decoration: none;
}

.comment-card__reply {
  margin-top: 12px;
  padding: 12px;
  border-radius: 12px;
  background: #eef5ff;
  color: #1e3a5f;
}

.comment-card__reply p { margin: 6px 0 0; white-space: pre-wrap; }

.comment-card__reply-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 700;
}

.comment-card__reply-form { margin-top: 12px; }
</style>
