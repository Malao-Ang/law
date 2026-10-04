<?php

namespace App\Services\MasterData;

use App\Services\ReviewStore;

class LawFamilyUsageCounter implements UsageCounter
{
    /** @var array<string, int>|null */
    private ?array $counts = null;

    public function __construct(
        private readonly LawTypes $lawTypes,
        private readonly MasterDataStore $store,
        private readonly ReviewStore $reviewStore,
    ) {}

    public function count(MasterDataKind $kind, string $code): int
    {
        if ($kind !== MasterDataKind::LawFamily) {
            return 0;
        }

        return $this->counts()[mb_strtoupper(trim($code))] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        if ($this->counts !== null) {
            return $this->counts;
        }

        $counts = [];
        foreach ($this->store->all(MasterDataKind::LawType) as $type) {
            $familyCode = (string) ($type['attrs']['family_code'] ?? '');
            if ($familyCode !== '') {
                $counts[$familyCode] = ($counts[$familyCode] ?? 0) + 1;
            }
        }

        foreach ($this->reviewStore->listLawMeta() as $row) {
            $familyCode = $this->lawTypes->familyOf($row['law_type'] ?? '');
            if ($familyCode !== null && $familyCode !== '') {
                $counts[$familyCode] = ($counts[$familyCode] ?? 0) + 1;
            }
        }

        $this->counts = $counts;

        return $this->counts;
    }
}
