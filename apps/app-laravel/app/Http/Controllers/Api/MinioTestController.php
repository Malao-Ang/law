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
        $ext = $file->getClientOriginalExtension();

        try {
            $stored = $this->minio->putFile($tmpPath, $ext, folderPath: '/test');
            return response()->json(['status' => 'ok', 'minio_filename' => $stored]);
        } catch (BuuApiException $e) {
            return response()->json(['error' => $e->getMessage(), 'body' => $e->responseBody], 502);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
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
