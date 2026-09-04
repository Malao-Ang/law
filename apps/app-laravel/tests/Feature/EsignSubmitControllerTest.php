<?php

namespace Tests\Feature;

use App\Services\Buu\BuuEsignService;
use App\Services\DocumentExportService;
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class EsignSubmitControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_send_uploads_via_mocked_buu_and_persists_status(): void
    {
        config([
            'buu.default_bucket' => 'buu-contract',
            'buu.esign_owner_citizenid' => '1111111111111',
            'buu.esign_callback_base_url' => 'https://example.test',
        ]);

        $store = app(ReviewStore::class);
        $documentId = $this->seedDocument($store);

        $export = Mockery::mock(DocumentExportService::class);
        $export->shouldReceive('toPdf')->once()->andReturn('%PDF-1.7 esign-test');
        $export->shouldReceive('safeFilenameBase')->andReturn('กฎหมายทดสอบ');
        $this->app->instance(DocumentExportService::class, $export);

        $buu = Mockery::mock(BuuEsignService::class);
        $buu->shouldReceive('uploadAndSend')
            ->once()
            ->withArgs(function (
                string $absolutePath,
                string $originalExtension,
                string $ownerCitizenId,
                string $docName,
                array $signers,
                ?string $docId,
            ) use ($documentId) {
                return is_file($absolutePath)
                    && $originalExtension === 'pdf'
                    && $ownerCitizenId === '1111111111111'
                    && $docName === 'กฎหมายทดสอบ'
                    && $signers[0]['psn_citizenid'] === '2222222222222'
                    && $docId === $documentId;
            })
            ->andReturn([
                'minio_filename' => 'stored-abc.pdf',
                'esign' => ['status' => 'ok', 'result' => 'queued'],
                'return_url' => "https://example.test/api/esign/callback/{$documentId}",
            ]);
        $this->app->instance(BuuEsignService::class, $buu);

        $response = $this->postJson("/api/documents/{$documentId}/esign/send", [
            'signers' => [
                ['citizen_id' => '2222222222222', 'name' => 'ผู้ลงนามทดสอบ', 'note' => 'sandbox'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('minio_filename', 'stored-abc.pdf')
            ->assertJsonPath('bucket', 'buu-contract')
            ->assertJsonPath('owner_citizen_id', '1111111111111');

        $status = $store->getStatus($documentId);
        $this->assertSame('stored-abc.pdf', $status['esign_doc_filename'] ?? null);
        $this->assertSame('1111111111111', $status['esign_owner_citizenid'] ?? null);
        $this->assertNotNull($status['esign_submitted_at'] ?? null);
        $this->assertNotNull($status['esign_exported_at'] ?? null);
    }

    public function test_send_requires_signers(): void
    {
        $store = app(ReviewStore::class);
        $documentId = $this->seedDocument($store);

        $this->postJson("/api/documents/{$documentId}/esign/send", [
            'signers' => [],
        ])->assertStatus(422);
    }

    public function test_send_reuses_first_signer_as_owner_when_owner_unset(): void
    {
        config([
            'buu.default_bucket' => 'buu-contract',
            'buu.esign_owner_citizenid' => '',
            'buu.esign_callback_base_url' => 'https://example.test',
        ]);

        $store = app(ReviewStore::class);
        $documentId = $this->seedDocument($store);

        $export = Mockery::mock(DocumentExportService::class);
        $export->shouldReceive('toPdf')->once()->andReturn('%PDF-1.7 esign-test');
        $export->shouldReceive('safeFilenameBase')->andReturn('กฎหมายทดสอบ');
        $this->app->instance(DocumentExportService::class, $export);

        $buu = Mockery::mock(BuuEsignService::class);
        $buu->shouldReceive('uploadAndSend')
            ->once()
            ->withArgs(function (
                string $absolutePath,
                string $originalExtension,
                string $ownerCitizenId,
                string $docName,
                array $signers,
            ) {
                return $ownerCitizenId === '3210500467156'
                    && $signers[0]['psn_citizenid'] === '3210500467156';
            })
            ->andReturn([
                'minio_filename' => 'stored-same.pdf',
                'esign' => ['status' => 'ok'],
                'return_url' => "https://example.test/api/esign/callback/{$documentId}",
            ]);
        $this->app->instance(BuuEsignService::class, $buu);

        $this->postJson("/api/documents/{$documentId}/esign/send", [
            'signers' => [
                ['citizen_id' => '3210500467156', 'name' => 'ศ.ดร.สมพร ประธาน'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('owner_citizen_id', '3210500467156');
    }

    public function test_cancel_uses_persisted_minio_path(): void
    {
        $store = app(ReviewStore::class);
        $documentId = $this->seedDocument($store);
        $store->setStatus($documentId, [
            'esign_doc_filename' => 'stored-abc.pdf',
            'esign_owner_citizenid' => '1111111111111',
            'esign_bucket' => 'buu-contract',
        ]);

        $buu = Mockery::mock(BuuEsignService::class);
        $buu->shouldReceive('cancelDocumentSign')
            ->once()
            ->with('1111111111111', '1111111111111', 'stored-abc.pdf', 'buu-contract')
            ->andReturn(['status' => 'ok']);
        $this->app->instance(BuuEsignService::class, $buu);

        $this->postJson("/api/documents/{$documentId}/esign/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('minio_filename', 'stored-abc.pdf');

        $status = $store->getStatus($documentId);
        $this->assertSame('C', $status['esign_sign_status'] ?? null);
        $this->assertNotNull($status['esign_cancelled_at'] ?? null);
    }

    public function test_cancel_without_prior_submit_returns_422(): void
    {
        $store = app(ReviewStore::class);
        $documentId = $this->seedDocument($store);

        $this->postJson("/api/documents/{$documentId}/esign/cancel")
            ->assertStatus(422);
    }

    private function seedDocument(ReviewStore $store): string
    {
        $documentId = $store->generateDocumentId();

        $store->storeUpload(
            UploadedFile::fake()->create('ประกาศ esign.pdf', 64, 'application/pdf'),
            $documentId,
        );

        $store->setStatus($documentId, ['status' => 'done']);
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'ประกาศ esign.pdf',
            'source_type' => 'pdf',
            'language' => 'th',
            'summary' => [
                'page_count' => 1,
                'block_count' => 1,
                'review_required_count' => 0,
            ],
            'law_meta' => [
                'title' => 'กฎหมายทดสอบ',
            ],
            'pages' => [[
                'page_no' => 1,
                'blocks' => [[
                    'block_id' => 'b1',
                    'type' => 'paragraph',
                    'bbox' => null,
                    'reading_order' => 1,
                    'raw_text' => 'ข้อความทดสอบ',
                    'normalized_text' => '',
                    'ai_suggested_text' => '',
                    'approved_text' => 'ข้อความที่อนุมัติแล้ว',
                    'confidence' => 1,
                    'needs_review' => false,
                    'flags' => [],
                    'meta' => ['layout' => ['tabs' => []], 'formatting' => []],
                ]],
            ]],
            'document_review' => [
                'generated_html' => '',
                'draft_html' => '',
                'html_mode' => 'generated',
                'out_of_sync' => false,
            ],
        ]);

        return $documentId;
    }
}
