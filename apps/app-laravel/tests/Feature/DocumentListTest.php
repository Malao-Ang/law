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
        ]);

        $response = $this->getJson('/api/documents');

        $response->assertOk();
        $response->assertJsonStructure(['documents' => [['document_id', 'title', 'status']]]);
        $found = collect($response->json('documents'))->firstWhere('document_id', $id);
        $this->assertNotNull($found);
        $this->assertSame('ตัวอย่าง.docx', $found['title']);
    }
}
