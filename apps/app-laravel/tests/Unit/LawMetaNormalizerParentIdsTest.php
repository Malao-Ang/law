<?php

namespace Tests\Unit;

use App\Services\LawMetaNormalizer;
use PHPUnit\Framework\TestCase;

class LawMetaNormalizerParentIdsTest extends TestCase
{
    public function test_legacy_parent_document_id_is_promoted_to_array(): void
    {
        $this->assertSame(['doc-a'], LawMetaNormalizer::parentDocumentIds([
            'parent_document_id' => 'doc-a',
        ]));
    }

    public function test_parent_document_ids_are_preferred_and_deduplicated(): void
    {
        $this->assertSame(['doc-a', 'doc-b'], LawMetaNormalizer::parentDocumentIds([
            'parent_document_id' => 'doc-a',
            'parent_document_ids' => ['doc-a', ' doc-b ', '', 'doc-a'],
        ]));
    }

    public function test_empty_parent_document_ids_fall_back_to_legacy(): void
    {
        $this->assertSame(['doc-z'], LawMetaNormalizer::parentDocumentIds([
            'parent_document_id' => 'doc-z',
            'parent_document_ids' => [],
        ]));
    }
}
