<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class WorkflowPublishEsignGuardTest extends TestCase
{
    public function test_new_doc_cannot_complete_step6_without_signed_callback(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-wf-guard-unsigned-'.uniqid();
        $store->setStatus($docId, [
            'status' => 'done',
            'document_id' => $docId,
            'esign_submitted_at' => now()->toIso8601String(),
            'esign_sign_status' => null,       // NOT signed
            'esign_confirmed_at' => null,
        ]);
        // Mark as a NEW document (default), no law_meta.document_type = 'old'.

        $this->patchJson("/api/documents/{$docId}/workflow-progress", [
            'completed_step' => 6,
        ])->assertStatus(422);

        $after = $store->getStatus($docId);
        $this->assertNull($after['esign_confirmed_at'] ?? null, 'confirmed_at must NOT be forged');
        $this->assertNotSame('ingested', $after['status'] ?? null, 'status must NOT be published');
    }

    public function test_new_doc_completes_step6_when_signed(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-wf-guard-signed-'.uniqid();
        $store->setStatus($docId, [
            'status' => 'done',
            'document_id' => $docId,
            'esign_submitted_at' => now()->toIso8601String(),
            'esign_sign_status' => 'Y',        // signed
            'esign_confirmed_at' => now()->toIso8601String(),
        ]);

        $this->patchJson("/api/documents/{$docId}/workflow-progress", [
            'completed_step' => 6,
        ])->assertOk();

        $this->assertSame('ingested', $store->getStatus($docId)['status'] ?? null);
    }

    public function test_step_below_6_is_unaffected(): void
    {
        $store = app(ReviewStore::class);
        $docId = 'test-wf-guard-step5-'.uniqid();
        $store->setStatus($docId, [
            'status' => 'done',
            'document_id' => $docId,
            'esign_sign_status' => null,
        ]);

        $this->patchJson("/api/documents/{$docId}/workflow-progress", [
            'completed_step' => 5,
        ])->assertOk();
    }
}
