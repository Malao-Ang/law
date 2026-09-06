<?php

namespace App\Http\Controllers\Api;

use App\Services\ReviewStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives e-sign status callbacks (doc_returnurl).
 *
 * After persisting (or logging unknown docs), always returns {status: success}
 * so Kong treats the callback as completed.
 */
class EsignCallbackController
{
    public function receive(Request $request, string $documentId, ReviewStore $reviewStore): JsonResponse
    {
        $documentId = basename($documentId);
        $payload = $request->all();

        if ($reviewStore->getStatus($documentId) === null) {
            Log::warning('e-sign callback for unknown document', [
                'document_id' => $documentId,
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'success']);
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
            'signer_psnid' => (string) $request->input('signer_psnid', ''),
            'signer_username' => (string) $request->input('signer_username', ''),
            'owner_citizenid' => (string) $request->input('owner_citizenid', ''),
            'owner_psnid' => (string) $request->input('owner_psnid', ''),
            'owner_username' => (string) $request->input('owner_username', ''),
            'owner_docno' => (string) $request->input('owner_docno', ''),
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
            'esign_last_signer_psnid' => $event['signer_psnid'],
            'esign_last_signer_username' => $event['signer_username'],
            'esign_owner_psnid' => $event['owner_psnid'],
            'esign_owner_username' => $event['owner_username'],
            'esign_owner_docno' => $event['owner_docno'],
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

        return response()->json(['status' => 'success']);
    }
}
