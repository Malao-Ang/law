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

    public function test_upload_puts_file_without_sending_esign(): void
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
        $buu->shouldReceive('callbackUrl')->andReturn("https://example.test/api/esign/callback/{$documentId}");
        $buu->shouldReceive('uploadPdf')
            ->once()
            ->withArgs(function (
                string $absolutePath,
                string $originalExtension,
                ?string $bucket,
                string $folderPath,
                bool $qrVerify,
            ) {
                return is_file($absolutePath)
                    && $originalExtension === 'pdf'
                    && $bucket === 'buu-contract'
                    && $folderPath === '/'
                    && $qrVerify === true;
            })
            ->andReturn('stored-abc.pdf');
        $buu->shouldReceive('sendDocumentSign')->never();
        $buu->shouldReceive('uploadAndSend')->never();
        $this->app->instance(BuuEsignService::class, $buu);

        $response = $this->postJson("/api/documents/{$documentId}/esign/upload", [
            'signers' => [
                ['citizen_id' => '2222222222222', 'name' => 'ผู้ลงนามทดสอบ', 'note' => 'sandbox'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'uploaded')
            ->assertJsonPath('minio_filename', 'stored-abc.pdf')
            ->assertJsonPath('bucket', 'buu-contract')
            ->assertJsonPath('owner_citizen_id', '1111111111111');

        $status = $store->getStatus($documentId);
        $this->assertSame('stored-abc.pdf', $status['esign_doc_filename'] ?? null);
        $this->assertSame('1111111111111', $status['esign_owner_citizenid'] ?? null);
        $this->assertNotNull($status['esign_exported_at'] ?? null);
        $this->assertNotNull($status['esign_uploaded_at'] ?? null);
        $this->assertNull($status['esign_submitted_at'] ?? null);
    }

    public function test_send_calls_esign_after_upload(): void
    {
        config([
            'buu.default_bucket' => 'buu-contract',
            'buu.esign_callback_base_url' => 'https://example.test',
        ]);

        $store = app(ReviewStore::class);
        $documentId = $this->seedDocument($store);
        $store->setStatus($documentId, [
            'esign_doc_filename' => 'stored-abc.pdf',
            'esign_bucket' => 'buu-contract',
            'esign_owner_citizenid' => '1111111111111',
            'esign_doc_name' => 'กฎหมายทดสอบ',
            'esign_return_url' => "https://example.test/api/esign/callback/{$documentId}",
            'esign_return_type' => 'L',
            'esign_signers' => [['psn_citizenid' => '2222222222222']],
        ]);

        $buu = Mockery::mock(BuuEsignService::class);
        $buu->shouldReceive('uploadPdf')->never();
        $buu->shouldReceive('sendDocumentSign')
            ->once()
            ->andReturn(['status' => 'ok', 'result' => 'queued']);
        $this->app->instance(BuuEsignService::class, $buu);

        $this->postJson("/api/documents/{$documentId}/esign/send")
            ->assertOk()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('minio_filename', 'stored-abc.pdf')
            ->assertJsonPath('esign.status', 'ok');

        $status = $store->getStatus($documentId);
        $this->assertNotNull($status['esign_submitted_at'] ?? null);
    }

    public function test_upload_requires_signers(): void
    {
        $store = app(ReviewStore::class);
        $documentId = $this->seedDocument($store);

        $this->postJson("/api/documents/{$documentId}/esign/upload", [
            'signers' => [],
        ])->assertStatus(422);
    }

    public function test_send_without_prior_upload_returns_422(): void
    {
        $store = app(ReviewStore::class);
        $documentId = $this->seedDocument($store);

        $this->postJson("/api/documents/{$documentId}/esign/send")
            ->assertStatus(422);
    }

    public function test_upload_reuses_first_signer_as_owner_when_owner_unset(): void
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
        $buu->shouldReceive('callbackUrl')->andReturn("https://example.test/api/esign/callback/{$documentId}");
        $buu->shouldReceive('uploadPdf')->once()->andReturn('stored-same.pdf');
        $buu->shouldReceive('sendDocumentSign')->never();
        $this->app->instance(BuuEsignService::class, $buu);

        $this->postJson("/api/documents/{$documentId}/esign/upload", [
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
