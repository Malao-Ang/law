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
            self::EnforcementStatus => [
                [
                    'code' => 'STA01',
                    'name' => 'มีผลบังคับใช้',
                    'description' => '',
                    'aliases' => ['มีผลบังคับใช้'],
                    'attrs' => ['role' => 'in_force', 'color' => 'success'],
                ],
                [
                    'code' => 'STA02',
                    'name' => 'ยกเลิกการใช้งาน',
                    'description' => '',
                    'aliases' => ['ยกเลิกการใช้งาน'],
                    'attrs' => ['role' => 'repealed', 'color' => 'error'],
                ],
                [
                    'code' => 'STA03',
                    'name' => 'ร่าง',
                    'description' => '',
                    'aliases' => ['ร่าง', ''],
                    'attrs' => ['role' => 'draft', 'color' => 'grey'],
                ],
            ],
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
