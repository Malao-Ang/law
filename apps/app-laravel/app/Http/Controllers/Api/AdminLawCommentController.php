<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\LawCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminLawCommentController extends Controller
{
    public function __construct(
        private readonly LawCommentService $comments,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:all,pending,answered,archived'],
            'type' => ['nullable', 'string', 'in:question,suggestion,incorrect,other'],
            'unit' => ['nullable', 'string', 'max:32'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->comments->adminList($request, [
            'status' => $validated['status'] ?? 'all',
            'type' => $validated['type'] ?? '',
            'unit' => $validated['unit'] ?? '',
            'date' => $validated['date'] ?? '',
            'q' => $validated['q'] ?? '',
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? 20),
        ]));
    }

    public function show(Request $request, string $commentId): JsonResponse
    {
        return response()->json(['comment' => $this->comments->adminShow($request, $commentId)]);
    }

    public function reply(Request $request, string $commentId): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'visibility' => ['nullable', 'string', 'in:public,private'],
            'forward_for_review' => ['nullable', 'boolean'],
            'as_draft' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:'.LawCommentService::MAX_FILES],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $files = $request->file('attachments', []);
        if (! is_array($files)) {
            $files = [$files];
        }

        $comment = $this->comments->adminReply($request, $commentId, [
            'body' => $validated['body'],
            'visibility' => $validated['visibility'] ?? 'public',
            'forward_for_review' => (bool) ($validated['forward_for_review'] ?? false),
            'as_draft' => (bool) ($validated['as_draft'] ?? false),
        ], array_values($files));

        $this->audit->record(
            'update',
            'comment',
            $commentId,
            (bool) ($validated['as_draft'] ?? false) ? 'บันทึกร่างคำตอบความคิดเห็น' : 'ตอบกลับความคิดเห็น',
            [
                'รหัส: '.(string) ($comment['code'] ?? $commentId),
                'สถานะ: '.(string) ($comment['status_label'] ?? $comment['status'] ?? ''),
            ],
            (string) ($comment['code'] ?? $commentId),
            (string) ($comment['document_id'] ?? null),
        );

        return response()->json(['comment' => $comment]);
    }

    public function visibility(Request $request, string $commentId): JsonResponse
    {
        $validated = $request->validate([
            'visibility' => ['required', 'string', 'in:public,private'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $comment = $this->comments->updateVisibility($request, $commentId, $validated);

        $this->audit->record(
            'update',
            'comment',
            $commentId,
            'เปลี่ยนการเผยแพร่ความคิดเห็น',
            [
                'รหัส: '.(string) ($comment['code'] ?? $commentId),
                'การเผยแพร่: '.(($comment['visibility'] ?? '') === 'private' ? 'Private' : 'Public'),
                'เหตุผล: '.$validated['reason'],
            ],
            (string) ($comment['code'] ?? $commentId),
            (string) ($comment['document_id'] ?? null),
        );

        return response()->json(['comment' => $comment]);
    }

    public function archive(Request $request, string $commentId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $comment = $this->comments->archive($request, $commentId, $validated);

        $this->audit->record(
            'update',
            'comment',
            $commentId,
            'จัดเก็บความคิดเห็นเข้าคลัง',
            [
                'รหัส: '.(string) ($comment['code'] ?? $commentId),
                'เหตุผล: '.$validated['reason'],
            ],
            (string) ($comment['code'] ?? $commentId),
            (string) ($comment['document_id'] ?? null),
        );

        return response()->json(['comment' => $comment]);
    }

    public function file(Request $request, string $commentId, string $fileId): StreamedResponse
    {
        $found = $this->comments->adminAttachment($request, $commentId, $fileId);
        $file = $found['file'];
        $name = str_replace('"', '', (string) ($file['name'] ?? 'attachment'));

        return Storage::disk('local')->response((string) $file['path'], $name, [
            'Content-Type' => (string) ($file['mime'] ?? 'application/octet-stream'),
        ]);
    }
}
