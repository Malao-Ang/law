<?php

namespace Tests\Feature;

use Tests\TestCase;

class NormalizeConfigTest extends TestCase
{
    public function test_normalize_autocorrect_min_confidence_defaults_to_one(): void
    {
        $this->assertSame(1.0, (float) config('services.ocr.normalize_autocorrect_min_confidence'));
    }
}
