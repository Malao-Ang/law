<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use App\Services\Search\LawSuggestService;
use Tests\TestCase;

class LawSuggestTest extends TestCase
{
    public function test_suggest_endpoint_returns_compact_results(): void
    {
        $fake = [
            'suggestions' => [[
                'law_id' => 'L1',
                'title' => 'พระราชบัญญัติภาษี',
                'law_type' => 'phrb',
                'agency' => 'กระทรวงการคลัง',
                'published_date' => '2562',
                'keywords' => ['ภาษีที่ดิน'],
            ]],
        ];

        $this->mock(LawSuggestService::class, fn ($mock) => $mock->shouldReceive('suggest')->once()->andReturn($fake));

        $this->postJson('/api/laws/suggest', ['q' => 'ภาษี'])
            ->assertOk()
            ->assertJsonPath('suggestions.0.law_id', 'L1')
            ->assertJsonPath('suggestions.0.keywords.0', 'ภาษีที่ดิน');
    }

    public function test_suggest_endpoint_falls_back_to_file_based_when_es_unavailable(): void
    {
        $this->mock(LawSuggestService::class, fn ($mock) => $mock->shouldReceive('suggest')->andThrow(new \RuntimeException('no route to host')));
        $this->mock(ReviewStore::class, fn ($mock) => $mock->shouldReceive('listLawMeta')->once()->andReturn([
            [
                'document_id' => 'doc_public',
                'title' => 'พระราชบัญญัติภาษีที่ดิน',
                'status' => 'ingested',
                'access_scope' => 'public',
                'law_type' => 'พระราชบัญญัติ',
                'meta_status' => 'มีผลใช้บังคับ',
                'change_status' => '',
                'signer_group' => '',
                'agencies' => ['กระทรวงการคลัง'],
                'law_groups' => ['ภาษี'],
                'promulgation_date' => '2562',
            ],
            [
                'document_id' => 'doc_private',
                'title' => 'พระราชบัญญัติภาษีลับ',
                'status' => 'ingested',
                'access_scope' => 'private',
                'law_type' => 'พระราชบัญญัติ',
                'meta_status' => '',
                'change_status' => '',
                'signer_group' => '',
                'agencies' => [],
                'law_groups' => [],
                'promulgation_date' => '',
            ],
        ]));

        $this->postJson('/api/laws/suggest', ['q' => 'ภาษี'])
            ->assertOk()
            ->assertJsonPath('suggestions.0.law_id', 'doc_public')
            ->assertJsonCount(1, 'suggestions');
    }

    public function test_suggest_endpoint_uses_file_fuzzy_when_es_returns_empty(): void
    {
        $this->mock(LawSuggestService::class, fn ($mock) => $mock->shouldReceive('suggest')->once()->andReturn(['suggestions' => []]));
        $this->mock(ReviewStore::class, fn ($mock) => $mock->shouldReceive('listLawMeta')->once()->andReturn([
            [
                'document_id' => 'doc_external_person',
                'title' => 'ระเบียบว่าด้วยการให้บริการบุคคลภายนอก',
                'status' => 'ingested',
                'access_scope' => 'public',
                'law_type' => 'ระเบียบ',
                'meta_status' => 'มีผลใช้บังคับ',
                'change_status' => '',
                'signer_group' => '',
                'agencies' => ['มหาวิทยาลัยบูรพา'],
                'law_groups' => ['บุคคลภายนอก'],
                'promulgation_date' => '2567',
            ],
            [
                'document_id' => 'doc_unrelated',
                'title' => 'ประกาศเรื่องกองทุนวิจัย',
                'status' => 'ingested',
                'access_scope' => 'public',
                'law_type' => 'ประกาศ',
                'meta_status' => '',
                'change_status' => '',
                'signer_group' => '',
                'agencies' => [],
                'law_groups' => ['วิจัย'],
                'promulgation_date' => '',
            ],
        ]));

        $this->postJson('/api/laws/suggest', ['q' => 'บุคคลภายนอห'])
            ->assertOk()
            ->assertJsonPath('suggestions.0.law_id', 'doc_external_person')
            ->assertJsonPath('suggestions.0.keywords.0', 'บุคคลภายนอก')
            ->assertJsonCount(1, 'suggestions');
    }
}
