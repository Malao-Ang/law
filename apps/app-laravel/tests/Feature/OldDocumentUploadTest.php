<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class OldDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_document_upload_dispatches_no_ocr_job(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('old.pdf', 10, 'application/pdf'),
            'document_type' => 'old',
        ]);

        $response->assertStatus(202)->assertJsonPath('status', 'done');
        Bus::assertNothingDispatched();
    }
}
