<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LawSuggestRequest;
use App\Services\Search\LawSuggestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LawSuggestController extends Controller
{
    public function suggest(LawSuggestRequest $request, LawSuggestService $service): JsonResponse
    {
        try {
            return response()->json($service->suggest($request->validated()));
        } catch (\Throwable $exception) {
            Log::warning('Law suggest failed', ['error' => $exception->getMessage()]);

            return response()->json(['message' => 'คำแนะนำการค้นหาไม่พร้อมใช้งาน'], 503);
        }
    }
}
