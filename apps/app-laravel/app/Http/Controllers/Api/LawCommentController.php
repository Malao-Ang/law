<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LawCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LawCommentController extends Controller
{
    public function __construct(private readonly LawCommentService $comments) {}

    public function index(Request $request, string $documentId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:question,suggestion,incorrect,other'],
            'status' => ['nullable', 'string', 'in:pending,answered'],
            'visibility' => ['nullable', 'string', 'in:all,public,mine'],
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'in:newest,oldest'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($this->comments->list($request, $documentId, [
            'type' => $validated['type'] ?? '',
            'status' => $validated['status'] ?? '',
            'visibility' => $validated['visibility'] ?? 'all',
            'q' => $validated['q'] ?? '',
            'sort' => $validated['sort'] ?? 'newest',
            'limit' => (int) ($validated['limit'] ?? 200),
        ]));
    }

    public function store(Request $request, string $documentId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:question,suggestion,incorrect,other'],
            'body' => ['required', 'string', 'max:5000'],
            'section_ref' => ['nullable', 'string', 'max:200'],
            'visibility' => ['nullable', 'string', 'in:public,private'],
            'attachments' => ['nullable', 'array', 'max:'.LawCommentService::MAX_FILES],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png,docx', 'max:10240'],
        ]);

        $files = $request->file('attachments', []);
        if (! is_array($files)) {
            $files = [$files];
        }

        $comment = $this->comments->create($request, $documentId, $validated, array_values($files));

        return response()->json(['comment' => $comment], 201);
    }

    public function reply(Request $request, string $documentId, string $commentId): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        return response()->json([
            'comment' => $this->comments->reply($request, $documentId, $commentId, $validated['body']),
        ]);
    }

    public function file(Request $request, string $documentId, string $commentId, string $fileId): StreamedResponse
    {
        $found = $this->comments->attachment($request, $documentId, $commentId, $fileId);
        $file = $found['file'];
        $name = str_replace('"', '', (string) ($file['name'] ?? 'attachment'));

        return Storage::disk('local')->response((string) $file['path'], $name, [
            'Content-Type' => (string) ($file['mime'] ?? 'application/octet-stream'),
        ]);
    }
}
