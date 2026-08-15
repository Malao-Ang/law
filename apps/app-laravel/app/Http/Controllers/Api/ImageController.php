<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReviewStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class ImageController extends Controller
{
    private static array $ALLOWED_EXTENSIONS = ['png', 'jpeg', 'jpg', 'gif', 'webp', 'bmp', 'tiff'];

    public function __construct(private readonly ReviewStore $reviewStore) {}

    public function show(Request $request, string $documentId, string $filename): Response
    {
        // Reject any path traversal attempt.
        if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
            abort(400, 'Invalid filename.');
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($ext, self::$ALLOWED_EXTENSIONS, strict: true)) {
            abort(400, 'Unsupported image type.');
        }

        $path = $this->reviewStore->absolutePath('images/'.basename($documentId).'/'.basename($filename));

        if (! File::exists($path)) {
            abort(404, 'Image not found.');
        }

        $mimeMap = [
            'png' => 'image/png',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'tiff' => 'image/tiff',
        ];

        return response(File::get($path), 200, [
            'Content-Type' => $mimeMap[$ext] ?? 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function showPage(Request $request, string $documentId, string $pageNo): Response
    {
        if (! ctype_digit($pageNo) || (int) $pageNo < 1) {
            abort(400, 'Invalid page number.');
        }

        $safeDocumentId = basename($documentId);
        $safePageNo = (int) $pageNo;
        $pageDir = $this->reviewStore->absolutePath('pages/'.$safeDocumentId);
        $candidates = glob($pageDir.'/page-'.$safePageNo.'*');

        if (! $candidates) {
            abort(404, 'Page image not found.');
        }

        sort($candidates);
        $path = $candidates[0];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($ext, self::$ALLOWED_EXTENSIONS, strict: true)) {
            abort(400, 'Unsupported image type.');
        }

        $mimeMap = [
            'png' => 'image/png',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'tiff' => 'image/tiff',
        ];

        return response(File::get($path), 200, [
            'Content-Type' => $mimeMap[$ext] ?? 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
