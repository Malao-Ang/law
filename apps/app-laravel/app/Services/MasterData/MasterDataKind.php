<?php

namespace App\Services\MasterData;

enum MasterDataKind: string
{
    case EnforcementStatus = 'enforcement_status';
    case LawFamily = 'law_family';
    case LawType = 'law_type';
    case Issuer = 'issuer';

    public function prefix(): string
    {
        return match ($this) {
            self::EnforcementStatus => 'STA',
            self::LawFamily => 'LFM',
            self::LawType => 'LTY',
            self::Issuer => 'ISS',
        };
    }

    public function codePad(): int
    {
        return 2;
    }

    public function deletable(): bool
    {
        return false;
    }

    public function label(): string
    {
        return match ($this) {
            self::EnforcementStatus => 'สถานะการบังคับใช้',
            self::LawFamily => 'กลุ่มประเภท',
            self::LawType => 'ประเภทเอกสาร',
            self::Issuer => 'ผู้ออกประกาศ',
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
            self::LawFamily => [
                [
                    'code' => 'LFM01',
                    'name' => 'ข้อบังคับ',
                    'description' => '',
                    'aliases' => ['ข้อบังคับ'],
                    'attrs' => ['source' => 'internal', 'color' => '#10B981'],
                ],
                [
                    'code' => 'LFM02',
                    'name' => 'ระเบียบ',
                    'description' => '',
                    'aliases' => ['ระเบียบ'],
                    'attrs' => ['source' => 'internal', 'color' => '#3B82F6'],
                ],
                [
                    'code' => 'LFM03',
                    'name' => 'ประกาศ',
                    'description' => '',
                    'aliases' => ['ประกาศ'],
                    'attrs' => ['source' => 'internal', 'color' => '#FB923C'],
                ],
                [
                    'code' => 'LFM04',
                    'name' => 'กฎหมายภายนอก',
                    'description' => '',
                    'aliases' => ['กฎหมายภายนอก'],
                    'attrs' => ['source' => 'external', 'color' => '#854D0E'],
                ],
            ],
            self::Issuer => [
                [
                    'code' => 'ISS01',
                    'name' => 'มหาวิทยาลัย',
                    'description' => '',
                    'aliases' => ['มหาวิทยาลัย'],
                    'attrs' => [
                        'legacy_type_aliases' => [
                            'ประกาศที่ออกโดยมหาวิทยาลัย',
                            'คำสั่ง',
                        ],
                    ],
                ],
                [
                    'code' => 'ISS02',
                    'name' => 'สภามหาวิทยาลัย',
                    'description' => '',
                    'aliases' => ['สภามหาวิทยาลัย'],
                    'attrs' => [
                        'legacy_type_aliases' => [
                            'ประกาศที่ออกโดยสภามหาวิทยาลัย',
                            'มติ',
                        ],
                    ],
                ],
            ],
            self::LawType => [
                [
                    'code' => 'LTY01',
                    'name' => 'ประกาศ',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ประกาศ', 'ประกาศที่ออกโดยมหาวิทยาลัย', 'ประกาศที่ออกโดยสภามหาวิทยาลัย', 'คำสั่ง', 'มติ'],
                    'attrs' => ['family_code' => 'LFM03', 'requires_issuer' => true],
                ],
                [
                    'code' => 'LTY02',
                    'name' => 'ระเบียบ',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ระเบียบ'],
                    'attrs' => ['family_code' => 'LFM02', 'requires_issuer' => false],
                ],
                [
                    'code' => 'LTY03',
                    'name' => 'ข้อบังคับ',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ข้อบังคับ'],
                    'attrs' => ['family_code' => 'LFM01', 'requires_issuer' => false],
                ],
                [
                    'code' => 'LTY04',
                    'name' => 'พระราชกำหนด',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['พระราชกำหนด', 'พ.ร.ก.'],
                    'attrs' => ['family_code' => 'LFM04', 'requires_issuer' => false],
                ],
                [
                    'code' => 'LTY05',
                    'name' => 'พระราชบัญญัติ',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['พระราชบัญญัติ', 'พ.ร.บ.', 'พรบ'],
                    'attrs' => ['family_code' => 'LFM04', 'requires_issuer' => false],
                ],
                [
                    'code' => 'LTY06',
                    'name' => 'กฎกระทรวง',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['กฎกระทรวง'],
                    'attrs' => ['family_code' => 'LFM04', 'requires_issuer' => false],
                ],
                [
                    'code' => 'LTY07',
                    'name' => 'ประกาศกระทรวง',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ประกาศกระทรวง'],
                    'attrs' => ['family_code' => 'LFM04', 'requires_issuer' => false],
                ],
                [
                    'code' => 'LTY08',
                    'name' => 'กฎหมายภายนอกอื่น ๆ',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['กฎหมายภายนอก'],
                    'attrs' => ['family_code' => 'LFM04', 'requires_issuer' => false],
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
            self::LawFamily => [
                'attrs.source' => ['required', 'string', 'in:internal,external'],
                'attrs.color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            ],
            self::LawType => [
                'attrs.family_code' => ['required', 'string', 'max:32'],
                'attrs.requires_issuer' => ['nullable', 'boolean'],
            ],
            self::Issuer => [],
        };
    }
}
