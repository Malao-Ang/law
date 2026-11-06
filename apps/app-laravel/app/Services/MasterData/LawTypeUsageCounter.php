<?php

namespace App\Services\MasterData;

use App\Services\ReviewStore;

class LawTypeUsageCounter implements UsageCounter
{
    /** @var array<string, int>|null */
    private ?array $counts = null;

    public function __construct(
        private readonly LawTypes $lawTypes,
        private readonly ReviewStore $reviewStore,
    ) {}

    public function count(MasterDataKind $kind, string $code): int
    {
        if ($kind !== MasterDataKind::LawType) {
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
            $item = $this->lawTypes->resolve($row['law_type'] ?? '');
            $code = (string) ($item['code'] ?? '');
            if ($code !== '') {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        $this->counts = $counts;

        return $this->counts;
    }
}
