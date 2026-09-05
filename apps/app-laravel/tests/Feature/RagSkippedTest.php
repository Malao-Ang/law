<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class RagSkippedTest extends TestCase
{
    public function test_can_set_and_clear_rag_skipped_flag(): void
    {
        $store = app(ReviewStore::class);
        $id = 'doc_test_'.uniqid();
        $store->setStatus($id, ['status' => 'done', 'progress' => 100, 'current_step' => 'done']);

        $this->patchJson("/api/documents/{$id}/rag-skip", ['rag_skipped' => true])
            ->assertOk()
            ->assertJsonPath('rag_skipped', true);
        $this->assertTrue($store->getStatus($id)['rag_skipped']);

        $this->patchJson("/api/documents/{$id}/rag-skip", ['rag_skipped' => false])
            ->assertOk()
            ->assertJsonPath('rag_skipped', false);
        $this->assertFalse($store->getStatus($id)['rag_skipped']);

        $store->deleteDocument($id);
    }

    public function test_rag_skipped_returns_404_for_missing_doc(): void
    {
        $this->patchJson('/api/documents/nonexistent_doc_xyz/rag-skip', ['rag_skipped' => true])
            ->assertNotFound();
    }
}
