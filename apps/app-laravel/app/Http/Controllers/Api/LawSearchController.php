<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LawSearchRequest;
use App\Services\Search\LawSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LawSearchController extends Controller
{
    public function search(LawSearchRequest $request, LawSearchService $service): JsonResponse
    {
        try {
            return response()->json($service->search($request->validated()));
        } catch (\Throwable $exception) {
            Log::warning('Law search failed', ['error' => $exception->getMessage()]);

            return response()->json(['message' => 'ค้นหาไม่พร้อมใช้งาน'], 503);
        }
    }
}
