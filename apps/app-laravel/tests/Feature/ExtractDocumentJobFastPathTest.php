<?php

namespace Tests\Feature;

use App\Jobs\CorrectDocumentJob;
use App\Jobs\ExtractDocumentJob;
use App\Jobs\NormalizeDocumentJob;
use App\Services\DocumentPipelineClient;
use App\Services\Fast\FastExtractionPipeline;
use App\Services\ReviewStore;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class ExtractDocumentJobFastPathTest extends TestCase
{
    public function test_fast_path_dispatches_normalize_not_correct(): void
    {
        Bus::fake([NormalizeDocumentJob::class, CorrectDocumentJob::class]);

        $documentId = 'doc_fast_wire';
        $store = app(ReviewStore::class);

        $pipeline = Mockery::mock(FastExtractionPipeline::class);
        $pipeline->shouldReceive('run')
            ->once()
            ->andReturn([
                'extraction' => ['path' => ['fast:php:docx'], 'conversion' => null],
                'timings' => ['fast_extract' => 12],
            ]);

        $job = new ExtractDocumentJob(
            documentId: $documentId,
            relativeFilePath: 'uploads/'.$documentId.'/sample.docx',
            enableAiCorrection: false,
            scanExtractionMode: 'auto',
            extractionEngine: 'fast',
        );

        $job->handle(
            app(DocumentPipelineClient::class),
            $store,
            $pipeline,
        );

        Bus::assertDispatched(NormalizeDocumentJob::class, function ($dispatched) use ($documentId) {
            return $dispatched->documentId === $documentId;
        });
        Bus::assertNotDispatched(CorrectDocumentJob::class);
    }
}
