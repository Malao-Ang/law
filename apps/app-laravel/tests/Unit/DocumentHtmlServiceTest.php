<?php

namespace Tests\Unit;

use App\Services\DocumentHtmlService;
use PHPUnit\Framework\TestCase;

class DocumentHtmlServiceTest extends TestCase
{
    private function makeService(): DocumentHtmlService
    {
        return new DocumentHtmlService();
    }

    /**
     * @param  array<string, mixed>  $formatting
     * @return array<string, mixed>
     */
    private function block(array $formatting, string $text = 'ข้อความ'): array
    {
        return [
            'block_id' => 'p1-b0001',
            'type' => 'paragraph',
            'reading_order' => 1,
            'approved_text' => $text,
            'normalized_text' => $text,
            'ai_suggested_text' => $text,
            'meta' => [
                'layout' => ['tabs' => []],
                'formatting' => $formatting,
            ],
        ];
    }

    public function test_bold_wraps_in_strong(): void
    {
        $html = $this->makeService()->buildBlockHtml(
            $this->block(['bold' => true, 'italic' => false, 'underline' => false]),
        );

        $this->assertStringContainsString('<strong>', $html);
    }

    public function test_italic_and_underline_wrap(): void
    {
        $html = $this->makeService()->buildBlockHtml(
            $this->block(['bold' => false, 'italic' => true, 'underline' => true]),
        );

        $this->assertStringContainsString('<em>', $html);
        $this->assertStringContainsString('<u>', $html);
    }

    public function test_no_formatting_has_no_wrappers(): void
    {
        $html = $this->makeService()->buildBlockHtml(
            $this->block(['bold' => false, 'italic' => false, 'underline' => false]),
        );

        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringNotContainsString('<em>', $html);
        $this->assertStringNotContainsString('<u>', $html);
    }

    public function test_image_block_html_carries_block_id_page_and_saved_width(): void
    {
        $service = $this->makeService();

        $block = [
            'block_id' => 'img-9',
            'type' => 'image',
            'meta' => ['image' => ['src_url' => '/api/x.png', 'display_width_px' => 320]],
        ];

        $html = $service->buildBlockHtml($block, 4);

        $this->assertStringContainsString('data-block-id="img-9"', $html);
        $this->assertStringContainsString('data-page-no="4"', $html);
        $this->assertStringContainsString('width:320px', $html);
    }

    public function test_image_block_html_without_saved_width_uses_responsive_default(): void
    {
        $service = $this->makeService();

        $block = [
            'block_id' => 'img-1',
            'type' => 'image',
            'meta' => ['image' => ['src_url' => '/api/x.png']],
        ];

        $html = $service->buildBlockHtml($block, 1);

        $this->assertStringContainsString('data-block-id="img-1"', $html);
        $this->assertStringContainsString('max-width:100%', $html);
    }
}
