<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOversightRequest;
use App\Services\OversightService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class OversightController extends Controller
{
    public function __construct(private readonly OversightService $oversight) {}

    public function summary(): JsonResponse
    {
        return response()->json($this->oversight->summary());
    }

    public function update(UpdateOversightRequest $request, string $documentId): JsonResponse
    {
        try {
            $item = $this->oversight->update($documentId, $request->validated());
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'Review document not found.') {
                throw $e;
            }

            return response()->json(['message' => 'ไม่พบเอกสาร'], 404);
        }

        return response()->json($item);
    }
}
