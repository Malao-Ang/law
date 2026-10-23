<?php

namespace App\Services\MasterData;

use App\Services\Storage\MongoBlobStore;
use Illuminate\Validation\ValidationException;

/** Unit master uses a separate allocator because each type owns a DEP range. */
class UnitStore
{
    private const RANGES = ['faculty' => [1, 99], 'division' => [100, 199], 'department' => [200, 499], 'law_subunit' => [500, 999]];

    private const DIVISIONS = [
        'กองกลาง', 'กองกฎหมาย', 'กองกิจการนิสิต', 'กองคลังและทรัพย์สิน',
        'กองบริหารการศึกษา', 'กองบริหารการวิจัยและนวัตกรรม', 'กองบริหารและพัฒนาทรัพยากรบุคคล',
        'กองบริหารวิทยาเขตจันทบุรี', 'กองบริหารวิทยาเขตสระแก้ว', 'กองแผนงาน', 'กองอาคารสถานที่',
        'สำนักงานตรวจสอบภายใน', 'กองทะเบียนและประมวลผลการศึกษา',
        'ศูนย์ส่งเสริมความสัมพันธ์ระหว่างประเทศ', 'ศูนย์จีนศึกษา', 'สถาบันขงจื่อ',
    ];

    public function __construct(private readonly MongoBlobStore $blobs) {}

    public function all(): array
    {
        $data = $this->blobs->read('master', 'unit');
        if ($data === null || ! isset($data['items'])) {
            $this->blobs->withLock('master', 'unit', function (array &$data): void {
                if (! isset($data['items'])) {
                    $data['items'] = $this->seedItems();
                }
            });
            $data = $this->blobs->read('master', 'unit');
        }
        $items = array_values($data['items'] ?? []);
        usort($items, fn (array $a, array $b): int => [$a['sort_order'], $a['code']] <=> [$b['sort_order'], $b['code']]);

        return $items;
    }

    public function find(string $code): ?array
    {
        foreach ($this->all() as $item) {
            if ($item['code'] === $code) {
                return $item;
            }
        }

        return null;
    }

    public function save(array $payload, ?string $code = null): array
    {
        $this->all();
        $saved = [];
        $this->blobs->withLock('master', 'unit', function (array &$data) use ($payload, $code, &$saved): void {
            $items = $data['items'];
            $index = $code === null ? null : array_search($code, array_column($items, 'code'), true);
            abort_if($index === false, 404, 'ไม่พบหน่วยงาน');
            $existing = $index === null ? [] : $items[$index];
            $type = $payload['type'] ?? $existing['type'] ?? '';
            if (! isset(self::RANGES[$type]) || ($existing !== [] && $type !== $existing['type'])) {
                $this->invalid('type', 'ชนิดหน่วยงานไม่ถูกต้องหรือไม่สามารถเปลี่ยนชนิดได้');
            }
            $saved = array_replace($existing ?: $this->item($this->nextCode($type, $items), '', $type), array_intersect_key($payload, array_flip([
                'name', 'parent_code', 'ums_fac_id', 'ums_dep_id', 'is_active', 'sort_order',
            ])));
            $saved['name'] = trim((string) $saved['name']);
            foreach (['ums_fac_id', 'ums_dep_id', 'parent_code'] as $field) {
                $saved[$field] = trim((string) ($saved[$field] ?? '')) ?: null;
            }
            if ($saved['name'] === '') {
                $this->invalid('name', 'กรุณาระบุชื่อหน่วยงาน');
            }
            if ($type === 'faculty') {
                $saved['parent_code'] = null;
            } else {
                $parent = $this->parentFor($type, $saved, $items);
                $saved['parent_code'] = $parent['code'];
                $saved['ums_fac_id'] = $parent['ums_fac_id'];
            }
            $this->assertUniqueAffiliation($items, $saved);
            if ($index === null) {
                $items[] = $saved;
            } else {
                $items[$index] = $saved;
            }
            $data['items'] = $items;
        });

        return $saved;
    }

    /** Resolve mapped divisions before creating a department; never match an empty department to a division. */
    public function forAffiliation(array $profile): ?array
    {
        $fac = trim((string) ($profile['ums_fac_id'] ?? $profile['fac_id'] ?? ''));
        $dep = trim((string) ($profile['ums_dep_id'] ?? $profile['dep_id'] ?? ''));
        if ($fac === '') {
            return null;
        }
        $this->all();
        $unit = null;
        $this->blobs->withLock('master', 'unit', function (array &$data) use ($fac, $dep, $profile, &$unit): void {
            $items = &$data['items'];
            $faculty = null;
            foreach ($items as $item) {
                if ($item['ums_fac_id'] !== $fac) {
                    continue;
                }
                if ($item['type'] === 'faculty') {
                    $faculty = $item;
                } elseif ($dep !== '' && $item['ums_dep_id'] === $dep) {
                    $unit = $item;

                    return;
                }
            }
            if ($faculty === null) {
                $faculty = $this->item($this->nextCode('faculty', $items), trim((string) ($profile['fac_name'] ?? '')) ?: 'คณะ '.$fac, 'faculty', null, $fac, null, 'ums');
                $items[] = $faculty;
            }
            $unit = $faculty;
            if ($dep !== '') {
                $unit = $this->item($this->nextCode('department', $items), trim((string) ($profile['dep_name'] ?? '')) ?: 'หน่วยงาน '.$dep, 'department', $faculty['code'], $fac, $dep, 'ums');
                $items[] = $unit;
            }
        });

        return $unit;
    }

