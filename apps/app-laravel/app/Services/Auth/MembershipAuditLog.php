<?php

namespace App\Services\Auth;

use App\Services\Storage\MongoBlobStore;
use Illuminate\Support\Str;

class MembershipAuditLog
{
    public function __construct(private readonly MongoBlobStore $blobs) {}

    public function record(string $who, string $what, string $target, ?string $unit, mixed $before, mixed $after): void
    {
        $this->blobs->write('event', (string) Str::uuid(), [
            'who' => $who, 'what' => $what, 'target' => $target, 'unit_code' => $unit,
            'before' => $before, 'after' => $after, 'at' => now()->toIso8601String(),
        ]);
    }
}
