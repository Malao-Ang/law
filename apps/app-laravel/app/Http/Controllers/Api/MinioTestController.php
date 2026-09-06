<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Buu\BuuMinioService;
use App\Services\Buu\BuuApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MinioTestController extends Controller
{
    public function __construct(private readonly BuuMinioService $minio) {}

    public function bucket(Request $request): JsonResponse
    {
        if (! config('buu.minio_enabled')) {
            return response()->json(['error' => 'BUU_MINIO_ENABLED is false'], 503);
        }

        $bucket = trim((string) $request->query('bucket', ''));
        $bucket = $bucket !== '' ? $bucket : null;
        $targetBucket = $bucket ?? config('buu.default_bucket');

        try {
            $files = $this->minio->listFiles('/', $bucket);

            return response()->json([
                'status' => 'ok',
                'exists' => true,
                'access_ok' => true,
                'bucket' => $targetBucket,
                'file_count' => count($files),
            ]);
        } catch (BuuApiException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'exists' => false,
                'access_ok' => false,
                'bucket' => $targetBucket,
                'body' => $e->responseBody,
            ], 502);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'exists' => false,
                'access_ok' => false,
                'bucket' => $targetBucket,
            ], 500);
        }
    }

    public function index(): JsonResponse
    {
        if (! config('buu.minio_enabled')) {
            return response()->json(['error' => 'BUU_MINIO_ENABLED is false'], 503);
        }

        try {
            $files = $this->minio->listFiles('/');
            return response()->json([
                'status' => 'ok',
                'bucket' => config('buu.default_bucket'),
                'files' => $files,
            ]);
        } catch (BuuApiException $e) {
            return response()->json(['error' => $e->getMessage(), 'body' => $e->responseBody], 502);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function upload(Request $request): JsonResponse
    {
        if (! config('buu.minio_enabled')) {
            return response()->json(['error' => 'BUU_MINIO_ENABLED is false'], 503);
        }

        $request->validate(['file' => 'required|file']);

        $file = $request->file('file');
        $tmpPath = $file->getRealPath();
        $originalName = $file->getClientOriginalName();
        $ext = $file->getClientOriginalExtension();

        if (! is_string($tmpPath) || $tmpPath === '' || ! is_file($tmpPath)) {
            return response()->json([
                'error' => 'Laravel received the upload field, but the temporary file is not readable.',
                'received_file' => [
                    'field' => 'file',
                    'original_name' => $originalName,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'is_valid' => $file->isValid(),
                    'upload_error' => $file->getErrorMessage(),
                    'tmp_path' => $tmpPath,
                ],
            ], 422);
        }

        try {
            $stored = $this->minio->putFile($tmpPath, $ext, fileName: $originalName, folderPath: '/test');

            return response()->json([
                'status' => 'ok',
                'minio_filename' => $stored,
                'received_file' => [
                    'field' => 'file',
                    'original_name' => $originalName,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ],
            ]);
        } catch (BuuApiException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'body' => $e->responseBody,
                'received_file' => [
                    'field' => 'file',
                    'original_name' => $originalName,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ],
            ], 502);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'received_file' => [
                    'field' => 'file',
                    'original_name' => $originalName,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ],
            ], 500);
        }
    }

    public function presign(Request $request): JsonResponse
    {
        if (! config('buu.minio_enabled')) {
            return response()->json(['error' => 'BUU_MINIO_ENABLED is false'], 503);
        }

        $request->validate(['filename' => 'required|string']);
        $filename = $request->string('filename')->toString();

        try {
            $links = $this->minio->getPublicLinks(['file' => $filename], ['file' => basename($filename)]);
            return response()->json(['status' => 'ok', 'links' => $links]);
        } catch (BuuApiException $e) {
            return response()->json(['error' => $e->getMessage(), 'body' => $e->responseBody], 502);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
