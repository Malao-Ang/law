<?php

namespace App\Services\MasterData;

enum MasterDataKind: string
{
    case EnforcementStatus = 'enforcement_status';

    public function prefix(): string
    {
        return match ($this) {
            self::EnforcementStatus => 'STA',
        };
    }

    public function codePad(): int
    {
        return match ($this) {
            self::EnforcementStatus => 2,
        };
    }

    public function deletable(): bool
    {
        return match ($this) {
            self::EnforcementStatus => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::EnforcementStatus => 'สถานะการบังคับใช้',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function seed(): array
    {
        return match ($this) {
            self::EnforcementStatus => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function attrRules(): array
    {
        return match ($this) {
            self::EnforcementStatus => [
                'attrs.color' => ['nullable', 'string', 'in:success,error,grey,info,warning'],
            ],
        };
    }
}
