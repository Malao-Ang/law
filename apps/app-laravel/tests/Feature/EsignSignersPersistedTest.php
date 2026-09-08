<?php

namespace Tests\Feature;

use App\Services\Buu\BuuEsignService;
use App\Services\ReviewStore;
use Mockery;
use Tests\TestCase;

class EsignSignersPersistedTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_send_persists_signer_name_and_position(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-signers-'.uniqid();
        $store->setStatus($docId, [
            'status' => 'done',
            'document_id' => $docId,
            'esign_doc_filename' => 'file.pdf',
            'esign_bucket' => 'library.elaw.storage',
        ]);

        $buu = Mockery::mock(BuuEsignService::class);
        $buu->shouldReceive('callbackUrl')->andReturn("https://x/api/esign/callback/{$docId}");
        $buu->shouldReceive('sendDocumentSign')->once()->andReturn(['status' => 'ok']);
        $this->app->instance(BuuEsignService::class, $buu);

        $this->postJson("/api/documents/{$docId}/esign/send", [
            'signers' => [[
                'citizen_id' => '1234567890123',
                'name' => 'ศ.ดร. ทดสอบ ระบบ',
                'note' => 'อธิการบดี',
            ]],
        ])->assertOk();

        $signers = $store->getStatus($docId)['esign_signers'] ?? [];
        $this->assertNotEmpty($signers);
        $this->assertSame('ศ.ดร. ทดสอบ ระบบ', $signers[0]['name'] ?? null);
        $this->assertSame('อธิการบดี', $signers[0]['position'] ?? null);
    }
}
