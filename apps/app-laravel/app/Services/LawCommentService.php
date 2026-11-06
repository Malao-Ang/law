<?php

namespace App\Services;

use App\Services\Auth\CurrentUser;
use App\Services\Auth\DocumentAccess;
use App\Services\Auth\MongoUserStore;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class LawCommentService
{
    /** @var array<string, string> */
    public const TYPES = [
        'question' => 'ข้อซักถาม',
        'suggestion' => 'ข้อเสนอแนะ',
        'incorrect' => 'แจ้งข้อมูลไม่ถูกต้อง',
        'other' => 'อื่น ๆ',
    ];

    /** @var array<string, string> */
    public const STATUSES = [
        'pending' => 'รอพิจารณา',
        'answered' => 'ตอบแล้ว',
    ];

    public const MAX_FILES = 5;

    public function __construct(
        private readonly LawCommentStore $comments,
        private readonly ReviewStore $documents,
        private readonly DocumentAccess $access,
        private readonly CurrentUser $currentUser,
    ) {}

    /**
     * @param  array{type?: string, status?: string, visibility?: string, q?: string, sort?: string, limit?: int}  $filters
     * @return array<string, mixed>
     */
    public function list(Request $request, string $documentId, array $filters): array
    {
        $review = $this->review($documentId);
        $this->access->authorizeRead($request, $this->accessDocument($documentId, $review));

        $viewer = $this->viewer($request);
        $visible = array_values(array_filter(
            $this->comments->forDocument($documentId),
            fn (array $comment): bool => $this->canSee($comment, $viewer, $this->isStaff($request)),
        ));

        $items = $visible;
        $type = $filters['type'] ?? '';
        if (isset(self::TYPES[$type])) {
            $items = array_values(array_filter($items, fn (array $comment): bool => ($comment['type'] ?? '') === $type));
        }
        $status = $filters['status'] ?? '';
        if (isset(self::STATUSES[$status])) {
            $items = array_values(array_filter($items, fn (array $comment): bool => ($comment['status'] ?? '') === $status));
        }
        $visibility = $filters['visibility'] ?? 'all';
        if ($visibility === 'public') {
            $items = array_values(array_filter($items, fn (array $comment): bool => ($comment['visibility'] ?? '') === 'public'));
        } elseif ($visibility === 'mine') {
            $items = array_values(array_filter(
                $items,
                fn (array $comment): bool => $viewer !== null && ($comment['author_username'] ?? '') === $viewer['username'] && ($comment['visibility'] ?? '') === 'private',
            ));
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $items = array_values(array_filter($items, function (array $comment) use ($needle): bool {
                $reply = is_array($comment['reply'] ?? null) ? (string) ($comment['reply']['body'] ?? '') : '';
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($comment['body'] ?? ''),
                    (string) ($comment['section_ref'] ?? ''),
                    (string) ($comment['author_name'] ?? ''),
                    $reply,
                ]));

                return str_contains($haystack, $needle);
            }));
        }

        $sort = ($filters['sort'] ?? 'newest') === 'oldest' ? 'oldest' : 'newest';
        usort($items, function (array $left, array $right) use ($sort): int {
            $compare = strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? ''));

            return $sort === 'oldest' ? $compare : -$compare;
        });

        $total = count($items);
        $limit = max(1, min((int) ($filters['limit'] ?? 200), 200));
        $items = array_slice($items, 0, $limit);

        return [
            'document' => [
                'id' => $documentId,
                'title' => trim((string) (($review['law_meta']['title'] ?? '') ?: '')),
            ],
            'items' => array_map(fn (array $comment): array => $this->present($comment, $viewer), $items),
            'total' => $total,
            'counts' => $this->counts($visible, $viewer),
            'can_reply' => $this->isStaff($request),
        ];
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function create(Request $request, string $documentId, array $input, array $files): array
    {
        $review = $this->review($documentId);
        $this->access->authorizeRead($request, $this->accessDocument($documentId, $review));

        $author = $this->viewer($request);
        abort_if($author === null, 401, 'กรุณาเข้าสู่ระบบก่อนแสดงความคิดเห็น');
        abort_if(count($files) > self::MAX_FILES, 422, 'แนบไฟล์ได้ไม่เกิน 5 ไฟล์');

        $id = 'cmt_'.bin2hex(random_bytes(8));
        $attachments = $this->storeFiles($id, $files);
        $now = now()->toIso8601String();
        $section = trim((string) ($input['section_ref'] ?? ''));
        $comment = [
            '_id' => $id,
            'document_id' => $documentId,
            'type' => (string) $input['type'],
            'body' => trim((string) $input['body']),
            'section_ref' => $section === '' ? null : $section,
            'visibility' => ($input['visibility'] ?? 'public') === 'private' ? 'private' : 'public',
            'status' => 'pending',
            'author_username' => (string) $author['username'],
            'author_name' => $this->displayName($author),
            'attachments' => $attachments,
            'reply' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            $this->comments->insert($comment);
        } catch (\Throwable $exception) {
            $this->deleteFiles($attachments);
            throw $exception;
        }

        return $this->present($comment, $author);
    }

    /** @return array<string, mixed> */
    public function reply(Request $request, string $documentId, string $commentId, string $body): array
    {
        $review = $this->review($documentId);
        $this->access->authorizeRead($request, $this->accessDocument($documentId, $review));
        abort_unless($this->isStaff($request), 403, 'เฉพาะเจ้าหน้าที่ที่ตอบกลับความคิดเห็นได้');

        $comment = $this->comments->find($commentId);
        abort_if($comment === null || ($comment['document_id'] ?? '') !== $documentId, 404, 'ไม่พบความคิดเห็น');
        abort_unless($this->canSee($comment, $this->viewer($request), true), 404, 'ไม่พบความคิดเห็น');

        $staff = $this->viewer($request);
        abort_if($staff === null, 401, 'กรุณาเข้าสู่ระบบ');

        $now = now()->toIso8601String();
        $comment['reply'] = [
            'body' => trim($body),
            'author_name' => $this->displayName($staff),
            'author_username' => (string) $staff['username'],
            'created_at' => $now,
        ];
        $comment['status'] = 'answered';
        $comment['updated_at'] = $now;
        $this->comments->update($commentId, [
            'reply' => $comment['reply'],
            'status' => 'answered',
            'updated_at' => $now,
        ]);

        return $this->present($comment, $staff);
    }

    /** @return array{comment: array<string, mixed>, file: array<string, mixed>} */
    public function attachment(Request $request, string $documentId, string $commentId, string $fileId): array
    {
        $review = $this->review($documentId);
        $this->access->authorizeRead($request, $this->accessDocument($documentId, $review));

        $comment = $this->comments->find($commentId);
        abort_if($comment === null || ($comment['document_id'] ?? '') !== $documentId, 404, 'ไม่พบไฟล์แนบ');
        abort_unless($this->canSee($comment, $this->viewer($request), $this->isStaff($request)), 404, 'ไม่พบไฟล์แนบ');

        foreach ((array) ($comment['attachments'] ?? []) as $file) {
            if (is_array($file) && ($file['id'] ?? '') === $fileId) {
                abort_unless(Storage::disk('local')->exists((string) ($file['path'] ?? '')), 404, 'ไม่พบไฟล์แนบ');

                return ['comment' => $comment, 'file' => $file];
            }
        }

        abort(404, 'ไม่พบไฟล์แนบ');
    }

    /** @return array<string, mixed> */
    private function review(string $documentId): array
    {
        try {
            return $this->documents->getReviewDocument($documentId);
        } catch (RuntimeException) {
            abort(404, 'ไม่พบเอกสาร');
        }
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    private function accessDocument(string $documentId, array $review): array
    {
        $meta = is_array($review['law_meta'] ?? null) ? $review['law_meta'] : [];

        return $meta + ['document_id' => $documentId];
    }

    /** @return array<string, mixed>|null */
    private function viewer(Request $request): ?array
    {
        return $this->currentUser->user($request);
    }

    private function isStaff(Request $request): bool
    {
        $user = $this->viewer($request);
        if ($user === null) {
            return false;
        }

        return $this->currentUser->role($user, $request) !== MongoUserStore::ROLE_USER;
    }

    /**
     * @param  array<string, mixed>  $comment
     * @param  array<string, mixed>|null  $viewer
     */
    private function canSee(array $comment, ?array $viewer, bool $staff): bool
    {
        if (($comment['visibility'] ?? 'public') !== 'private') {
            return true;
        }
        if ($staff) {
            return true;
        }

        return $viewer !== null && ($comment['author_username'] ?? '') === ($viewer['username'] ?? '');
    }

    /**
     * @param  list<array<string, mixed>>  $comments
     * @param  array<string, mixed>|null  $viewer
     * @return array<string, mixed>
     */
    private function counts(array $comments, ?array $viewer): array
    {
        $types = array_fill_keys(array_keys(self::TYPES), 0);
        $statuses = array_fill_keys(array_keys(self::STATUSES), 0);
        $public = 0;
        $mine = 0;
        foreach ($comments as $comment) {
            $type = (string) ($comment['type'] ?? '');
            if (isset($types[$type])) {
                $types[$type]++;
            }
            $status = (string) ($comment['status'] ?? '');
            if (isset($statuses[$status])) {
                $statuses[$status]++;
            }
            if (($comment['visibility'] ?? '') === 'public') {
                $public++;
            }
            if ($viewer !== null && ($comment['author_username'] ?? '') === $viewer['username'] && ($comment['visibility'] ?? '') === 'private') {
                $mine++;
            }
        }

        return [
            'all' => count($comments),
            'types' => $types,
            'statuses' => $statuses,
            'visibility' => ['public' => $public, 'mine' => $mine],
        ];
    }

    /**
     * @param  array<string, mixed>  $comment
     * @param  array<string, mixed>|null  $viewer
     * @return array<string, mixed>
     */
    private function present(array $comment, ?array $viewer): array
    {
        $type = (string) ($comment['type'] ?? 'other');
        $status = (string) ($comment['status'] ?? 'pending');
        $reply = is_array($comment['reply'] ?? null) ? $comment['reply'] : null;
        $attachments = [];
        foreach ((array) ($comment['attachments'] ?? []) as $file) {
            if (! is_array($file)) {
                continue;
            }
            $attachments[] = [
                'id' => (string) ($file['id'] ?? ''),
                'name' => (string) ($file['name'] ?? 'ไฟล์แนบ'),
                'size' => (int) ($file['size'] ?? 0),
                'mime' => (string) ($file['mime'] ?? 'application/octet-stream'),
            ];
        }

        return [
            'id' => (string) ($comment['_id'] ?? ''),
            'document_id' => (string) ($comment['document_id'] ?? ''),
            'type' => $type,
            'type_label' => self::TYPES[$type] ?? $type,
            'body' => (string) ($comment['body'] ?? ''),
            'section_ref' => $comment['section_ref'] ?? null,
            'visibility' => ($comment['visibility'] ?? 'public') === 'private' ? 'private' : 'public',
            'status' => $status,
            'status_label' => self::STATUSES[$status] ?? $status,
            'author_name' => (string) ($comment['author_name'] ?? 'ผู้ใช้'),
            'is_mine' => $viewer !== null && ($comment['author_username'] ?? '') === ($viewer['username'] ?? ''),
            'created_at' => (string) ($comment['created_at'] ?? ''),
            'attachments' => $attachments,
            'reply' => $reply === null ? null : [
                'body' => (string) ($reply['body'] ?? ''),
                'author_name' => (string) ($reply['author_name'] ?? 'เจ้าหน้าที่'),
                'created_at' => (string) ($reply['created_at'] ?? ''),
            ],
        ];
    }

    /** @param array<string, mixed> $author */
    private function displayName(array $author): string
    {
        $name = trim((string) ($author['name'] ?? ''));

        return $name !== '' ? $name : (string) ($author['username'] ?? 'ผู้ใช้');
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array<string, mixed>>
     */
    private function storeFiles(string $commentId, array $files): array
    {
        $stored = [];
        try {
            foreach ($files as $file) {
                $fileId = 'f_'.bin2hex(random_bytes(6));
                $path = $file->storeAs('law-comments/'.$commentId, $fileId, 'local');
                if (! is_string($path) || $path === '') {
                    throw new RuntimeException('บันทึกไฟล์แนบไม่สำเร็จ');
                }
                $stored[] = [
                    'id' => $fileId,
                    'name' => $this->safeName($file->getClientOriginalName()),
                    'mime' => (string) ($file->getClientMimeType() ?: 'application/octet-stream'),
                    'size' => (int) $file->getSize(),
                    'path' => $path,
                ];
            }
        } catch (\Throwable $exception) {
            $this->deleteFiles($stored);
            throw $exception;
        }

        return $stored;
    }

    /** @param list<array<string, mixed>> $attachments */
    private function deleteFiles(array $attachments): void
    {
        foreach ($attachments as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path !== '') {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function safeName(string $name): string
    {
        $base = trim(basename(str_replace('\\', '/', $name)));
        $base = preg_replace('/[^\p{L}\p{N}._\- ]/u', '', $base) ?? '';
        $base = trim($base);
        if ($base === '' || $base === '.' || $base === '..') {
            return 'attachment';
        }

        return mb_substr($base, 0, 180);
    }
}
