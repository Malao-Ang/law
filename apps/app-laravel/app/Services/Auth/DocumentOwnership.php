<?php

namespace App\Services\Auth;

use App\Services\MasterData\UnitStore;
use App\Services\Storage\MongoBlobStore;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentOwnership
{
    public const FIELDS = ['uploaded_by', 'responsible', 'owner_unit_code'];

    public function __construct(private readonly CurrentUser $current,
        private readonly UnitStore $units, private readonly MongoBlobStore $blobs) {}

    public function legalUnitCode(): string
    {
        foreach ($this->units->all() as $unit) {
            if ($unit['name'] === 'กองกฎหมาย') {
                return $unit['code'];
            }
        }
        throw new \RuntimeException('ไม่พบหน่วยงานกองกฎหมาย กรุณาเพิ่มข้อมูลหน่วยงานก่อน');
    }

    public function forUpload(Request $request): array
    {
        $user = $this->current->user($request);
        $active = $request->hasSession() ? $request->session()->get(CurrentUser::ACTIVE_UNIT_KEY) : null;
        $code = ($user['is_super_admin'] ?? false) ? $request->input('owner_unit_code', $active) : $active;
        if (! $code && ! config('auth_ldap.enforced')) {
            $code = $this->legalUnitCode();
        }
        $unit = is_string($code) ? $this->units->find($code) : null;
        abort_unless($unit && $unit['is_active'], 422, 'กรุณาเลือกหน่วยงานเจ้าของเอกสารที่เปิดใช้งาน');
        $person = $user ? ['username' => $user['username'], 'name' => $user['name'] ?? null] : null;

        return ['uploaded_by' => array_merge($person ?? ['username' => null, 'name' => null], ['at' => now()->toIso8601String()]),
            'responsible' => $person, 'owner_unit_code' => $code];
    }

    public function record(Request $request, string $id, string $action, array $before, mixed $after): void
    {
        $this->blobs->write('event', (string) Str::uuid(), [
            'actor' => $this->current->user($request)['username'] ?? null,
            'owner' => $before['responsible'] ?? null, 'document' => $id, 'action' => $action,
            'before' => $before, 'after' => $after, 'at' => now()->toIso8601String(),
        ]);
    }
}
