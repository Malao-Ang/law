<?php

namespace App\Services\MasterData;

use App\Services\ReviewStore;

class ChangeStatusUsageCounter implements UsageCounter
{
    /** @var array<string, int>|null */
    private ?array $statusCounts = null;

    /** @var array<string, int>|null */
    private ?array $detailCounts = null;

    public function __construct(
        private readonly ChangeStatuses $changeStatuses,
        private readonly ReviewStore $reviewStore,
    ) {}

    public function count(MasterDataKind $kind, string $code): int
    {
        $code = mb_strtoupper(trim($code));

        return match ($kind) {
            MasterDataKind::ChangeStatus => $this->statusCounts()[$code] ?? 0,
            MasterDataKind::ChangeDetail => $this->detailCounts()[$code] ?? 0,
            default => 0,
        };
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $this->loadCounts();

        return $this->statusCounts ?? [];
    }

    /**
     * @return array<string, int>
     */
    private function detailCounts(): array
    {
        $this->loadCounts();

        return $this->detailCounts ?? [];
    }

    private function loadCounts(): void
    {
        if ($this->statusCounts !== null && $this->detailCounts !== null) {
            return;
        }

        $statusCounts = [];
        $detailCounts = [];
        foreach ($this->reviewStore->listLawMeta() as $row) {
            $statusCode = (string) ($this->changeStatuses->resolve($row['change_status'] ?? null)['code'] ?? '');
            if ($statusCode !== '') {
                $statusCounts[$statusCode] = ($statusCounts[$statusCode] ?? 0) + 1;
            }

            $seenDetails = [];
            foreach ((array) ($row['change_details'] ?? []) as $detail) {
                $detailCode = (string) ($this->changeStatuses->resolveDetail($detail)['code'] ?? '');
                if ($detailCode !== '') {
                    $seenDetails[$detailCode] = true;
                }
            }

            foreach (array_keys($seenDetails) as $detailCode) {
                $detailCounts[$detailCode] = ($detailCounts[$detailCode] ?? 0) + 1;
            }
        }

        $this->statusCounts = $statusCounts;
        $this->detailCounts = $detailCounts;
    }
}
