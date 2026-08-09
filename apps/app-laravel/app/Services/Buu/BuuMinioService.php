<?php

namespace App\Services\Buu;

/**
 * MinIO object-store APIs via Kong (Develop).
 *
 * Flow for e-sign: PutFile → keep returned filename → pass as doc_filename to e-sign.
 */
class BuuMinioService
{
    public function __construct(private readonly BuuKongClient $kong) {}

    /**
     * List file names under a path in a bucket.
     *
     * @return list<string>
     */
    public function listFiles(string $path, ?string $bucket = null): array
    {
        $response = $this->kong->postJson('minio.list', [
            'path' => $path,
            'bucket' => $bucket ?? $this->defaultBucket(),
        ]);

        $result = $response['result'] ?? [];

        return is_array($result) ? array_values(array_map('strval', $result)) : [];
    }

    /**
     * Upload a local file. Returns the stored object name on MinIO.
     *
     * @param  string  $absolutePath  Real filesystem path to the file
     * @param  string  $originalExtension  e.g. pdf|doc|png (without dot)
     * @param  string|null  $fileName  Optional desired name; server may generate one
     * @param  string  $folderPath  Folder path inside bucket (default /)
     * @param  bool  $qrVerify  Stamp QR verify on PDF tail
     */
    public function putFile(
        string $absolutePath,
        string $originalExtension,
        ?string $bucket = null,
        ?string $fileName = null,
        string $folderPath = '/',
        bool $qrVerify = false,
    ): string {
        if (! is_file($absolutePath)) {
            throw new BuuApiException("File not found for MinIO upload: {$absolutePath}");
        }

        $extension = ltrim($originalExtension, '.');
        $uploadName = $fileName ?: basename($absolutePath);

        $fields = [
            [
                'name' => 'qrVerify',
                'contents' => $qrVerify ? 'true' : 'false',
            ],
            [
                'name' => 'path',
                'contents' => $folderPath,
            ],
            [
                'name' => 'bucket',
                'contents' => $bucket ?? $this->defaultBucket(),
            ],
            [
                'name' => 'originalExtension',
                'contents' => $extension,
            ],
            [
                'name' => 'fileUpload',
                'contents' => fopen($absolutePath, 'r'),
                'filename' => $uploadName,
            ],
        ];

        if ($fileName !== null && $fileName !== '') {
            $fields[] = [
                'name' => 'fileName',
                'contents' => $fileName,
            ];
        }

        $response = $this->kong->postMultipart('minio.put', $fields);
        $stored = (string) ($response['result'] ?? '');

        if ($stored === '') {
            throw new BuuApiException('MinIO PutFile returned empty result', null, $response);
        }

        return $stored;
    }

    /**
     * Create time-limited view/download URLs for one or more objects.
     *
     * @param  array<string, string>  $filePaths  key => MinIO object name
     * @param  array<string, string>  $originalFileNames  key => display name
     * @return array<string, array{view?: string, download?: string}>
     */
    public function getPublicLinks(
        array $filePaths,
        array $originalFileNames = [],
        int $time = 10,
        string $carbonTime = 'M',
        ?string $bucket = null,
    ): array {
        $response = $this->kong->postJson('minio.public', [
            'filePath' => $filePaths,
            'time' => (string) $time,
            'carbonTime' => $carbonTime,
            'bucket' => $bucket ?? $this->defaultBucket(),
            'originalFileName' => $originalFileNames,
        ]);

        $result = $response['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    public function deleteFile(string $filePath, ?string $bucket = null): void
    {
        $this->kong->postJson('minio.delete', [
            'filePath' => $filePath,
            'bucket' => $bucket ?? $this->defaultBucket(),
        ]);
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
