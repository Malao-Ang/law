<?php

namespace App\Console\Commands;

use App\Services\MasterData\EnforcementStatuses;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use App\Services\ReviewStore;
use Illuminate\Console\Command;

class MigrateMasterDataCommand extends Command
{
    protected $signature = 'master-data:migrate
        {kind : Migration kind. Supported: enforcement-status}
        {--dry-run : Audit without writing}
        {--map=* : Extra mapping in the form "legacy value=STA0x"}';

    protected $description = 'Migrate legacy data values to master data codes.';

    public function handle(MasterDataStore $masterData, EnforcementStatuses $statuses, ReviewStore $reviewStore): int
    {
        if (! in_array((string) $this->argument('kind'), ['enforcement-status', 'enforcement_status'], true)) {
            $this->error('Unknown migration kind. Supported: enforcement-status');

            return self::FAILURE;
        }

        $masterData->seedIfEmpty(MasterDataKind::EnforcementStatus);
        $extraMap = $this->parseMapOptions($statuses);
        if ($extraMap === null) {
            return self::FAILURE;
        }

        $rows = $reviewStore->listLawMeta();
        $audit = [];
        $unmapped = [];
        $patches = [];

        foreach ($rows as $row) {
            $documentId = (string) ($row['document_id'] ?? '');
            if ($documentId === '') {
                continue;
            }

            $raw = trim((string) ($row['raw_meta_status'] ?? $row['meta_status'] ?? ''));
            $code = $extraMap[$raw] ?? null;
            if ($code === null) {
                $resolved = $statuses->resolve($raw);
                $code = $resolved === null ? null : (string) $resolved['code'];
            }

            $auditKey = $raw;
            if ($code === null || $code === '') {
                $audit[$auditKey]['target'] = 'UNMAPPED';
                $audit[$auditKey]['count'] = ($audit[$auditKey]['count'] ?? 0) + 1;
                $unmapped[$raw][] = $documentId;

                continue;
            }

            $audit[$auditKey]['target'] = $code;
            $audit[$auditKey]['count'] = ($audit[$auditKey]['count'] ?? 0) + 1;

            if ($raw !== $code) {
                $patches[$documentId] = $code;
            }
        }

        $this->renderAudit($audit);

        if ($unmapped !== []) {
            $this->error('UNMAPPED enforcement status value(s) found.');
            foreach ($unmapped as $value => $documentIds) {
                $label = $value === '' ? '<empty>' : $value;
                $this->line($label.': '.implode(', ', $documentIds));
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run: '.$this->patchCountLabel($patches).' would be updated.');

            return self::SUCCESS;
        }

        foreach ($patches as $documentId => $code) {
            $reviewStore->patchLawMeta($documentId, ['status' => $code]);
        }

        $this->info($this->patchCountLabel($patches).' updated.');
        if ($patches !== []) {
            $this->call('laws:reindex');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>|null
     */
    private function parseMapOptions(EnforcementStatuses $statuses): ?array
    {
        $map = [];
        foreach ((array) $this->option('map') as $entry) {
            $parts = explode('=', (string) $entry, 2);
            if (count($parts) !== 2) {
                $this->error('Invalid --map value. Use "legacy value=STA0x".');

                return null;
            }

            [$legacy, $target] = $parts;
            $resolved = $statuses->resolve($target);
            if ($resolved === null) {
                $this->error("Invalid --map target: {$target}");

                return null;
            }

            $map[trim($legacy)] = (string) $resolved['code'];
        }

        return $map;
    }

    /**
     * @param  array<string, array{target?: string, count?: int}>  $audit
     */
    private function renderAudit(array $audit): void
    {
        ksort($audit);
        $this->table(['value', 'target', 'count'], array_map(
            static fn (string $value, array $row): array => [
                $value === '' ? '<empty>' : $value,
                $row['target'] ?? 'UNMAPPED',
                (string) ($row['count'] ?? 0),
            ],
            array_keys($audit),
            array_values($audit),
        ));
    }

    /**
     * @param  array<string, string>  $patches
     */
    private function patchCountLabel(array $patches): string
    {
        return count($patches).' document(s)';
    }
}
