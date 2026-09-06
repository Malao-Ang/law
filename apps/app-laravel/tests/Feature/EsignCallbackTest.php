<?php

namespace Tests\Feature;

use App\Services\Buu\BuuEsignService;
use App\Services\ReviewStore;
use Tests\TestCase;

class EsignCallbackTest extends TestCase
{
    public function test_callback_url_uses_configured_base(): void
    {
        config([
            'buu.esign_callback_base_url' => 'https://example.test',
            'buu.esign_return_url' => '',
        ]);

        $url = app(BuuEsignService::class)->callbackUrl('doc_abc_123');

        $this->assertSame('https://example.test/api/esign/callback/doc_abc_123', $url);
    }

    public function test_callback_url_uses_exact_return_url_when_set(): void
    {
        config([
            'buu.esign_callback_base_url' => 'https://example.test',
            'buu.esign_return_url' => 'https://service-api-dev.buu.ac.th/api/getTestApiPost',
        ]);

        $url = app(BuuEsignService::class)->callbackUrl('doc_abc_123');

        $this->assertSame('https://service-api-dev.buu.ac.th/api/getTestApiPost', $url);
    }

    public function test_esign_callback_records_approval(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-esign-cb-'.uniqid();
        $store->setStatus($docId, ['status' => 'done', 'document_id' => $docId]);

        $response = $this->postJson("/api/esign/callback/{$docId}", [
            'response' => '1',
            'sign_status' => 'Y',
            'sign_message' => '',
            'doc_name' => 'ชุดแรก',
            'doc_filename' => 'abc123.pdf',
            'signer_citizenid' => '1234567890123',
        ]);

        $response->assertOk()->assertJson(['status' => 'success']);

        $status = $store->getStatus($docId);
        $this->assertSame('Y', $status['esign_sign_status'] ?? null);
        $this->assertSame('abc123.pdf', $status['esign_doc_filename'] ?? null);
        $this->assertSame('1234567890123', $status['esign_last_signer_citizenid'] ?? null);
        $this->assertNotNull($status['esign_signed_at'] ?? null);
        $this->assertCount(1, $status['esign_callbacks'] ?? []);
    }

    public function test_esign_callback_records_rejection(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-esign-rej-'.uniqid();
        $store->setStatus($docId, ['status' => 'done', 'document_id' => $docId]);

        $response = $this->postJson("/api/esign/callback/{$docId}", [
            'sign_status' => 'N',
            'sign_message' => 'เอกสารไม่ครบ',
            'doc_name' => 'ชุดสอง',
            'doc_filename' => 'def456.pdf',
            'signer_citizenid' => '9876543210987',
        ]);

        $response->assertOk();

        $status = $store->getStatus($docId);
        $this->assertSame('N', $status['esign_sign_status'] ?? null);
        $this->assertSame('เอกสารไม่ครบ', $status['esign_sign_message'] ?? null);
        $this->assertNotNull($status['esign_rejected_at'] ?? null);
    }

    public function test_esign_callback_unknown_document_still_returns_success(): void
    {
        $this->postJson('/api/esign/callback/doc_missing_xyz', [
            'sign_status' => 'Y',
        ])->assertOk()->assertJson(['status' => 'success']);
    }

    public function test_esign_callback_accepts_get_probe(): void
    {
        $this->getJson('/api/esign/callback/doc_missing_xyz')
            ->assertOk()
            ->assertJson(['status' => 'success']);
    }
}
