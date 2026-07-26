<?php

namespace Tests\Feature;

use Tests\TestCase;

class NormalizeConfigTest extends TestCase
{
    public function test_normalize_autocorrect_min_confidence_is_configured_at_0_85(): void
    {
        $this->assertSame(0.85, (float) config('services.ocr.normalize_autocorrect_min_confidence'));
    }
}
