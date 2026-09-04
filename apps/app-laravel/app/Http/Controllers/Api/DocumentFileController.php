<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Buu\BuuMinioService;
use App\Services\ReviewStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\HeaderUtils;

class DocumentFileController extends Controller
{
    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly BuuMinioService $minioService,
    ) {}

    public function show(Request $request, string $documentId): Response|RedirectResponse
    {
        $status = $this->reviewStore->getStatus($documentId);
        if ($status === null) {
            abort(404, 'Document not found.');
        }

        // Access check: private documents require an authorised session.
        try {
            $meta = $this->reviewStore->getReviewDocument($documentId)['law_meta'] ?? [];
        } catch (\Throwable) {
            $meta = [];
        }
        if (($meta['access_scope'] ?? 'public') === 'private' && ! $request->user()) {
            abort(403, 'This document is private.');
        }

        $relative = (string) ($status['source_path'] ?? '');
        if ($relative === '') {
            abort(404, 'Original file not available.');
        }

        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf'  => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc'  => 'application/msword',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
        $isDownload = $request->boolean('download');

        // 1. Try local file first
        $path = $this->reviewStore->absolutePath($relative);
        if (File::exists($path)) {
            $disposition = $isDownload
                ? HeaderUtils::DISPOSITION_ATTACHMENT
                : HeaderUtils::DISPOSITION_INLINE;
            $filename = basename((string) ($status['source_file'] ?? $relative));
            $asciiFallback = trim((string) preg_replace('/[^\x20-\x7e]/', '', $filename)) ?: 'document';
            $dispositionHeader = HeaderUtils::makeDisposition($disposition, $filename, $asciiFallback);

            return response(File::get($path), 200, [
                'Content-Type'        => $mime,
                'Content-Disposition' => $dispositionHeader,
                'Cache-Control'       => 'private, max-age=3600',
            ]);
        }

        // 2. MinIO fallback
        if (config('buu.minio_enabled')) {
            try {
                $links = $this->minioService->getPublicLinks(
                    ['file' => $relative],
                    ['file' => basename((string) ($status['source_file'] ?? $relative))],
                );
                $url = $links['file'][$isDownload ? 'download' : 'view'] ?? $links['file']['view'] ?? null;
                if (is_string($url) && $url !== '') {
                    return redirect($url);
                }
            } catch (\Throwable) {
                // Fall through to the same 404 as missing local files.
            }
        }

        abort(404, 'File not found.');
    }

    public function showRelated(Request $request, string $documentId, string $targetDocumentId): Response|RedirectResponse
    {
        // Security: verify the relation exists on the source doc before serving the target file
        try {
            $doc = $this->reviewStore->getReviewDocument($documentId);
        } catch (\Throwable) {
            abort(404, 'Source document not found.');
        }

        $relations = $doc['relations'] ?? [];
        $linked = collect($relations)->firstWhere('target_document_id', $targetDocumentId);
        if (! $linked) {
            abort(404, 'Related document not found.');
        }

        return $this->show($request, $targetDocumentId);
    }
}