    public function syncFaculties(array $rows, bool $fallback): void
    {
        $this->all();
        $this->blobs->withLock('master', 'unit', function (array &$data) use ($rows, $fallback): void {
            $items = &$data['items'];
            $seen = [];
            foreach ($rows as $row) {
                $fac = trim((string) ($row['fac_id'] ?? ''));
                $name = trim((string) ($row['fac_nameth'] ?? ''));
                if ($fac === '' || $name === '') {
                    continue;
                }
                $seen[] = $fac;
                $found = false;
                foreach ($items as &$item) {
                    if ($item['type'] === 'faculty' && $item['ums_fac_id'] === $fac) {
                        // A fallback must not replace a name previously supplied by live UMS.
                        if (! $fallback || $item['source'] !== 'ums') {
                            $item['name'] = $name;
                            $item['source'] = $fallback ? 'seed' : 'ums';
                        }
                        if (! $fallback) {
                            $item['missing_in_ums'] = false;
                        }
                        $found = true;
                        break;
                    }
                }
                unset($item);
                if (! $found) {
                    $items[] = $this->item($this->nextCode('faculty', $items), $name, 'faculty', null, $fac, null, $fallback ? 'seed' : 'ums');
                }
            }
            if (! $fallback) {
                foreach ($items as &$item) {
                    if ($item['type'] === 'faculty') {
                        $item['missing_in_ums'] = ! in_array($item['ums_fac_id'], $seen, true);
                    }
                }
                unset($item);
            }
        });
    }

    public static function fallbackFaculties(): array
    {
        return json_decode(file_get_contents(database_path('seeders/data/faculty.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedItems(): array
    {
        $items = [];
        $parent = null;
        foreach (self::fallbackFaculties() as $row) {
            $item = $this->item($this->nextCode('faculty', $items), $row['fac_nameth'], 'faculty', null, (string) $row['fac_id'], null, 'seed');
            $items[] = $item;
            if ($item['ums_fac_id'] === '02') {
                $parent = $item['code'];
            }
        }
        if ($parent === null) {
            $this->invalid('parent_code', 'ไม่พบสำนักงานอธิการบดีในข้อมูลคณะสำรอง');
        }
        foreach (self::DIVISIONS as $name) {
            $items[] = $this->item($this->nextCode('division', $items), $name, 'division', $parent, '02', null, 'seed');
        }

        return $items;
    }

    private function nextCode(string $type, array $items): string
    {
        [$min, $max] = self::RANGES[$type];
        $next = $min;
        foreach ($items as $item) {
            if (preg_match('/^DEP(\d{3})$/', $item['code'], $match) && (int) $match[1] >= $min && (int) $match[1] <= $max) {
                $next = max($next, (int) $match[1] + 1);
            }
        }
        if ($next > $max) {
            $this->invalid('type', 'รหัสหน่วยงานในช่วงที่กำหนดเต็มแล้ว ไม่สามารถเพิ่มหน่วยงานได้');
        }

        return 'DEP'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function item(string $code, string $name, string $type, ?string $parent = null, ?string $fac = null, ?string $dep = null, string $source = 'manual'): array
    {
        return ['code' => $code, 'name' => $name, 'type' => $type, 'parent_code' => $parent, 'ums_fac_id' => $fac, 'ums_dep_id' => $dep, 'source' => $source, 'missing_in_ums' => false, 'is_active' => true, 'sort_order' => (int) substr($code, 3)];
    }

    private function parentFor(string $type, array $unit, array $items): array
    {
        foreach ($items as $item) {
            if (($type === 'division' && $item['type'] === 'faculty' && $item['ums_fac_id'] === '02')
                || ($type === 'law_subunit' && $item['type'] === 'division' && $item['code'] === 'DEP101')
                || ($type === 'department' && $item['type'] === 'faculty' && $item['code'] === $unit['parent_code'])) {
                return $item;
            }
        }
        $this->invalid('parent_code', 'ไม่พบหน่วยงานแม่ที่ถูกต้อง');
    }

    private function assertUniqueAffiliation(array $items, array $unit): void
    {
        foreach ($items as $item) {
            if ($item['code'] === $unit['code'] || ! $unit['ums_fac_id'] || $item['ums_fac_id'] !== $unit['ums_fac_id']) {
                continue;
            }
            if (($unit['type'] === 'faculty' && $item['type'] === 'faculty')
                || ($unit['ums_dep_id'] !== null && $item['ums_dep_id'] === $unit['ums_dep_id'])) {
                $this->invalid('ums_dep_id', 'สังกัด UMS นี้ถูกผูกกับหน่วยงานอื่นแล้ว');
            }
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
