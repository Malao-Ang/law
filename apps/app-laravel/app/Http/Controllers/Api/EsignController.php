<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Buu\BuuApiException;
use App\Services\EsignSubmitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class EsignController extends Controller
{
    public function __construct(private readonly EsignSubmitService $esignSubmit) {}

    public function send(Request $request, string $documentId): JsonResponse
    {
        $validated = $request->validate([
            'owner_citizen_id' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'return_type' => ['nullable', 'string', 'in:L,A'],
            'signers' => ['required', 'array', 'min:1'],
            'signers.*.citizen_id' => ['nullable', 'string', 'max:32'],
            'signers.*.psn_citizenid' => ['nullable', 'string', 'max:32'],
            'signers.*.docs_comment' => ['nullable', 'string', 'max:500'],
            'signers.*.note' => ['nullable', 'string', 'max:500'],
            'signers.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->esignSubmit->submit(
                documentId: $documentId,
                signers: $validated['signers'],
                ownerCitizenId: $validated['owner_citizen_id'] ?? null,
                comment: $validated['comment'] ?? null,
                returnType: $validated['return_type'] ?? 'L',
            );
        } catch (BuuApiException $exception) {
            return $this->buuError($exception);
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $status = $message === 'PDF service unavailable' ? 503 : 500;

            return response()->json(['message' => $message], $status);
        } catch (Throwable $exception) {
            Log::error('e-sign send failed', [
                'document_id' => $documentId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to submit e-sign request.'], 500);
        }

        return response()->json([
            'status' => 'submitted',
            ...$result,
        ]);
    }

    public function cancel(Request $request, string $documentId): JsonResponse
    {
        $validated = $request->validate([
            'psn_id' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $result = $this->esignSubmit->cancel(
                documentId: $documentId,
                psnId: $validated['psn_id'] ?? null,
            );
        } catch (BuuApiException $exception) {
            return $this->buuError($exception);
        } catch (Throwable $exception) {
            Log::error('e-sign cancel failed', [
                'document_id' => $documentId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to cancel e-sign request.'], 500);
        }

        return response()->json([
            'status' => 'cancelled',
            ...$result,
        ]);
    }

    private function buuError(BuuApiException $exception): JsonResponse
    {
        $status = $exception->statusCode;
        if ($status === null || $status < 400 || $status > 599) {
            $status = 502;
        }

        Log::warning('BUU e-sign API error', [
            'message' => $exception->getMessage(),
            'status' => $status,
            'body' => $exception->responseBody,
        ]);

        return response()->json([
            'message' => $exception->getMessage(),
            'buu' => $exception->responseBody,
        ], $status);
    }
}
