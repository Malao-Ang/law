<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class DocumentListTest extends TestCase
{
    public function test_lists_uploaded_documents(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_list_'.uniqid();
        $store->setStatus($id, [
            'status' => 'done',
            'source_file' => 'ตัวอย่าง.docx',
            'extraction_engine' => 'fast',
            'scan_extraction_mode_effective' => 'gemini',
            'timings' => ['fast_extract' => 1.25, 'normalize' => 0.75],
            'error' => 'sample failure',
        ]);

        $response = $this->getJson('/api/documents');

        $response->assertOk();
        $response->assertJsonStructure(['documents' => [['document_id', 'title', 'status']]]);
        $found = collect($response->json('documents'))->firstWhere('document_id', $id);
        $this->assertNotNull($found);
        $this->assertSame('ตัวอย่าง.docx', $found['title']);
        $this->assertSame('fast', $found['extraction_engine']);
        $this->assertSame('gemini', $found['scan_mode']);
        $this->assertSame(['fast_extract' => 1.25, 'normalize' => 0.75], $found['timings']);
        $this->assertSame('sample failure', $found['error']);
    }
}
