<?php

namespace Tests\Feature;

use App\Jobs\ExtractDocumentJob;
use App\Services\ReviewStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    public function test_upload_queues_extraction_and_sets_status(): void
    {
        Queue::fake();

        $response = $this->post('/api/documents', [
            'file' => UploadedFile::fake()->create('thai-legal.pdf', 64, 'application/pdf'),
        ]);

        $response->assertStatus(202)->assertJsonStructure(['document_id', 'status']);

        $documentId = (string) $response->json('document_id');

        Queue::assertPushed(ExtractDocumentJob::class);

        $this->getJson('/api/documents/'.$documentId)
            ->assertOk()
            ->assertJson([
                'document_id' => $documentId,
                'status' => 'queued',
            ]);
    }

    public function test_upload_accepts_scan_extraction_mode_and_passes_it_to_job(): void
    {
        Queue::fake();

        $response = $this->post('/api/documents', [
            'file' => UploadedFile::fake()->create('scan.pdf', 64, 'application/pdf'),
            'scan_extraction_mode' => 'landingai',
        ]);

        $response->assertStatus(202)->assertJsonStructure(['document_id', 'status']);
        $documentId = (string) $response->json('document_id');

        Queue::assertPushed(ExtractDocumentJob::class, function (ExtractDocumentJob $job): bool {
            return $job->scanExtractionMode === 'landingai';
        });

        $this->getJson('/api/documents/'.$documentId)
            ->assertOk()
            ->assertJsonPath('scan_extraction_mode_requested', 'landingai');
    }

    public function test_generated_html_preserves_docx_layout_and_tables(): void
    {
        /** @var ReviewStore $store */
        $store = app(ReviewStore::class);

        $documentId = 'doc_test_docx_layout';
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'example.docx',
            'source_type' => 'docx',
            'language' => 'th',
            'summary' => [
                'page_count' => 1,
                'block_count' => 4,
                'review_required_count' => 0,
            ],
            'pages' => [[
                'page_no' => 1,
                'image_path' => null,
                'blocks' => [[
                    'block_id' => '1-1',
                    'type' => 'title',
                    'bbox' => null,
                    'reading_order' => 1,
                    'raw_text' => 'Announcement',
                    'normalized_text' => 'Announcement',
                    'ai_suggested_text' => 'Announcement',
                    'approved_text' => 'Announcement',
                    'confidence' => 0.99,
                    'needs_review' => false,
                    'flags' => [],
                    'meta' => [
                        'layout' => [
                            'bbox' => null,
                            'reading_order' => 1,
                            'alignment' => 'center',
                            'indent_left' => null,
                            'indent_first_line' => null,
                            'indent_hanging' => null,
                            'tabs' => [],
                        ],
                    ],
                ], [
                    'block_id' => '1-2',
                    'type' => 'paragraph',
                    'bbox' => null,
                    'reading_order' => 2,
                    'raw_text' => "Day\t21 March 2026",
                    'normalized_text' => "Day\t21 March 2026",
                    'ai_suggested_text' => "Day\t21 March 2026",
                    'approved_text' => "Day\t21 March 2026",
                    'confidence' => 0.99,
                    'needs_review' => false,
                    'flags' => [],
                    'meta' => [
                        'layout' => [
                            'bbox' => null,
                            'reading_order' => 2,
                            'alignment' => null,
                            'indent_left' => 720,
                            'indent_first_line' => 720,
                            'indent_hanging' => null,
                            'tabs' => [[
                                'align' => 'left',
                                'position' => 1440,
                            ]],
                        ],
                    ],
                ], [
                    'block_id' => '1-3',
                    'type' => 'table',
                    'bbox' => null,
                    'reading_order' => 3,
                    'raw_text' => "MERGED\tHEADER\nB1\tB2",
                    'normalized_text' => "MERGED\tHEADER\nB1\tB2",
                    'ai_suggested_text' => "MERGED\tHEADER\nB1\tB2",
                    'approved_text' => "MERGED\tHEADER\nB1\tB2",
                    'confidence' => 0.99,
                    'needs_review' => false,
                    'flags' => [],
                    'meta' => [
                        'table' => [
                            'headers' => ['MERGED', 'HEADER'],
                            'rows' => [['B1', 'B2']],
                            'cells' => [[
                                [
                                    'text' => 'MERGED',
                                    'colspan' => 1,
                                    'rowspan' => 2,
                                    'alignment' => null,
                                ],
                                [
                                    'text' => 'HEADER',
                                    'colspan' => 2,
                                    'rowspan' => 1,
                                    'alignment' => 'center',
                                ],
                            ], [
                                [
                                    'text' => 'B1',
                                    'colspan' => 1,
                                    'rowspan' => 1,
                                    'alignment' => null,
                                ],
                                [
                                    'text' => 'B2',
                                    'colspan' => 1,
                                    'rowspan' => 1,
                                    'alignment' => null,
                                ],
                            ]],
                        ],
                    ],
                ], [
                    'block_id' => '1-4',
                    'type' => 'table',
                    'bbox' => null,
                    'reading_order' => 4,
                    'raw_text' => "Legacy 1\tLegacy 2\nValue 1\tValue 2",
                    'normalized_text' => "Legacy 1\tLegacy 2\nValue 1\tValue 2",
                    'ai_suggested_text' => "Legacy 1\tLegacy 2\nValue 1\tValue 2",
                    'approved_text' => "Legacy 1\tLegacy 2\nValue 1\tValue 2",
                    'confidence' => 0.99,
                    'needs_review' => false,
                    'flags' => [],
                    'meta' => [
                        'table' => [
                            'headers' => ['Legacy 1', 'Legacy 2'],
                            'rows' => [['Value 1', 'Value 2']],
                        ],
                    ],
                ]],
            ]],
        ]);

        $review = $store->getReviewDocument($documentId);
        $generatedHtml = (string) $review['document_review']['generated_html'];

        $this->assertStringContainsString('text-align:center', $generatedHtml);
        $this->assertStringContainsString('margin-left:36pt', $generatedHtml);
        $this->assertStringContainsString('text-indent:36pt', $generatedHtml);
        $this->assertStringContainsString('class="doc-tab"', $generatedHtml);
        $this->assertStringContainsString('width:72pt', $generatedHtml);
        $this->assertStringContainsString('rowspan="2"', $generatedHtml);
        $this->assertStringContainsString('colspan="2"', $generatedHtml);
        $this->assertStringContainsString('Legacy 1', $generatedHtml);
        $this->assertStringContainsString('Value 2', $generatedHtml);
    }
    public function test_document_html_review_is_saved_and_used_for_export_and_ingest(): void
    {
        /** @var ReviewStore $store */
        $store = app(ReviewStore::class);

        $documentId = 'doc_test_flow';
        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'example.pdf',
            'source_type' => 'pdf_text',
            'language' => 'th',
            'summary' => [
                'page_count' => 1,
                'block_count' => 1,
                'review_required_count' => 1,
            ],
            'pages' => [[
                'page_no' => 1,
                'image_path' => null,
                'blocks' => [[
                    'block_id' => '1-1',
                    'type' => 'paragraph',
                    'bbox' => [20, 20, 400, 120],
                    'reading_order' => 1,
                    'raw_text' => 'old raw text',
                    'normalized_text' => 'normalized text',
                    'ai_suggested_text' => 'ai suggested text',
                    'approved_text' => 'approved text',
                    'confidence' => 0.7,
                    'needs_review' => true,
                    'flags' => ['low_confidence'],
                    'meta' => [
                        'reviewed_html' => '<p>approved text</p>',
                    ],
                ]],
            ]],
        ]);

        $this->patchJson('/api/documents/'.$documentId.'/blocks/1-1', [
            'page_no' => 1,
            'approved_text' => 'approved text',
            'mark_uncertain' => false,
            'type' => 'section_header',
            'reading_order' => 3,
            'bbox' => [32, 44, 420, 140],
            'reviewed_html' => '<h2>approved text</h2>',
        ])->assertOk()->assertJson(['status' => 'updated']);

        $this->putJson('/api/documents/'.$documentId.'/document-review', [
            'draft_html' => '<article class="doc-review-document"><section class="doc-page" data-page-no="1"><div class="doc-block" data-block-id="1-1" data-block-type="section_header" data-page-no="1" data-reading-order="3"><h2>final reviewed document text</h2></div></section></article>',
        ])->assertOk()->assertJson([
            'document_id' => $documentId,
            'status' => 'updated',
        ]);

        $exportResponse = $this->postJson('/api/documents/'.$documentId.'/export')
            ->assertOk()
            ->assertJson([
                'document_id' => $documentId,
                'status' => 'exported',
                'rag_status' => 'queued',
            ]);

        $review = $store->getReviewDocument($documentId);
        $this->assertSame('manual', $review['document_review']['html_mode']);
        $this->assertSame('section_header', $review['pages'][0]['blocks'][0]['type']);

        $exportPath = str_replace('storage/app/poc/', '', (string) $exportResponse->json('export_path'));
        $exportFile = $store->absolutePath($exportPath);
        $exportJson = json_decode((string) file_get_contents($exportFile), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('final reviewed document text', $exportJson['chunks'][0]['text']);
        $this->assertSame('section_header', $exportJson['chunks'][0]['meta']['type']);
        $this->assertStringContainsString('final reviewed document text', $exportJson['document_html']);

        $ingestFile = $store->absolutePath($store->ingestRelativePath($documentId));
        $this->assertFileExists($ingestFile);

        $status = $store->getStatus($documentId);
        $this->assertSame('ingested', $status['status']);
        $this->assertSame(1, $status['ingested_chunk_count']);
    }

    public function test_review_response_exposes_scan_page_image_metadata(): void
    {
        /** @var ReviewStore $store */
        $store = app(ReviewStore::class);

        $documentId = 'doc_test_scan_review';
        $pageDir = storage_path('app/poc/pages/'.$documentId);
        File::ensureDirectoryExists($pageDir);
        file_put_contents($pageDir.'/page-1-z1_5.png', 'fake-image');

        $store->writeReviewDocument($documentId, [
            'document_id' => $documentId,
            'source_file' => 'scan.pdf',
            'source_type' => 'pdf_scan',
            'language' => 'th',
            'summary' => [
                'page_count' => 1,
                'block_count' => 1,
                'review_required_count' => 0,
            ],
            'pages' => [[
                'page_no' => 1,
                'image_path' => '/data/poc/pages/'.$documentId.'/page-1-z1_5.png',
                'blocks' => [[
                    'block_id' => '1-1',
                    'type' => 'paragraph',
                    'bbox' => [10, 10, 100, 40],
                    'reading_order' => 1,
                    'raw_text' => 'scan text',
                    'normalized_text' => 'scan text',
                    'ai_suggested_text' => 'scan text',
                    'approved_text' => 'scan text',
                    'confidence' => 0.95,
                    'needs_review' => false,
                    'flags' => [],
                    'meta' => [
                        'layout' => [
                            'bbox' => [10, 10, 100, 40],
                            'reading_order' => 1,
                            'alignment' => null,
                            'indent_left' => null,
                            'indent_first_line' => null,
                            'indent_hanging' => null,
                            'tabs' => [],
                        ],
                    ],
                ]],
            ]],
        ]);

        $this->getJson('/api/documents/'.$documentId.'/review')
            ->assertOk()
            ->assertJsonPath('pages.0.source_kind', 'pdf_scan')
            ->assertJsonPath('pages.0.image_url', '/api/documents/'.$documentId.'/pages/1/image');

        $this->get('/api/documents/'.$documentId.'/pages/1/image')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }
}
