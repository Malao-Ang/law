<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class WorkflowProgressTest extends TestCase
{
    private function seedDocument(ReviewStore $store, string $documentId): void
    {
        $store->setStatus($documentId, [
            'status' => 'done',
            'source_file' => 'workflow.docx',
        ]);

        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'workflow.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);
    }

    public function test_workflow_progress_endpoint_persists_status_fields(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'workflow_'.uniqid();
        $this->seedDocument($store, $documentId);

        $this->patchJson("/api/documents/{$documentId}/workflow-progress", [
            'completed_step' => 4,
        ])
            ->assertOk()
            ->assertJsonPath('workflow_completed_step', 4)
            ->assertJsonPath('workflow_current_step', 5);

        $status = $store->getStatus($documentId);
        $this->assertSame(4, $status['workflow_completed_step']);
        $this->assertSame(5, $status['workflow_current_step']);
        $this->assertNotEmpty($status['workflow_updated_at']);
    }

    public function test_workflow_progress_endpoint_rejects_invalid_step(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'workflow_bad_'.uniqid();
        $this->seedDocument($store, $documentId);

        $this->patchJson("/api/documents/{$documentId}/workflow-progress", [
            'completed_step' => 7,
        ])->assertStatus(422);
    }

    public function test_document_list_includes_workflow_progress_fields(): void
    {
        $store = app(ReviewStore::class);
        $documentId = 'workflow_list_'.uniqid();
        $this->seedDocument($store, $documentId);

        $store->setStatus($documentId, [
            'workflow_completed_step' => 3,
            'workflow_current_step' => 4,
            'workflow_updated_at' => now()->toIso8601String(),
        ]);

        $document = collect($this->getJson('/api/documents')->json('documents'))
            ->firstWhere('document_id', $documentId);

        $this->assertNotNull($document);
        $this->assertSame(3, $document['workflow_completed_step']);
        $this->assertSame(4, $document['workflow_current_step']);
        $this->assertNotEmpty($document['workflow_updated_at']);
    }
}
