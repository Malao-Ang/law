<?php

namespace App\Services\Auth;

use App\Services\Storage\MongoBlobStore;
use Illuminate\Support\Str;

class MembershipAuditLog
{
    public function __construct(private readonly MongoBlobStore $blobs) {}

    public function record(string $who, string $what, string $target, ?string $unit, mixed $before, mixed $after): void
    {
        $action = str_contains($what, 'remove') ? 'delete' : (str_contains($what, 'add') ? 'create' : 'update');
        app(\App\Services\Audit\AuditLogger::class)->record($action, str_starts_with($what, 'super_admin') ? 'user' : 'membership',
            $target, str_starts_with($what, 'super_admin') ? 'เปลี่ยนสิทธิ์ผู้ดูแลระบบสูงสุด' : 'เปลี่ยนสมาชิกหน่วยงาน', [], $target, null, ['unit_code' => $unit, 'before' => (array) $before, 'after' => (array) $after]);
        $this->blobs->write('event', (string) Str::uuid(), [
            'who' => $who, 'what' => $what, 'target' => $target, 'unit_code' => $unit,
            'before' => $before, 'after' => $after, 'at' => now()->toIso8601String(),
        ]);
    }
}
