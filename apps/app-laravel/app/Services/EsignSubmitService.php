<?php

namespace App\Services;

use App\Services\Buu\BuuApiException;
use App\Services\Buu\BuuEsignService;
use RuntimeException;

/**
 * App-level e-sign flow: upload PDF to MinIO, then SendDocumentSign as a separate step.
 */
class EsignSubmitService
{
    public function __construct(
        private readonly ReviewStore $reviewStore,
        private readonly DocumentExportService $exportService,
        private readonly BuuEsignService $buuEsign,
    ) {}

    /**
     * Export review PDF and PutFile only. Does not call SendDocumentSign.
     *
     * @param  list<array{citizen_id?: string, psn_citizenid?: string, docs_comment?: string, note?: string, name?: string}>  $signers
     * @param  'L'|'A'  $returnType
     * @return array{
     *     document_id: string,
     *     minio_filename: string,
     *     bucket: string,
     *     return_url: string,
     *     doc_name: string,
     *     owner_citizen_id: string
     * }
     */
    public function upload(
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
        $owner = $this->resolveOwnerCitizenId(
            $ownerCitizenId,
            $docSigners[0]['psn_citizenid'] ?? null,
        );
        $docName = $this->docName($document);
        $bucket = $this->requireBucket();
        $returnUrl = $this->buuEsign->callbackUrl($documentId);

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

            $minioFilename = $this->buuEsign->uploadPdf(
                absolutePath: $pdfPath,
                originalExtension: 'pdf',
                bucket: $bucket,
                folderPath: '/',
            );
        } finally {
            @unlink($pdfPath);
        }

        $now = now()->toIso8601String();
        $this->reviewStore->setStatus($documentId, [
            'esign_exported_at' => $now,
            'esign_uploaded_at' => $now,
            'esign_doc_name' => $docName,
            'esign_doc_filename' => $minioFilename,
            'esign_bucket' => $bucket,
            'esign_owner_citizenid' => $owner,
            'esign_return_url' => $returnUrl,
            'esign_return_type' => $returnType,
            'esign_comment' => $comment,
            'esign_signers' => $docSigners,
            'esign_send_response' => null,
            'esign_submitted_at' => null,
            'esign_sign_status' => null,
            'esign_rejected_at' => null,
        ]);

        return [
            'document_id' => $documentId,
            'minio_filename' => $minioFilename,
            'bucket' => $bucket,
            'return_url' => $returnUrl,
            'doc_name' => $docName,
            'owner_citizen_id' => $owner,
        ];
    }

    /**
     * Call SendDocumentSign for a document already uploaded to MinIO.
     *
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
    public function send(
        string $documentId,
        array $signers = [],
        ?string $ownerCitizenId = null,
        ?string $comment = null,
        ?string $returnType = null,
    ): array {
        $documentId = basename($documentId);
        $status = $this->reviewStore->getStatus($documentId);

        if ($status === null) {
            throw new BuuApiException("Document not found: {$documentId}", 404);
        }

        $docFilename = (string) ($status['esign_doc_filename'] ?? '');
        if ($docFilename === '') {
            throw new BuuApiException('Upload the PDF to MinIO first (POST .../esign/upload).', 422);
        }

        $persistedSigners = is_array($status['esign_signers'] ?? null) ? $status['esign_signers'] : [];
        $docSigners = $signers !== [] ? $this->normalizeSigners($signers) : $persistedSigners;
        if ($docSigners === []) {
            throw new BuuApiException('At least one signer is required.', 422);
        }

        $owner = $this->resolveOwnerCitizenId(
            $ownerCitizenId ?: (string) ($status['esign_owner_citizenid'] ?? ''),
            $docSigners[0]['psn_citizenid'] ?? null,
        );
        $bucket = (string) ($status['esign_bucket'] ?? $this->requireBucket());
        $docName = (string) ($status['esign_doc_name'] ?? $documentId);
        $resolvedReturnType = $returnType ?: (string) ($status['esign_return_type'] ?? 'L');
        if ($resolvedReturnType !== 'A' && $resolvedReturnType !== 'L') {
            $resolvedReturnType = 'L';
        }
        $resolvedComment = $comment ?? (isset($status['esign_comment']) ? (string) $status['esign_comment'] : null);
        $returnUrl = (string) ($status['esign_return_url'] ?? $this->buuEsign->callbackUrl($documentId));

        $esign = $this->buuEsign->sendDocumentSign(
            ownerCitizenId: $owner,
            docName: $docName,
            docFilename: $docFilename,
            signers: $docSigners,
            returnUrl: $returnUrl,
            documentId: $documentId,
            bucket: $bucket !== '' ? $bucket : null,
            returnType: $resolvedReturnType,
            comment: $resolvedComment,
        );

        $now = now()->toIso8601String();
        $this->reviewStore->setStatus($documentId, [
            'esign_submitted_at' => $now,
            'esign_owner_citizenid' => $owner,
            'esign_return_url' => $returnUrl,
            'esign_return_type' => $resolvedReturnType,
            'esign_signers' => $docSigners,
            'esign_send_response' => $esign,
            'esign_sign_status' => null,
            'esign_rejected_at' => null,
        ]);

        return [
            'document_id' => $documentId,
            'minio_filename' => $docFilename,
            'bucket' => $bucket,
            'return_url' => $returnUrl,
            'doc_name' => $docName,
            'owner_citizen_id' => $owner,
            'esign' => $esign,
        ];
    }

    private function requireBucket(): string
    {
        $bucket = (string) config('buu.default_bucket');

        if ($bucket === '') {
            throw new BuuApiException('BUU_MINIO_BUCKET is not configured.');
        }

        return $bucket;
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
