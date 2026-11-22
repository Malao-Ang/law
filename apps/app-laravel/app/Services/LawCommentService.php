<?php

namespace App\Services;

use App\Services\Auth\CurrentUser;
use App\Services\Auth\DocumentAccess;
use App\Services\Auth\MongoUserStore;
use App\Services\MasterData\UnitStore;
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
        'archived' => 'จัดเก็บเข้าคลัง',
    ];

    public const MAX_FILES = 5;

    public function __construct(
        private readonly LawCommentStore $comments,
        private readonly ReviewStore $documents,
        private readonly DocumentAccess $access,
        private readonly CurrentUser $currentUser,
        private readonly UnitStore $units,
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
        $staff = $this->isStaff($request);
        $visible = array_values(array_filter(
            $this->comments->forDocument($documentId),
            function (array $comment) use ($viewer, $staff): bool {
                if (! $staff && ($comment['status'] ?? '') === 'archived') {
                    return false;
                }

                return $this->canSee($comment, $viewer, $staff);
            },
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
                    (string) ($comment['code'] ?? ''),
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
            'can_reply' => $staff,
        ];
    }

    /**
     * @param  array{status?: string, type?: string, unit?: string, date?: string, q?: string, page?: int, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function adminList(Request $request, array $filters): array
    {
        $this->assertStaff($request);
        $viewer = $this->viewer($request);
        $raw = $this->comments->all();
        $documentIds = array_values(array_unique(array_filter(array_map(
            static fn (array $comment): string => (string) ($comment['document_id'] ?? ''),
            $raw,
        ))));
        $docs = $this->documentIndex($documentIds);
        $allowedUnits = $this->adminUnitScope($request);

        $items = array_values(array_filter(
            $raw,
            function (array $comment) use ($docs, $allowedUnits): bool {
                $documentId = (string) ($comment['document_id'] ?? '');
                $doc = $docs[$documentId] ?? null;
                if ($allowedUnits === null) {
                    return true;
                }
                if ($doc === null) {
                    return false;
                }
                $unit = (string) ($doc['owner_unit_code'] ?? '');

                return $unit !== '' && in_array($unit, $allowedUnits, true);
            },
        ));

        $counts = [
            'all' => count($items),
            'pending' => 0,
            'answered' => 0,
            'archived' => 0,
        ];
        foreach ($items as $comment) {
            $status = (string) ($comment['status'] ?? 'pending');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        $status = $filters['status'] ?? '';
        if ($status !== '' && $status !== 'all' && isset(self::STATUSES[$status])) {
            $items = array_values(array_filter($items, fn (array $comment): bool => ($comment['status'] ?? '') === $status));
        }

        $type = $filters['type'] ?? '';
        if (isset(self::TYPES[$type])) {
            $items = array_values(array_filter($items, fn (array $comment): bool => ($comment['type'] ?? '') === $type));
        }

        $unit = trim((string) ($filters['unit'] ?? ''));
        if ($unit !== '') {
            $items = array_values(array_filter($items, function (array $comment) use ($docs, $unit): bool {
                $doc = $docs[(string) ($comment['document_id'] ?? '')] ?? null;

                return $doc !== null && (string) ($doc['owner_unit_code'] ?? '') === $unit;
            }));
        }

        $date = trim((string) ($filters['date'] ?? ''));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $items = array_values(array_filter($items, function (array $comment) use ($date): bool {
                $created = (string) ($comment['created_at'] ?? '');

                return str_starts_with($created, $date);
            }));
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $items = array_values(array_filter($items, function (array $comment) use ($needle, $docs): bool {
                $doc = $docs[(string) ($comment['document_id'] ?? '')] ?? null;
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($comment['code'] ?? ''),
                    (string) ($comment['_id'] ?? ''),
                    (string) ($comment['body'] ?? ''),
                    (string) ($comment['author_name'] ?? ''),
                    (string) ($comment['section_ref'] ?? ''),
                    (string) ($doc['title'] ?? ''),
                ]));

                return str_contains($haystack, $needle);
            }));
        }

        usort($items, fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $total = count($items);
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
        }
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        $units = [];
        foreach ($docs as $doc) {
            $code = (string) ($doc['owner_unit_code'] ?? '');
            if ($code === '' || isset($units[$code])) {
                continue;
            }
            if ($allowedUnits !== null && ! in_array($code, $allowedUnits, true)) {
                continue;
            }
            $units[$code] = [
                'code' => $code,
                'name' => (string) ($doc['unit_name'] ?? $code),
            ];
        }
        usort($units, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'items' => array_map(fn (array $comment): array => $this->presentAdmin($comment, $viewer, $docs), $slice),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'counts' => $counts,
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
            'units' => array_values($units),
        ];
    }

    /** @return array<string, mixed> */
    public function adminShow(Request $request, string $commentId): array
    {
        $this->assertStaff($request);
        $comment = $this->findForAdmin($request, $commentId);
        $viewer = $this->viewer($request);
        $docs = $this->documentIndex([(string) ($comment['document_id'] ?? '')]);

        return $this->presentAdmin($comment, $viewer, $docs, true);
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
            'code' => $this->comments->nextCode(),
            'document_id' => $documentId,
            'type' => (string) $input['type'],
            'body' => trim((string) $input['body']),
            'section_ref' => $section === '' ? null : $section,
            'visibility' => ($input['visibility'] ?? 'private') === 'public' ? 'public' : 'private',
            'reveal_name' => true,
            'notify' => true,
            'status' => 'pending',
            'author_username' => (string) $author['username'],
            'author_name' => $this->displayName($author),
            'attachments' => $attachments,
            'reply' => null,
            'reply_draft' => null,
            'replies' => [],
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

        return $this->saveReply($request, $comment, [
            'body' => $body,
            'visibility' => (string) ($comment['visibility'] ?? 'public'),
            'forward_for_review' => false,
            'as_draft' => false,
        ], []);
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  array{body: string, visibility?: string, forward_for_review?: bool, as_draft?: bool}  $input
     * @return array<string, mixed>
     */
    public function adminReply(Request $request, string $commentId, array $input, array $files): array
    {
        $this->assertStaff($request);
        $comment = $this->findForAdmin($request, $commentId);

        return $this->saveReply($request, $comment, $input, $files);
    }

    /**
     * @param  array{visibility: string, reason: string}  $input
     * @return array<string, mixed>
     */
    public function updateVisibility(Request $request, string $commentId, array $input): array
    {
        $this->assertStaff($request);
        $comment = $this->findForAdmin($request, $commentId);
        $visibility = $input['visibility'] === 'private' ? 'private' : 'public';
        $reason = trim((string) $input['reason']);
        abort_if($reason === '', 422, 'กรุณาระบุเหตุผล');

        $now = now()->toIso8601String();
        $history = is_array($comment['visibility_history'] ?? null) ? $comment['visibility_history'] : [];
        $staff = $this->viewer($request);
        $history[] = [
            'from' => ($comment['visibility'] ?? 'public') === 'private' ? 'private' : 'public',
            'to' => $visibility,
            'reason' => $reason,
            'author_name' => $staff ? $this->displayName($staff) : 'เจ้าหน้าที่',
            'author_username' => (string) ($staff['username'] ?? ''),
            'created_at' => $now,
        ];

        $this->comments->update($commentId, [
            'visibility' => $visibility,
            'visibility_history' => $history,
            'updated_at' => $now,
        ]);
        $comment['visibility'] = $visibility;
        $comment['visibility_history'] = $history;
        $comment['updated_at'] = $now;

        $docs = $this->documentIndex([(string) ($comment['document_id'] ?? '')]);

        return $this->presentAdmin($comment, $staff, $docs, true);
    }

    /**
     * @param  array{reason: string}  $input
     * @return array<string, mixed>
     */
    public function archive(Request $request, string $commentId, array $input): array
    {
        $this->assertStaff($request);
        $comment = $this->findForAdmin($request, $commentId);
        $reason = trim((string) $input['reason']);
        abort_if($reason === '', 422, 'กรุณาระบุเหตุผล');

        $now = now()->toIso8601String();
        $staff = $this->viewer($request);
        $this->comments->update($commentId, [
            'status' => 'archived',
            'archive_reason' => $reason,
            'archived_at' => $now,
            'archived_by' => $staff ? $this->displayName($staff) : 'เจ้าหน้าที่',
            'updated_at' => $now,
        ]);
        $comment['status'] = 'archived';
        $comment['archive_reason'] = $reason;
        $comment['archived_at'] = $now;
        $comment['updated_at'] = $now;

        $docs = $this->documentIndex([(string) ($comment['document_id'] ?? '')]);

        return $this->presentAdmin($comment, $staff, $docs, true);
    }

    /** @return array{comment: array<string, mixed>, file: array<string, mixed>} */
    public function attachment(Request $request, string $documentId, string $commentId, string $fileId): array
    {
        $review = $this->review($documentId);
        $this->access->authorizeRead($request, $this->accessDocument($documentId, $review));

        $comment = $this->comments->find($commentId);
        abort_if($comment === null || ($comment['document_id'] ?? '') !== $documentId, 404, 'ไม่พบไฟล์แนบ');
        abort_unless($this->canSee($comment, $this->viewer($request), $this->isStaff($request)), 404, 'ไม่พบไฟล์แนบ');

        foreach ($this->allAttachments($comment) as $file) {
            if (($file['id'] ?? '') === $fileId) {
                abort_unless(Storage::disk('local')->exists((string) ($file['path'] ?? '')), 404, 'ไม่พบไฟล์แนบ');

                return ['comment' => $comment, 'file' => $file];
            }
        }

        abort(404, 'ไม่พบไฟล์แนบ');
    }

    /** @return array{comment: array<string, mixed>, file: array<string, mixed>} */
    public function adminAttachment(Request $request, string $commentId, string $fileId): array
    {
        $this->assertStaff($request);
        $comment = $this->findForAdmin($request, $commentId);

        foreach ($this->allAttachments($comment) as $file) {
            if (($file['id'] ?? '') === $fileId) {
                abort_unless(Storage::disk('local')->exists((string) ($file['path'] ?? '')), 404, 'ไม่พบไฟล์แนบ');

                return ['comment' => $comment, 'file' => $file];
            }
        }

        abort(404, 'ไม่พบไฟล์แนบ');
    }

    /**
     * @param  array{body: string, visibility?: string, forward_for_review?: bool, as_draft?: bool}  $input
     * @param  list<UploadedFile>  $files
     * @param  array<string, mixed>  $comment
     * @return array<string, mixed>
     */
    private function saveReply(Request $request, array $comment, array $input, array $files): array
    {
        $staff = $this->viewer($request);
        abort_if($staff === null, 401, 'กรุณาเข้าสู่ระบบ');
        abort_if(($comment['status'] ?? '') === 'archived', 422, 'รายการนี้ถูกจัดเก็บเข้าคลังแล้ว');
        abort_if(count($files) > self::MAX_FILES, 422, 'แนบไฟล์ได้ไม่เกิน 5 ไฟล์');

        $body = trim((string) ($input['body'] ?? ''));
        abort_if($body === '', 422, 'กรุณาระบุคำตอบจากเจ้าหน้าที่');

        $commentId = (string) ($comment['_id'] ?? '');
        $attachments = $this->storeFiles($commentId.'/replies', $files);
        $now = now()->toIso8601String();
        $asDraft = (bool) ($input['as_draft'] ?? false);
        $forward = (bool) ($input['forward_for_review'] ?? false);
        $visibility = (($input['visibility'] ?? $comment['visibility'] ?? 'public') === 'private') ? 'private' : 'public';

        if ($asDraft) {
            $draft = [
                'body' => $body,
                'attachments' => $attachments,
                'visibility' => $visibility,
                'forward_for_review' => $forward,
                'author_name' => $this->displayName($staff),
                'author_username' => (string) $staff['username'],
                'updated_at' => $now,
            ];
            $this->comments->update($commentId, [
                'reply_draft' => $draft,
                'updated_at' => $now,
            ]);
            $comment['reply_draft'] = $draft;
            $comment['updated_at'] = $now;
            $docs = $this->documentIndex([(string) ($comment['document_id'] ?? '')]);

            return $this->presentAdmin($comment, $staff, $docs, true);
        }

        $replies = is_array($comment['replies'] ?? null) ? $comment['replies'] : [];
        if ($replies === [] && is_array($comment['reply'] ?? null)) {
            $legacy = $comment['reply'];
            $replies[] = [
                'id' => 'rep_legacy',
                'body' => (string) ($legacy['body'] ?? ''),
                'author_name' => (string) ($legacy['author_name'] ?? 'เจ้าหน้าที่'),
                'author_username' => (string) ($legacy['author_username'] ?? ''),
                'created_at' => (string) ($legacy['created_at'] ?? $now),
                'attachments' => [],
                'visibility' => (string) ($comment['visibility'] ?? 'public'),
                'status' => 'published',
            ];
        }

        $entry = [
            'id' => 'rep_'.bin2hex(random_bytes(6)),
            'body' => $body,
            'author_name' => $this->displayName($staff),
            'author_username' => (string) $staff['username'],
            'created_at' => $now,
            'attachments' => array_map(fn (array $file): array => [
                'id' => (string) ($file['id'] ?? ''),
                'name' => (string) ($file['name'] ?? 'ไฟล์แนบ'),
                'size' => (int) ($file['size'] ?? 0),
                'mime' => (string) ($file['mime'] ?? 'application/octet-stream'),
                'path' => (string) ($file['path'] ?? ''),
            ], $attachments),
            'visibility' => $visibility,
            'status' => $forward ? 'pending_review' : 'published',
        ];
        $replies[] = $entry;

        $fields = [
            'replies' => $replies,
            'reply_draft' => null,
            'updated_at' => $now,
        ];

        if (! $forward) {
            $fields['reply'] = [
                'body' => $body,
                'author_name' => $this->displayName($staff),
                'author_username' => (string) $staff['username'],
                'created_at' => $now,
                'attachments' => array_map(fn (array $file): array => [
                    'id' => (string) ($file['id'] ?? ''),
                    'name' => (string) ($file['name'] ?? 'ไฟล์แนบ'),
                    'size' => (int) ($file['size'] ?? 0),
                    'mime' => (string) ($file['mime'] ?? 'application/octet-stream'),
                ], $attachments),
            ];
            $fields['status'] = 'answered';
            $fields['visibility'] = $visibility;
        }

        try {
            $this->comments->update($commentId, $fields);
        } catch (\Throwable $exception) {
            $this->deleteFiles($attachments);
            throw $exception;
        }

        foreach ($fields as $key => $value) {
            $comment[$key] = $value;
        }

        $docs = $this->documentIndex([(string) ($comment['document_id'] ?? '')]);

        return $this->presentAdmin($comment, $staff, $docs, true);
    }

    /** @return array<string, mixed> */
    private function findForAdmin(Request $request, string $commentId): array
    {
        $comment = $this->comments->find($commentId);
        abort_if($comment === null, 404, 'ไม่พบความคิดเห็น');

        $allowedUnits = $this->adminUnitScope($request);
        if ($allowedUnits !== null) {
            $docs = $this->documentIndex([(string) ($comment['document_id'] ?? '')]);
            $doc = $docs[(string) ($comment['document_id'] ?? '')] ?? null;
            $unit = (string) ($doc['owner_unit_code'] ?? '');
            abort_unless($unit !== '' && in_array($unit, $allowedUnits, true), 404, 'ไม่พบความคิดเห็น');
        }

        return $comment;
    }

    private function assertStaff(Request $request): void
    {
        abort_if($this->viewer($request) === null, 401, 'กรุณาเข้าสู่ระบบ');
        abort_unless($this->isStaff($request), 403, 'เฉพาะเจ้าหน้าที่ที่เข้าถึงได้');
    }

    /** @return list<string>|null null = unrestricted (super admin) */
    private function adminUnitScope(Request $request): ?array
    {
        $user = $this->viewer($request);
        if ($user === null) {
            return [];
        }
        if (($user['is_super_admin'] ?? false) === true) {
            return null;
        }
        $active = $request->hasSession() ? $request->session()->get(CurrentUser::ACTIVE_UNIT_KEY) : null;
        if (! is_string($active) || $active === '') {
            return [];
        }

        return [$active];
    }

    /**
     * @param  list<string>|null  $onlyIds
     * @return array<string, array{title: string, owner_unit_code: string|null, unit_name: string, agency: string}>
     */
    private function documentIndex(?array $onlyIds = null): array
    {
        $index = [];
        $wanted = $onlyIds === null ? null : array_fill_keys($onlyIds, true);

        foreach ($this->documents->listLawMeta() as $row) {
            $id = (string) ($row['document_id'] ?? '');
            if ($id === '' || ($wanted !== null && ! isset($wanted[$id]))) {
                continue;
            }
            $unitCode = isset($row['owner_unit_code']) ? (string) $row['owner_unit_code'] : '';
            $unitName = '';
            if ($unitCode !== '') {
                $unit = $this->units->find($unitCode);
                $unitName = is_array($unit) ? trim((string) ($unit['name'] ?? '')) : '';
            }
            $agencies = is_array($row['agencies'] ?? null) ? $row['agencies'] : [];
            $agency = trim((string) ($agencies[0] ?? $row['agency'] ?? ''));
            $index[$id] = [
                'title' => trim((string) ($row['title'] ?? $id)),
                'owner_unit_code' => $unitCode !== '' ? $unitCode : null,
                'unit_name' => $unitName !== '' ? $unitName : ($agency !== '' ? $agency : '—'),
                'agency' => $agency !== '' ? $agency : ($unitName !== '' ? $unitName : '—'),
            ];
        }

        if ($wanted !== null) {
            foreach (array_keys($wanted) as $id) {
                if (isset($index[$id]) || $id === '') {
                    continue;
                }
                try {
                    $review = $this->documents->getReviewDocument($id);
                } catch (RuntimeException) {
                    continue;
                }
                $meta = is_array($review['law_meta'] ?? null) ? $review['law_meta'] : [];
                $status = $this->documents->getStatus($id) ?? [];
                $unitCode = (string) ($status['owner_unit_code'] ?? '');
                $unitName = '';
                if ($unitCode !== '') {
                    $unit = $this->units->find($unitCode);
                    $unitName = is_array($unit) ? trim((string) ($unit['name'] ?? '')) : '';
                }
                $agency = trim((string) ($meta['agency'] ?? ''));
                $index[$id] = [
                    'title' => trim((string) ($meta['title'] ?? $id)),
                    'owner_unit_code' => $unitCode !== '' ? $unitCode : null,
                    'unit_name' => $unitName !== '' ? $unitName : ($agency !== '' ? $agency : '—'),
                    'agency' => $agency !== '' ? $agency : ($unitName !== '' ? $unitName : '—'),
                ];
            }
        }

        return $index;
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
        $reveal = ($comment['reveal_name'] ?? true) !== false;
        $authorName = $reveal
            ? (string) ($comment['author_name'] ?? 'ผู้ใช้')
            : 'ไม่ระบุชื่อ';

        return [
            'id' => (string) ($comment['_id'] ?? ''),
            'code' => (string) ($comment['code'] ?? $comment['_id'] ?? ''),
            'document_id' => (string) ($comment['document_id'] ?? ''),
            'type' => $type,
            'type_label' => self::TYPES[$type] ?? $type,
            'body' => (string) ($comment['body'] ?? ''),
            'section_ref' => $comment['section_ref'] ?? null,
            'visibility' => ($comment['visibility'] ?? 'public') === 'private' ? 'private' : 'public',
            'reveal_name' => $reveal,
            'status' => $status,
            'status_label' => self::STATUSES[$status] ?? $status,
            'author_name' => $authorName,
            'is_mine' => $viewer !== null && ($comment['author_username'] ?? '') === ($viewer['username'] ?? ''),
            'created_at' => (string) ($comment['created_at'] ?? ''),
            'attachments' => $this->presentAttachments((array) ($comment['attachments'] ?? [])),
            'reply' => $reply === null ? null : [
                'body' => (string) ($reply['body'] ?? ''),
                'author_name' => (string) ($reply['author_name'] ?? 'เจ้าหน้าที่'),
                'created_at' => (string) ($reply['created_at'] ?? ''),
                'attachments' => $this->presentAttachments((array) ($reply['attachments'] ?? [])),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $comment
     * @param  array<string, mixed>|null  $viewer
     * @param  array<string, array{title: string, owner_unit_code: string|null, unit_name: string, agency: string}>  $docs
     * @return array<string, mixed>
     */
    private function presentAdmin(array $comment, ?array $viewer, array $docs, bool $detail = false): array
    {
        $base = $this->present($comment, $viewer);
        // Admin always sees the real author name.
        $base['author_name'] = (string) ($comment['author_name'] ?? 'ผู้ใช้');
        $doc = $docs[(string) ($comment['document_id'] ?? '')] ?? null;
        $base['document_title'] = (string) ($doc['title'] ?? '—');
        $base['unit_code'] = $doc['owner_unit_code'] ?? null;
        $base['unit_name'] = (string) ($doc['unit_name'] ?? '—');
        $base['agency'] = (string) ($doc['agency'] ?? '—');
        $base['notify'] = ($comment['notify'] ?? true) !== false;
        $base['reveal_name'] = ($comment['reveal_name'] ?? true) !== false;
        $base['attachment_count'] = count($base['attachments']);

        if (! $detail) {
            $base['body_preview'] = $this->preview((string) ($comment['body'] ?? ''), 120);

            return $base;
        }

        $replies = [];
        foreach (array_reverse(is_array($comment['replies'] ?? null) ? $comment['replies'] : []) as $index => $reply) {
            if (! is_array($reply)) {
                continue;
            }
            $replies[] = [
                'id' => (string) ($reply['id'] ?? ''),
                'version' => count(is_array($comment['replies'] ?? null) ? $comment['replies'] : []) - $index,
                'body' => (string) ($reply['body'] ?? ''),
                'author_name' => (string) ($reply['author_name'] ?? 'เจ้าหน้าที่'),
                'created_at' => (string) ($reply['created_at'] ?? ''),
                'status' => (string) ($reply['status'] ?? 'published'),
                'visibility' => (($reply['visibility'] ?? 'public') === 'private') ? 'private' : 'public',
                'attachments' => $this->presentAttachments((array) ($reply['attachments'] ?? [])),
                'is_latest' => $index === 0,
            ];
        }

        $draft = is_array($comment['reply_draft'] ?? null) ? $comment['reply_draft'] : null;
        $base['replies'] = $replies;
        $base['reply_draft'] = $draft === null ? null : [
            'body' => (string) ($draft['body'] ?? ''),
            'visibility' => (($draft['visibility'] ?? 'public') === 'private') ? 'private' : 'public',
            'forward_for_review' => (bool) ($draft['forward_for_review'] ?? false),
            'updated_at' => (string) ($draft['updated_at'] ?? ''),
            'attachments' => $this->presentAttachments((array) ($draft['attachments'] ?? [])),
        ];
        $base['archive_reason'] = $comment['archive_reason'] ?? null;
        $base['archived_at'] = $comment['archived_at'] ?? null;
        $base['visibility_history'] = is_array($comment['visibility_history'] ?? null) ? $comment['visibility_history'] : [];

        return $base;
    }

    /** @param list<mixed> $files @return list<array<string, mixed>> */
    private function presentAttachments(array $files): array
    {
        $attachments = [];
        foreach ($files as $file) {
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

        return $attachments;
    }

    /**
     * @param  array<string, mixed>  $comment
     * @return list<array<string, mixed>>
     */
    private function allAttachments(array $comment): array
    {
        $files = [];
        foreach ((array) ($comment['attachments'] ?? []) as $file) {
            if (is_array($file)) {
                $files[] = $file;
            }
        }
        if (is_array($comment['reply'] ?? null)) {
            foreach ((array) ($comment['reply']['attachments'] ?? []) as $file) {
                if (is_array($file)) {
                    $files[] = $file;
                }
            }
        }
        foreach ((array) ($comment['replies'] ?? []) as $reply) {
            if (! is_array($reply)) {
                continue;
            }
            foreach ((array) ($reply['attachments'] ?? []) as $file) {
                if (is_array($file)) {
                    $files[] = $file;
                }
            }
        }
        if (is_array($comment['reply_draft'] ?? null)) {
            foreach ((array) ($comment['reply_draft']['attachments'] ?? []) as $file) {
                if (is_array($file)) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    private function preview(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 1).'…';
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
