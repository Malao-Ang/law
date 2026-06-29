<?php

namespace Tests\Feature;

use App\Services\DocumentPipelineClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentPipelineClientNormalizeTest extends TestCase
{
    public function test_normalize_posts_blocks_and_returns_results(): void
    {
        Http::fake([
            '*/pipeline/normalize' => Http::response([
                'document_id' => 'doc_1',
                'results' => [
                    [
                        'block_id' => 'b1',
                        'normalized_text' => 'ราชการ',
                        'approved_text' => 'ราชการ',
                        'auto_corrected' => true,
                        'flags' => ['auto_corrected'],
                        'spell_suggestions' => [],
                    ],
                ],
            ], 200),
        ]);

        $client = app(DocumentPipelineClient::class);

        $result = $client->normalize(
            'doc_1',
            [['block_id' => 'b1', 'text' => 'ราชการง']],
            1.0,
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/pipeline/normalize')
                && $request['document_id'] === 'doc_1'
                && $request['blocks'][0]['block_id'] === 'b1'
                && $request['blocks'][0]['text'] === 'ราชการง'
                && $request['autocorrect_min_confidence'] === 1.0;
        });

        $this->assertSame('ราชการ', $result['results'][0]['approved_text']);
        $this->assertTrue($result['results'][0]['auto_corrected']);
    }
}
