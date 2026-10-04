<?php

namespace App\Services\MasterData;

enum MasterDataKind: string
{
    private const FAMILY_ORDINANCE = "\u{0E02}\u{0E49}\u{0E2D}\u{0E1A}\u{0E31}\u{0E07}\u{0E04}\u{0E31}\u{0E1A}";
    private const FAMILY_REGULATION = "\u{0E23}\u{0E30}\u{0E40}\u{0E1A}\u{0E35}\u{0E22}\u{0E1A}";
    private const FAMILY_ANNOUNCEMENT = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}";
    private const FAMILY_EXTERNAL = "\u{0E01}\u{0E0E}\u{0E2B}\u{0E21}\u{0E32}\u{0E22}\u{0E20}\u{0E32}\u{0E22}\u{0E19}\u{0E2D}\u{0E01}";
    private const TYPE_ANNOUNCEMENT_UNIVERSITY = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E17}\u{0E35}\u{0E48}\u{0E2D}\u{0E2D}\u{0E01}\u{0E42}\u{0E14}\u{0E22}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}";
    private const TYPE_COMMAND = "\u{0E04}\u{0E33}\u{0E2A}\u{0E31}\u{0E48}\u{0E07}";
    private const TYPE_ANNOUNCEMENT_COUNCIL = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E17}\u{0E35}\u{0E48}\u{0E2D}\u{0E2D}\u{0E01}\u{0E42}\u{0E14}\u{0E22}\u{0E2A}\u{0E20}\u{0E32}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}";
    private const TYPE_RESOLUTION = "\u{0E21}\u{0E15}\u{0E34}";
    private const TYPE_DECREE = "\u{0E1E}\u{0E23}\u{0E30}\u{0E23}\u{0E32}\u{0E0A}\u{0E01}\u{0E33}\u{0E2B}\u{0E19}\u{0E14}";
    private const TYPE_DECREE_ABBR = "\u{0E1E}.\u{0E23}.\u{0E01}.";
    private const TYPE_ACT = "\u{0E1E}\u{0E23}\u{0E30}\u{0E23}\u{0E32}\u{0E0A}\u{0E1A}\u{0E31}\u{0E0D}\u{0E0D}\u{0E31}\u{0E15}\u{0E34}";
    private const TYPE_ACT_ABBR = "\u{0E1E}.\u{0E23}.\u{0E1A}.";
    private const TYPE_ACT_ABBR_COMPACT = "\u{0E1E}\u{0E23}\u{0E1A}";
    private const TYPE_MINISTERIAL_RULE = "\u{0E01}\u{0E0E}\u{0E01}\u{0E23}\u{0E30}\u{0E17}\u{0E23}\u{0E27}\u{0E07}";
    private const TYPE_MINISTERIAL_ANNOUNCEMENT = "\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}\u{0E01}\u{0E23}\u{0E30}\u{0E17}\u{0E23}\u{0E27}\u{0E07}";
    private const TYPE_EXTERNAL_OTHER = "\u{0E01}\u{0E0E}\u{0E2B}\u{0E21}\u{0E32}\u{0E22}\u{0E20}\u{0E32}\u{0E22}\u{0E19}\u{0E2D}\u{0E01}\u{0E2D}\u{0E37}\u{0E48}\u{0E19} \u{0E46}";

    case EnforcementStatus = 'enforcement_status';
    case LawFamily = 'law_family';
    case LawType = 'law_type';
    case LawCategory = 'law_category';

    public function prefix(): string
    {
        return match ($this) {
            self::EnforcementStatus => 'STA',
            self::LawFamily => 'LFM',
            self::LawType => 'LTY',
            self::LawCategory => 'DCT',
        };
    }

    public function codePad(): int
    {
        return $this === self::LawCategory ? 3 : 2;
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
            self::LawCategory => 'หมวดเอกสาร',
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
                    'name' => self::FAMILY_ORDINANCE,
                    'description' => '',
                    'aliases' => [self::FAMILY_ORDINANCE],
                    'attrs' => ['source' => 'internal', 'color' => '#10B981'],
                ],
                [
                    'code' => 'LFM02',
                    'name' => self::FAMILY_REGULATION,
                    'description' => '',
                    'aliases' => [self::FAMILY_REGULATION],
                    'attrs' => ['source' => 'internal', 'color' => '#3B82F6'],
                ],
                [
                    'code' => 'LFM03',
                    'name' => self::FAMILY_ANNOUNCEMENT,
                    'description' => '',
                    'aliases' => [self::FAMILY_ANNOUNCEMENT],
                    'attrs' => ['source' => 'internal', 'color' => '#FB923C'],
                ],
                [
                    'code' => 'LFM04',
                    'name' => self::FAMILY_EXTERNAL,
                    'description' => '',
                    'aliases' => [self::FAMILY_EXTERNAL],
                    'attrs' => ['source' => 'external', 'color' => '#854D0E'],
                ],
            ],
            self::LawType => [
                [
                    'code' => 'LTY01',
                    'name' => self::TYPE_ANNOUNCEMENT_UNIVERSITY,
                    'description' => '',
                    'is_system' => false,
                    'aliases' => [self::TYPE_ANNOUNCEMENT_UNIVERSITY, self::TYPE_COMMAND],
                    'attrs' => ['family_code' => 'LFM03'],
                ],
                [
                    'code' => 'LTY09',
                    'name' => self::TYPE_ANNOUNCEMENT_COUNCIL,
                    'description' => '',
                    'is_system' => false,
                    'sort_order' => 2,
                    'aliases' => [self::TYPE_ANNOUNCEMENT_COUNCIL, self::TYPE_RESOLUTION],
                    'attrs' => ['family_code' => 'LFM03'],
                ],
                [
                    'code' => 'LTY02',
                    'name' => self::FAMILY_REGULATION,
                    'description' => '',
                    'is_system' => false,
                    'sort_order' => 3,
                    'aliases' => [self::FAMILY_REGULATION],
                    'attrs' => ['family_code' => 'LFM02'],
                ],
                [
                    'code' => 'LTY03',
                    'name' => self::FAMILY_ORDINANCE,
                    'description' => '',
                    'is_system' => false,
                    'sort_order' => 4,
                    'aliases' => [self::FAMILY_ORDINANCE],
                    'attrs' => ['family_code' => 'LFM01'],
                ],
                [
                    'code' => 'LTY04',
                    'name' => self::TYPE_DECREE,
                    'description' => '',
                    'is_system' => false,
                    'sort_order' => 5,
                    'aliases' => [self::TYPE_DECREE, self::TYPE_DECREE_ABBR],
                    'attrs' => ['family_code' => 'LFM04'],
                ],
                [
                    'code' => 'LTY05',
                    'name' => self::TYPE_ACT,
                    'description' => '',
                    'is_system' => false,
                    'sort_order' => 6,
                    'aliases' => [self::TYPE_ACT, self::TYPE_ACT_ABBR, self::TYPE_ACT_ABBR_COMPACT],
                    'attrs' => ['family_code' => 'LFM04'],
                ],
                [
                    'code' => 'LTY06',
                    'name' => self::TYPE_MINISTERIAL_RULE,
                    'description' => '',
                    'is_system' => false,
                    'sort_order' => 7,
                    'aliases' => [self::TYPE_MINISTERIAL_RULE],
                    'attrs' => ['family_code' => 'LFM04'],
                ],
                [
                    'code' => 'LTY07',
                    'name' => self::TYPE_MINISTERIAL_ANNOUNCEMENT,
                    'description' => '',
                    'is_system' => false,
                    'sort_order' => 8,
                    'aliases' => [self::TYPE_MINISTERIAL_ANNOUNCEMENT],
                    'attrs' => ['family_code' => 'LFM04'],
                ],
                [
                    'code' => 'LTY08',
                    'name' => self::TYPE_EXTERNAL_OTHER,
                    'description' => '',
                    'is_system' => false,
                    'sort_order' => 9,
                    'aliases' => [self::FAMILY_EXTERNAL],
                    'attrs' => ['family_code' => 'LFM04'],
                ],
            ],
            self::LawCategory => [
                [
                    'code' => 'DCT001',
                    'name' => 'ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ด้านวิชาการ การผลิตบัณฑิต การเรียนรู้ตลอดชีวิต และการบริหารหลักสูตร', 'academic'],
                    'attrs' => [],
                ],
                [
                    'code' => 'DCT002',
                    'name' => 'ด้านกิจการนิสิต',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ด้านกิจการนิสิต', 'student-affairs'],
                    'attrs' => [],
                ],
                [
                    'code' => 'DCT003',
                    'name' => 'ด้านการวิจัย นวัตกรรม และการนำไปใช้ประโยชน์',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ด้านการวิจัย นวัตกรรม และการนำไปใช้ประโยชน์', 'research-innovation'],
                    'attrs' => [],
                ],
                [
                    'code' => 'DCT004',
                    'name' => 'ด้านบริการวิชาการ',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ด้านบริการวิชาการ', 'academic-service'],
                    'attrs' => [],
                ],
                [
                    'code' => 'DCT005',
                    'name' => 'ด้านการทะนุบำรุงศิลปวัฒนธรรม',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ด้านการทะนุบำรุงศิลปวัฒนธรรม'],
                    'attrs' => [],
                ],
                [
                    'code' => 'DCT006',
                    'name' => 'ด้านโครงสร้างองค์กรและระบบการบริหาร',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ด้านโครงสร้างองค์กรและระบบการบริหาร', 'organization-admin'],
                    'attrs' => [],
                ],
                [
                    'code' => 'DCT007',
                    'name' => 'ด้านการบริหารงานบุคคล สิทธิประโยชน์ วินัยและจรรยาบรรณ',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ด้านการบริหารงานบุคคล สิทธิประโยชน์ วินัยและจรรยาบรรณ', 'hr-discipline'],
                    'attrs' => [],
                ],
                [
                    'code' => 'DCT008',
                    'name' => 'ด้านการเงินและทรัพย์สิน พัสดุ การตรวจสอบ และการบริหารความเสี่ยง',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ด้านการเงินและทรัพย์สิน พัสดุ การตรวจสอบ และการบริหารความเสี่ยง', 'finance-assets-risk'],
                    'attrs' => [],
                ],
                [
                    'code' => 'DCT009',
                    'name' => 'ด้านการพัฒนารายได้',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ด้านการพัฒนารายได้'],
                    'attrs' => [],
                ],
                [
                    'code' => 'DCT010',
                    'name' => 'ด้านการรักษาพยาบาล',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ด้านการรักษาพยาบาล'],
                    'attrs' => [],
                ],
                [
                    'code' => 'DCT011',
                    'name' => 'ด้านการบริการเฉพาะด้าน เช่น ทันตกรรม',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ด้านการบริการเฉพาะด้าน เช่น ทันตกรรม'],
                    'attrs' => [],
                ],
                [
                    'code' => 'DCT012',
                    'name' => 'ด้านอื่น ๆ',
                    'description' => '',
                    'is_system' => false,
                    'aliases' => ['ด้านอื่น ๆ', 'other'],
                    'attrs' => [],
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
            ],
            self::LawCategory => [],
        };
    }
}
