<?php

namespace App\Services\Fast;

use RuntimeException;

class FastPathUnsupportedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $detectedType,
    ) {
        parent::__construct("Fast path unsupported: {$reason} (detected: {$detectedType})");
    }
}
