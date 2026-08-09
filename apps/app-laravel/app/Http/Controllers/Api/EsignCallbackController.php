<?php

namespace App\Http\Controllers\Api;

use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives e-sign status callbacks (doc_returnurl).
 *
 * Payload from API-Develop.pdf:
 *  - sign_status: Y=อนุมัติ, N=ไม่อนุมัติ
 *  - sign_message, doc_name, doc_filename, signer_citizenid, response
 */
class EsignCallbackController
{
    public function receive(Request $request, string $documentId, ReviewStore $reviewStore): JsonResponse
    {
        $documentId = basename($documentId);

        if ($reviewStore->getStatus($documentId) === null) {
            Log::warning('e-sign callback for unknown document', [
                'document_id' => $documentId,
                'payload' => $request->all(),
            ]);

            return response()->json(['error' => 'Document not found'], 404);
        }

        $signStatus = strtoupper((string) $request->input('sign_status', ''));
        $signMessage = (string) $request->input('sign_message', '');
        $docName = (string) $request->input('doc_name', '');
        $docFilename = (string) $request->input('doc_filename', '');
        $signerCitizenId = (string) $request->input('signer_citizenid', '');

        $event = [
            'at' => now()->toIso8601String(),
            'sign_status' => $signStatus,
            'sign_message' => $signMessage,
            'doc_name' => $docName,
            'doc_filename' => $docFilename,
            'signer_citizenid' => $signerCitizenId,
        ];

        $current = $reviewStore->getStatus($documentId) ?? [];
        $history = is_array($current['esign_callbacks'] ?? null) ? $current['esign_callbacks'] : [];
        $history[] = $event;

        $patch = [
            'esign_callbacks' => $history,
            'esign_last_callback_at' => $event['at'],
            'esign_sign_status' => $signStatus,
            'esign_sign_message' => $signMessage,
            'esign_doc_name' => $docName !== '' ? $docName : ($current['esign_doc_name'] ?? null),
            'esign_doc_filename' => $docFilename !== '' ? $docFilename : ($current['esign_doc_filename'] ?? null),
            'esign_last_signer_citizenid' => $signerCitizenId,
        ];

        if ($signStatus === 'Y') {
            $patch['esign_signed_at'] = $event['at'];
        } elseif ($signStatus === 'N') {
            $patch['esign_rejected_at'] = $event['at'];
        }

        $reviewStore->setStatus($documentId, $patch);

        Log::info('e-sign callback received', [
            'document_id' => $documentId,
            'sign_status' => $signStatus,
            'signer_citizenid' => $signerCitizenId,
        ]);

        return response()->json(['status' => 'received']);
    }
}
