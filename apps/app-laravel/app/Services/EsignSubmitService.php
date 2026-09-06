<?php

namespace App\Services;

use App\Services\Buu\BuuApiException;
use App\Services\Buu\BuuEsignService;
use RuntimeException;

/**
 * App-level e-sign flow: export PDF → MinIO (via Kong) → SendDocumentSign → persist status.
 */
class EsignSubmitService
{
    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly DocumentExportService $exportService,
        private readonly BuuEsignService $buuEsign,
    ) {}

    /**
     * @param  list<array{citizen_id?: string, psn_citizenid?: string, docs_comment?: string, note?: string, name?: string}>  $signers
     * @param  'L'|'A'  $returnType
     * @return array{
     *     document_id: string,
     *     minio_filename: string,
     *     bucket: string,
     *     return_url: string,
     *     doc_name: string,
     *     owner_citizen_id: string,
     *     esign: array<string, mixed>
     * }
     */
    public function submit(
        string $documentId,
        array $signers,
        ?string $ownerCitizenId = null,
        ?string $comment = null,
        string $returnType = 'L',
    ): array {
        $documentId = basename($documentId);

        try {
            $document = $this->reviewStore->getReviewDocument($documentId);
        } catch (RuntimeException) {
            throw new BuuApiException("Document not found: {$documentId}", 404);
        }

        $docSigners = $this->normalizeSigners($signers);
        // Sandbox mock: reuse first signer as document owner when not configured.
        $owner = $this->resolveOwnerCitizenId(
            $ownerCitizenId,
            $docSigners[0]['psn_citizenid'] ?? null,
        );
        $docName = $this->docName($document);
        $bucket = (string) config('buu.default_bucket');

        if ($bucket === '') {
            throw new BuuApiException('BUU_MINIO_BUCKET is not configured.');
        }

        $pdfBytes = $this->exportService->toPdf($document);
        $tempPath = tempnam(sys_get_temp_dir(), 'esign_pdf_');
        if ($tempPath === false) {
            throw new BuuApiException('Failed to create temporary PDF file.');
        }

        $pdfPath = $tempPath.'.pdf';
        @unlink($tempPath);

        try {
            if (file_put_contents($pdfPath, $pdfBytes) === false) {
                throw new BuuApiException('Failed to write temporary PDF file.');
            }

            $result = $this->buuEsign->uploadAndSend(
                absolutePath: $pdfPath,
                originalExtension: 'pdf',
                ownerCitizenId: $owner,
                docName: $docName,
                signers: $docSigners,
                documentId: $documentId,
                bucket: $bucket,
                returnType: $returnType,
                comment: $comment,
                folderPath: '/'.$documentId,
            );
        } finally {
            @unlink($pdfPath);
        }

        $now = now()->toIso8601String();
        $this->reviewStore->setStatus($documentId, [
            'esign_exported_at' => $now,
            'esign_submitted_at' => $now,
            'esign_doc_name' => $docName,
            'esign_doc_filename' => $result['minio_filename'],
            'esign_bucket' => $bucket,
            'esign_owner_citizenid' => $owner,
            'esign_return_url' => $result['return_url'],
            'esign_return_type' => $returnType,
            'esign_signers' => $docSigners,
            'esign_send_response' => $result['esign'],
            'esign_sign_status' => null,
            'esign_rejected_at' => null,
        ]);

        return [
            'document_id' => $documentId,
            'minio_filename' => $result['minio_filename'],
            'bucket' => $bucket,
            'return_url' => $result['return_url'],
            'doc_name' => $docName,
            'owner_citizen_id' => $owner,
            'esign' => $result['esign'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $documentId, ?string $psnId = null): array
    {
        $documentId = basename($documentId);
        $status = $this->reviewStore->getStatus($documentId);

        if ($status === null) {
            throw new BuuApiException("Document not found: {$documentId}", 404);
        }

        $docFilename = (string) ($status['esign_doc_filename'] ?? '');
        $owner = (string) ($status['esign_owner_citizenid'] ?? '');
        $bucket = (string) ($status['esign_bucket'] ?? config('buu.default_bucket'));

        if ($docFilename === '' || $owner === '') {
            throw new BuuApiException('Document has not been submitted to e-sign yet.', 422);
        }

        $resolvedPsnId = $psnId !== null && $psnId !== ''
            ? $psnId
            : (string) ($status['esign_cancel_psn_id'] ?? $owner);

        $response = $this->buuEsign->cancelDocumentSign(
            psnId: $resolvedPsnId,
            ownerCitizenId: $owner,
            docFilename: $docFilename,
            bucket: $bucket !== '' ? $bucket : null,
        );

        $this->reviewStore->setStatus($documentId, [
            'esign_cancelled_at' => now()->toIso8601String(),
            'esign_cancel_response' => $response,
            'esign_sign_status' => 'C',
            'esign_send_response' => null,
            'esign_submitted_at' => null,
            'esign_confirmed_at' => null,
            'workflow_completed_step' => 5,
            'workflow_current_step' => 6,
        ]);

        return [
            'document_id' => $documentId,
            'minio_filename' => $docFilename,
            'esign' => $response,
        ];
    }

    private function resolveOwnerCitizenId(?string $ownerCitizenId, ?string $fallbackFromSigner = null): string
    {
        $owner = trim((string) ($ownerCitizenId ?? ''));
        if ($owner === '') {
            $owner = trim((string) config('buu.esign_owner_citizenid'));
        }
        if ($owner === '') {
            $owner = trim((string) ($fallbackFromSigner ?? ''));
        }

        if ($owner === '') {
            throw new BuuApiException(
                'owner_citizen_id is required (or set BUU_ESIGN_OWNER_CITIZENID / signer citizen_id).',
                422,
            );
        }

        return $owner;
    }

    /**
     * @param  list<array{citizen_id?: string, psn_citizenid?: string, docs_comment?: string, note?: string, name?: string}>  $signers
     * @return list<array{psn_citizenid: string, docs_comment?: string}>
     */
    private function normalizeSigners(array $signers): array
    {
        $defaultSigner = trim((string) config('buu.esign_default_signer_citizenid'));
        $normalized = [];

        foreach ($signers as $signer) {
            if (! is_array($signer)) {
                continue;
            }

            $citizenId = trim((string) ($signer['psn_citizenid'] ?? $signer['citizen_id'] ?? ''));
            if ($citizenId === '') {
                $citizenId = $defaultSigner;
            }

            if ($citizenId === '') {
                throw new BuuApiException(
                    'Each signer needs citizen_id (or set BUU_ESIGN_DEFAULT_SIGNER_CITIZENID).',
                    422,
                );
            }

            $entry = ['psn_citizenid' => $citizenId];
            $comment = trim((string) ($signer['docs_comment'] ?? $signer['note'] ?? ''));
            if ($comment !== '') {
                $entry['docs_comment'] = $comment;
            }

            $normalized[] = $entry;
        }

        if ($normalized === []) {
            throw new BuuApiException('At least one signer is required.', 422);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function docName(array $document): string
    {
        $lawMeta = is_array($document['law_meta'] ?? null) ? $document['law_meta'] : [];
        $title = trim((string) ($lawMeta['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        return $this->exportService->safeFilenameBase($document);
    }
}
