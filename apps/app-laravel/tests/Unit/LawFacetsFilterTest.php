<?php

namespace Tests\Unit;

use App\Services\ReviewStore;
use Mockery;
use Tests\TestCase;

class LawFacetsFilterTest extends TestCase
{
    public function test_facets_only_count_ingested_public_docs(): void
    {
        $store = Mockery::mock(ReviewStore::class);
        $store->shouldReceive('listLawMeta')->andReturn([
            ['document_id' => 'a', 'status' => 'ingested', 'access_scope' => 'public',  'law_type' => 'prakat', 'meta_status' => '', 'change_status' => '', 'signer_group' => '', 'agencies' => [], 'law_groups' => [], 'promulgation_date' => ''],
            ['document_id' => 'b', 'status' => 'done',     'access_scope' => 'public',  'law_type' => 'rabiap', 'meta_status' => '', 'change_status' => '', 'signer_group' => '', 'agencies' => [], 'law_groups' => [], 'promulgation_date' => ''],
            ['document_id' => 'c', 'status' => 'ingested', 'access_scope' => 'private', 'law_type' => 'command','meta_status' => '', 'change_status' => '', 'signer_group' => '', 'agencies' => [], 'law_groups' => [], 'promulgation_date' => ''],
            ['document_id' => 'd', 'status' => 'failed',   'access_scope' => 'public',  'law_type' => 'phrb',   'meta_status' => '', 'change_status' => '', 'signer_group' => '', 'agencies' => [], 'law_groups' => [], 'promulgation_date' => ''],
        ]);

        $this->instance(ReviewStore::class, $store);

        $response = $this->getJson('/api/laws/facets');

        $response->assertStatus(200);
        $lawTypes = collect($response->json('law_type'));

        // Only doc 'a' passes: ingested + public
        $this->assertCount(1, $lawTypes);
        $this->assertEquals('prakat', $lawTypes->first()['value']);
        $this->assertEquals(1, $lawTypes->first()['count']);
    }
}
