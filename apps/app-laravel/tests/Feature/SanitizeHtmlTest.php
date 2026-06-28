<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Tests\TestCase;

class SanitizeHtmlTest extends TestCase
{
    private function callSanitize(string $html): string
    {
        $store = app(ReviewStore::class);
        $ref = new \ReflectionMethod($store, 'sanitizeHtml');
        $ref->setAccessible(true);
        return $ref->invoke($store, $html);
    }

    public function test_preserves_margin_left_style(): void
    {
        $result = $this->callSanitize('<p style="margin-left: 24px">hello</p>');
        $this->assertStringContainsString('margin-left: 24px', $result);
    }

    public function test_strips_disallowed_style_props(): void
    {
        $result = $this->callSanitize('<p style="color: red; margin-left: 24px">text</p>');
        $this->assertStringNotContainsString('color: red', $result);
        $this->assertStringContainsString('margin-left: 24px', $result);
    }

    public function test_strips_event_handlers(): void
    {
        $result = $this->callSanitize('<p onclick="alert(1)">x</p>');
        $this->assertStringNotContainsString('onclick', $result);
    }

    public function test_preserves_class_attribute(): void
    {
        $result = $this->callSanitize('<p class="doc-paragraph">x</p>');
        $this->assertStringContainsString('class="doc-paragraph"', $result);
    }

    public function test_strips_disallowed_tags(): void
    {
        $result = $this->callSanitize('<p>text<script>bad()</script></p>');
        $this->assertStringNotContainsString('script', $result);
        $this->assertStringContainsString('text', $result);
    }

    public function test_preserves_colspan_rowspan(): void
    {
        $result = $this->callSanitize('<td colspan="2" rowspan="3">cell</td>');
        $this->assertStringContainsString('colspan="2"', $result);
        $this->assertStringContainsString('rowspan="3"', $result);
    }
}
