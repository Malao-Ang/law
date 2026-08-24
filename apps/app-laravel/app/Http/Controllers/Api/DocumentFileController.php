<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReviewStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class DocumentFileController extends Controller
{
    public function __construct(private readonly ReviewStore $reviewStore) {}

    public function show(Request $request, string $documentId): Response
    {
        $status = $this->reviewStore->getStatus($documentId);
        if ($status === null) {
            abort(404, 'Document not found.');
        }

        // Access check: private documents require an authorised session.
        // ponytail: reuse law_meta.access_scope; group-level enforcement can
        // tighten here later if needed (add when private sharing ships).
        try {
            $meta = $this->reviewStore->getReviewDocument($documentId)['law_meta'] ?? [];
        } catch (\Throwable) {
            $meta = [];
        }
        if (($meta['access_scope'] ?? 'public') === 'private' && ! $request->user()) {
            abort(403, 'This document is private.');
        }

        $relative = (string) ($status['source_path'] ?? '');
        if ($relative === '' || strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'pdf') {
            abort(404, 'Original file not available.');
        }

        $path = $this->reviewStore->absolutePath($relative);
        if (! File::exists($path)) {
            abort(404, 'File not found.');
        }

        return response(File::get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename($relative).'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
