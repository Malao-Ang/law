<?php

namespace App\Services\MasterData;

use App\Services\ReviewStore;

class LegalStructureUsageCounter implements UsageCounter
{
    /** @var array<string, int>|null */
    private ?array $counts = null;

    public function __construct(
        private readonly LegalStructures $legalStructures,
        private readonly ReviewStore $reviewStore,
    ) {}

    public function count(MasterDataKind $kind, string $code): int
    {
        if ($kind !== MasterDataKind::LegalStructure) {
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
        foreach ($this->reviewStore->blockChunkTypesByDocument() as $chunkTypes) {
            $seen = [];
            foreach ($chunkTypes as $chunkType) {
                $item = $this->legalStructures->resolve($chunkType);
                $code = (string) ($item['code'] ?? '');
                if ($code !== '') {
                    $seen[$code] = true;
                }
            }

            foreach (array_keys($seen) as $code) {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        $this->counts = $counts;

        return $this->counts;
    }
}
