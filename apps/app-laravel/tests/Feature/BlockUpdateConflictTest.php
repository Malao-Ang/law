<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use App\Services\Storage\ConcurrencyException;
use RuntimeException;
use Tests\TestCase;

class BlockUpdateConflictTest extends TestCase
{
    public function test_update_returns_409_on_concurrency_exception(): void
    {
        $this->mock(ReviewStore::class, function ($mock): void {
            $mock->shouldReceive('patchApprovedBlock')
                ->once()
                ->andThrow(new ConcurrencyException('contention'));
        });

        $response = $this->patchJson('/api/documents/doc-conflict-test/blocks/b1', [
            'page_no' => 1,
            'approved_text' => 'hello',
            'mark_uncertain' => false,
        ]);

        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => 'กำลังบันทึกอยู่ กรุณาลองใหม่อีกครั้ง']);
    }

    public function test_update_returns_404_on_not_found_runtime_exception(): void
    {
        $this->mock(ReviewStore::class, function ($mock): void {
            $mock->shouldReceive('patchApprovedBlock')
                ->once()
                ->andThrow(new RuntimeException('Block not found.'));
        });

        $response = $this->patchJson('/api/documents/doc-conflict-test/blocks/b1', [
            'page_no' => 1,
            'approved_text' => 'hello',
            'mark_uncertain' => false,
        ]);

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'Block not found.']);
    }
}
