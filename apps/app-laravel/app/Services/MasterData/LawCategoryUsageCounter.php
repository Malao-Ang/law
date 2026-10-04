<?php

namespace App\Services\MasterData;

use App\Services\ReviewStore;

class LawCategoryUsageCounter implements UsageCounter
{
    /** @var array<string, int>|null */
    private ?array $counts = null;

    public function __construct(
        private readonly LawCategories $lawCategories,
        private readonly ReviewStore $reviewStore,
    ) {}

    public function count(MasterDataKind $kind, string $code): int
    {
        if ($kind !== MasterDataKind::LawCategory) {
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
        foreach ($this->reviewStore->listLawMeta() as $row) {
            $values = (array) ($row['law_groups'] ?? []);
            if ($values === [] && trim((string) ($row['law_group'] ?? '')) !== '') {
                $values = [$row['law_group']];
            }

            foreach ($this->lawCategories->codesOf($values) as $code) {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        $this->counts = $counts;

        return $this->counts;
    }
}
