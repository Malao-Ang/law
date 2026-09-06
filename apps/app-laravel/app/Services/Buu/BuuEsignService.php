<?php

namespace App\Services\Buu;

/**
 * e-Sign APIs via Kong (Develop).
 *
 * Documents must already exist on MinIO — pass doc_filename + doc_bucket only.
 */
class BuuEsignService
{
    public function __construct(
        private readonly BuuKongClient $kong,
        private readonly BuuMinioService $minio,
    ) {}

    /**
     * Public URL for doc_returnurl (BUU e-sign POSTs sign status here).
     */
    public function callbackUrl(string $documentId): string
    {
        $base = rtrim((string) config('buu.esign_callback_base_url'), '/');
        $id = rawurlencode(basename($documentId));

        if ($base === '') {
            throw new BuuApiException('BUU_ESIGN_CALLBACK_BASE_URL / APP_URL is not configured.');
        }

        return "{$base}/api/esign/callback/{$id}";
    }

    /**
     * Submit a MinIO-stored document for electronic signing.
     *
     * When $returnUrl is null, uses callbackUrl($documentId) — $documentId is then required.
     *
     * @param  list<array{psn_citizenid: string, docs_comment?: string}>  $signers
     * @param  'L'|'A'  $returnType  L = last signer / reject only; A = every signature
     * @return array<string, mixed>
     */
    public function sendDocumentSign(
        string $ownerCitizenId,
        string $docName,
        string $docFilename,
        array $signers,
        ?string $returnUrl = null,
        ?string $documentId = null,
        ?string $bucket = null,
        string $returnType = 'L',
        ?string $comment = null,
        ?string $sysName = null,
    ): array {
        $resolvedReturnUrl = $returnUrl;
        if ($resolvedReturnUrl === null || $resolvedReturnUrl === '') {
            if ($documentId === null || $documentId === '') {
                throw new BuuApiException('documentId is required when returnUrl is not provided.');
            }
            $resolvedReturnUrl = $this->callbackUrl($documentId);
        }

        return $this->kong->postJson('esign.send', [
            'psn_citizenid' => $ownerCitizenId,
            'doc_name' => $docName,
            'doc_filename' => $docFilename,
            'doc_bucket' => $bucket ?? $this->defaultBucket(),
            'doc_returnurl' => $resolvedReturnUrl,
            'doc_returntype' => $returnType,
            'doc_sysname' => $sysName ?? (string) config('buu.esign_sysname'),
            'doc_comment' => $comment ?? '',
            'doc_signer' => array_values($signers),
        ]);
    }

    /**
     * Upload a local file to MinIO only (bucket root). Returns the basename e-sign expects.
     */
    public function uploadPdf(
        string $absolutePath,
        string $originalExtension,
        ?string $bucket = null,
        string $folderPath = '/',
        bool $qrVerify = false,
    ): string {
        $stored = $this->minio->putFile(
            absolutePath: $absolutePath,
            originalExtension: $originalExtension,
            bucket: $bucket,
            folderPath: $folderPath,
            qrVerify: $qrVerify,
        );

        return $this->esignObjectName($stored);
    }

    /**
     * Convenience: upload local file to MinIO then send to e-sign.
     *
     * @param  list<array{psn_citizenid: string, docs_comment?: string}>  $signers
     * @param  'L'|'A'  $returnType
     * @return array{minio_filename: string, esign: array<string, mixed>, return_url: string}
     */
    public function uploadAndSend(
        string $absolutePath,
        string $originalExtension,
        string $ownerCitizenId,
        string $docName,
        array $signers,
        ?string $documentId = null,
        ?string $returnUrl = null,
        ?string $bucket = null,
        string $returnType = 'L',
        ?string $comment = null,
        ?string $sysName = null,
        string $folderPath = '/',
        bool $qrVerify = false,
    ): array {
        $resolvedReturnUrl = $returnUrl;
        if ($resolvedReturnUrl === null || $resolvedReturnUrl === '') {
            if ($documentId === null || $documentId === '') {
                throw new BuuApiException('documentId is required when returnUrl is not provided.');
            }
            $resolvedReturnUrl = $this->callbackUrl($documentId);
        }

        $stored = $this->uploadPdf(
            absolutePath: $absolutePath,
            originalExtension: $originalExtension,
            bucket: $bucket,
            folderPath: $folderPath,
            qrVerify: $qrVerify,
        );

        $esign = $this->sendDocumentSign(
            ownerCitizenId: $ownerCitizenId,
            docName: $docName,
            docFilename: $stored,
            signers: $signers,
            returnUrl: $resolvedReturnUrl,
            documentId: $documentId,
            bucket: $bucket,
            returnType: $returnType,
            comment: $comment,
            sysName: $sysName,
        );

        return [
            'minio_filename' => $stored,
            'esign' => $esign,
            'return_url' => $resolvedReturnUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelDocumentSign(
        string $psnId,
        string $ownerCitizenId,
        string $docFilename,
        ?string $bucket = null,
    ): array {
        return $this->kong->postJson('esign.cancel', [
            'psn_id' => $psnId,
            'psn_citizenid' => $ownerCitizenId,
            'doc_filename' => $docFilename,
            'doc_bucket' => $bucket ?? $this->defaultBucket(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function addDocumentSigner(
        string $psnId,
        string $ownerCitizenId,
        string $docFilename,
        ?string $bucket = null,
    ): array {
        return $this->kong->postJson('esign.add_signer', [
            'psn_id' => $psnId,
            'psn_citizenid' => $ownerCitizenId,
            'doc_filename' => $docFilename,
            'doc_bucket' => $bucket ?? $this->defaultBucket(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteDocumentSigner(
        string $psnId,
        string $ownerCitizenId,
        string $docFilename,
        ?string $bucket = null,
    ): array {
        return $this->kong->postJson('esign.delete_signer', [
            'psn_id' => $psnId,
            'psn_citizenid' => $ownerCitizenId,
            'doc_filename' => $docFilename,
            'doc_bucket' => $bucket ?? $this->defaultBucket(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function checkCertificateAndSignature(string $psnId): array
    {
        $response = $this->kong->postJson('esign.check_certificate', [
            'psn_id' => $psnId,
        ]);

        $result = $response['result'] ?? [];

        return is_array($result) ? array_values($result) : [];
    }

    /**
     * BUU e-sign sample uses a basename in the bucket root, not a folder key.
     */
    private function esignObjectName(string $stored): string
    {
        return basename(str_replace('\\', '/', ltrim($stored, '/')));
    }

    private function defaultBucket(): string
    {
        $bucket = (string) config('buu.default_bucket');

        if ($bucket === '') {
            throw new BuuApiException('BUU_MINIO_BUCKET is not configured.');
        }

        return $bucket;
    }
}
