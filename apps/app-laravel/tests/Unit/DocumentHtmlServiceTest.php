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

    public function test_image_block_html_uses_display_width_cm_and_logo_spacing(): void
    {
        $html = $this->makeService()->buildBlockHtml([
            'block_id' => 'logo-1',
            'type' => 'image',
            'meta' => [
                'image' => [
                    'src_url' => '/api/logo.png',
                    'is_logo' => true,
                    'display_width_cm' => 2.99,
                    'alignment' => 'center',
                    'spacing_after_line_height' => 1.5,
                ],
            ],
        ], 1);

        $this->assertStringContainsString('text-align:center', $html);
        $this->assertStringContainsString('margin-bottom:1.5em', $html);
        $this->assertStringContainsString('width:2.99cm', $html);
    }

    public function test_image_block_html_uses_docx_width_cm_for_non_logo_images(): void
    {
        $html = $this->makeService()->buildBlockHtml([
            'block_id' => 'img-2',
            'type' => 'image',
            'meta' => [
                'image' => [
                    'src_url' => '/api/image.png',
                    'docx_width_cm' => 4.25,
                    'alignment' => 'right',
                ],
            ],
        ], 1);

        $this->assertStringContainsString('text-align:right', $html);
        $this->assertStringContainsString('width:4.25cm', $html);
        $this->assertStringNotContainsString('margin-bottom:1.5em', $html);
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

    public function test_build_layout_style_attribute_uses_first_tab_as_padding_left(): void
    {
        $style = $this->makeService()->buildLayoutStyleAttribute([
            'tabs' => [['position' => 1440, 'type' => 'left']],
        ]);

        $this->assertStringContainsString('padding-left:72pt', $style);
    }

    public function test_build_layout_style_attribute_clamps_line_height(): void
    {
        $service = $this->makeService();

        $this->assertStringContainsString('line-height:1.00', $service->buildLayoutStyleAttribute(['line_spacing' => 120]));
        $this->assertStringContainsString('line-height:1.50', $service->buildLayoutStyleAttribute(['line_spacing' => 360]));
        $this->assertStringContainsString('line-height:2.00', $service->buildLayoutStyleAttribute(['line_spacing' => 720]));
    }

    public function test_applyFormatting_wraps_font_size_pt_in_span(): void
    {
        $html = $this->makeService()->buildBlockHtml(
            $this->block(['bold' => false, 'italic' => false, 'underline' => false, 'font_size_pt' => 14.0]),
        );

        $this->assertStringContainsString('<span style="font-size: 14pt">', $html);
    }

    public function test_build_block_html_does_not_double_render_promoted_leading_tab(): void
    {
        $html = $this->makeService()->buildBlockHtml([
            'block_id' => 'p1-b0001',
            'type' => 'paragraph',
            'reading_order' => 1,
            'approved_text' => "\tข้อความ",
            'normalized_text' => "\tข้อความ",
            'ai_suggested_text' => "\tข้อความ",
            'meta' => [
                'layout' => [
                    'tabs' => [['position' => 1440, 'type' => 'left']],
                ],
                'formatting' => [],
            ],
        ]);

        $this->assertStringContainsString('padding-left:72pt', $html);
        $this->assertStringNotContainsString('class="doc-tab"', $html);
    }
}
