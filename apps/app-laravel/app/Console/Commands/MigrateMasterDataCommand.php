<?php

namespace App\Console\Commands;

use App\Services\MasterData\EnforcementStatuses;
use App\Services\MasterData\ChangeStatuses;
use App\Services\MasterData\LawCategories;
use App\Services\MasterData\LawTypes;
use App\Services\MasterData\LegalStructures;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use App\Services\ReviewStore;
use Illuminate\Console\Command;

class MigrateMasterDataCommand extends Command
{
    protected $signature = 'master-data:migrate
        {kind : Migration kind. Supported: enforcement-status, law-type, law-category, legal-structure, change-status}
        {--dry-run : Audit without writing}
        {--map=* : Extra mapping in the form "legacy value=STA0x"}';

    protected $description = 'Migrate legacy data values to master data codes.';

    public function handle(MasterDataStore $masterData, EnforcementStatuses $statuses, LawTypes $lawTypes, LawCategories $lawCategories, ReviewStore $reviewStore, LegalStructures $legalStructures, ChangeStatuses $changeStatuses): int
    {
        $kind = (string) $this->argument('kind');
        if (in_array($kind, ['change-status', 'change_status'], true)) {
            return $this->migrateChangeStatuses($masterData, $changeStatuses, $reviewStore);
        }
        if (in_array($kind, ['legal-structure', 'legal_structure'], true)) {
            return $this->migrateLegalStructures($masterData, $legalStructures, $reviewStore);
        }
        if (in_array($kind, ['law-type', 'law_type'], true)) {
            return $this->migrateLawTypes($masterData, $lawTypes, $reviewStore);
        }
        if (in_array($kind, ['law-category', 'law_category'], true)) {
            return $this->migrateLawCategories($masterData, $lawCategories, $reviewStore);
        }

        if (! in_array($kind, ['enforcement-status', 'enforcement_status'], true)) {
            $this->error('Unknown migration kind. Supported: enforcement-status, law-type, law-category, legal-structure, change-status');

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

    private function migrateLawTypes(MasterDataStore $masterData, LawTypes $lawTypes, ReviewStore $reviewStore): int
    {
        $masterData->seedIfEmpty(MasterDataKind::LawFamily);
        $masterData->seedIfEmpty(MasterDataKind::LawType);

        $extraMap = $this->parseLawTypeMapOptions($lawTypes);
        if ($extraMap === null) {
            return self::FAILURE;
        }

        $audit = [];
        $unmapped = [];
        $needsForm = [];
        $patches = [];

        foreach ($reviewStore->listLawMeta() as $row) {
            $documentId = (string) ($row['document_id'] ?? '');
            if ($documentId === '') {
                continue;
            }

            $rawType = trim((string) ($row['law_type'] ?? ''));
            $rawIssuer = trim((string) ($row['issuer'] ?? ''));
            $mapped = $extraMap[$rawType] ?? null;
            $typeCode = $mapped ?? $this->resolveMigratedLawTypeCode($lawTypes, $rawType, $rawIssuer);

            if ($typeCode === 'NEEDS_FORM') {
                $audit[$rawType]['target'] = 'NEEDS_FORM';
                $audit[$rawType]['count'] = ($audit[$rawType]['count'] ?? 0) + 1;
                $needsForm[] = [
                    'document_id' => $documentId,
                    'title' => (string) ($row['title'] ?? ''),
                ];

                continue;
            }

            if ($typeCode === null || $typeCode === '') {
                $audit[$rawType]['target'] = 'UNMAPPED';
                $audit[$rawType]['count'] = ($audit[$rawType]['count'] ?? 0) + 1;
                $unmapped[$rawType][] = $documentId;

                continue;
            }

            $audit[$rawType]['target'] = $typeCode;
            $audit[$rawType]['count'] = ($audit[$rawType]['count'] ?? 0) + 1;

            $patch = [];
            if ($rawType !== $typeCode) {
                $patch['law_type'] = $typeCode;
            }
            if ($rawIssuer !== '') {
                $patch['issuer'] = null;
            }

            if ($patch !== []) {
                $patches[$documentId] = $patch;
            }
        }

        $this->renderAudit($audit);

        if ($unmapped !== []) {
            $this->error('UNMAPPED law type or issuer value(s) found.');
            foreach ($unmapped as $value => $documentIds) {
                $label = $value === '' ? '<empty>' : $value;
                $this->line($label.': '.implode(', ', $documentIds));
            }

            return self::FAILURE;
        }

        if ($needsForm !== []) {
            $this->warn('NEEDS_FORM law type value(s) found.');
            $this->table(['document_id', 'title'], $needsForm);
        }

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run: '.$this->patchCountLabel($patches).' would be updated.');

            return self::SUCCESS;
        }

        foreach ($patches as $documentId => $patch) {
            $reviewStore->patchLawMeta($documentId, $patch);
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
    private function parseLawTypeMapOptions(LawTypes $lawTypes): ?array
    {
        $map = [];
        foreach ((array) $this->option('map') as $entry) {
            $parts = explode('=', (string) $entry, 2);
            if (count($parts) !== 2) {
                $this->error('Invalid --map value. Use "legacy value=LTY0x".');

                return null;
            }

            [$legacy, $target] = $parts;
            $type = $lawTypes->resolve(trim($target));
            if ($type === null) {
                $this->error("Invalid --map law type target: {$target}");

                return null;
            }

            $map[trim($legacy)] = (string) $type['code'];
        }

        return $map;
    }

    private function migrateLegalStructures(MasterDataStore $masterData, LegalStructures $legalStructures, ReviewStore $reviewStore): int
    {
        $masterData->seedIfEmpty(MasterDataKind::LegalStructure);

        $extraMap = [];
        foreach ((array) $this->option('map') as $entry) {
            $parts = explode('=', (string) $entry, 2);
            $target = count($parts) === 2 ? $legalStructures->resolve(trim($parts[1])) : null;
            if ($target === null) {
                $this->error('Invalid --map value. Use "legacy value=LSTxxx".');

                return self::FAILURE;
            }
            $extraMap[trim($parts[0])] = (string) $target['code'];
        }

        $resolve = function (string $value) use ($extraMap, $legalStructures): ?string {
            if (isset($extraMap[$value])) {
                return $extraMap[$value];
            }
            $item = $legalStructures->resolve($value);

            return $item === null ? null : (string) $item['code'];
        };

        $audit = [];
        $unmapped = [];
        $documentsToPatch = [];
        $blockChanges = 0;

        foreach ($reviewStore->listDocuments() as $row) {
            $documentId = (string) ($row['document_id'] ?? '');
            if ($documentId === '') {
                continue;
            }
            try {
                $document = $reviewStore->getReviewDocument($documentId);
            } catch (\RuntimeException) {
                continue;
            }

            foreach (($document['pages'] ?? []) as $page) {
                foreach ((array) ($page['blocks'] ?? []) as $block) {
                    $raw = is_array($block) ? trim((string) ($block['meta']['chunk_type'] ?? '')) : '';
                    if ($raw === '') {
                        continue;
                    }
                    $code = $resolve($raw);
                    $audit[$raw]['target'] = $code ?? 'UNMAPPED';
                    $audit[$raw]['count'] = ($audit[$raw]['count'] ?? 0) + 1;
                    if ($code === null) {
                        $unmapped[$raw][$documentId] = true;

                        continue;
                    }
                    if ($code !== $raw) {
                        $documentsToPatch[$documentId] = true;
                        $blockChanges++;
                    }
                }
            }
        }

        $this->renderAudit($audit);

        if ($unmapped !== []) {
            $this->error('UNMAPPED legal structure value(s) found.');
            foreach ($unmapped as $value => $documentIds) {
                $this->line($value.': '.implode(', ', array_keys($documentIds)));
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info("Dry run: {$blockChanges} block(s) in ".count($documentsToPatch).' document(s) would be updated.');

            return self::SUCCESS;
        }

        $changed = 0;
        foreach (array_keys($documentsToPatch) as $documentId) {
            $changed += $reviewStore->mapBlockChunkTypes(
                $documentId,
                static fn (string $value): string => $resolve($value) ?? $value,
            );
        }

        $this->info("{$changed} block(s) in ".count($documentsToPatch).' document(s) updated.');

        return self::SUCCESS;
    }

    private function migrateLawCategories(MasterDataStore $masterData, LawCategories $lawCategories, ReviewStore $reviewStore): int
    {
        $masterData->seedIfEmpty(MasterDataKind::LawCategory);

        $extraMap = [];
        foreach ((array) $this->option('map') as $entry) {
            $parts = explode('=', (string) $entry, 2);
            $target = count($parts) === 2 ? $lawCategories->resolve(trim($parts[1])) : null;
            if ($target === null) {
                $this->error('Invalid --map value. Use "legacy value=DCT0xx".');

                return self::FAILURE;
            }
            $extraMap[trim($parts[0])] = (string) $target['code'];
        }

        $audit = [];
        $unmapped = [];
        $patches = [];

        foreach ($reviewStore->listLawMeta() as $row) {
            $documentId = (string) ($row['document_id'] ?? '');
            if ($documentId === '') {
                continue;
            }

            // listLawMeta() already folds a legacy single law_group into law_groups.
            $values = is_array($row['law_groups'] ?? null) ? $row['law_groups'] : [];

            $codes = [];
            $rowUnmapped = false;
            foreach ($values as $value) {
                $raw = trim((string) $value);
                if ($raw === '') {
                    continue;
                }
                $code = $extraMap[$raw] ?? (($resolved = $lawCategories->resolve($raw)) === null ? null : (string) $resolved['code']);
                if ($code === null) {
                    $audit[$raw]['target'] = 'UNMAPPED';
                    $audit[$raw]['count'] = ($audit[$raw]['count'] ?? 0) + 1;
                    $unmapped[$raw][] = $documentId;
                    $rowUnmapped = true;

                    continue;
                }
                $audit[$raw]['target'] = $code;
                $audit[$raw]['count'] = ($audit[$raw]['count'] ?? 0) + 1;
                if (! in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }

            if ($rowUnmapped) {
                continue;
            }

            $currentGroups = array_values(array_map('strval', $values));
            if ($currentGroups !== $codes) {
                $patches[$documentId] = ['law_groups' => $codes, 'law_group' => $codes[0] ?? ''];
            }
        }

        $this->renderAudit($audit);

        if ($unmapped !== []) {
            $this->error('UNMAPPED law category value(s) found.');
            foreach ($unmapped as $value => $documentIds) {
                $this->line($value.': '.implode(', ', array_unique($documentIds)));
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run: '.$this->patchCountLabel($patches).' would be updated.');

            return self::SUCCESS;
        }

        foreach ($patches as $documentId => $patch) {
            $reviewStore->patchLawMeta($documentId, $patch);
        }

        $this->info($this->patchCountLabel($patches).' updated.');
        if ($patches !== []) {
            $this->call('laws:reindex');
        }

        return self::SUCCESS;
    }

    private function migrateChangeStatuses(MasterDataStore $masterData, ChangeStatuses $changeStatuses, ReviewStore $reviewStore): int
    {
        $masterData->seedIfEmpty(MasterDataKind::ChangeStatus);
        $masterData->seedIfEmpty(MasterDataKind::ChangeDetail);

        $extraMap = $this->parseChangeStatusMapOptions($changeStatuses);
        if ($extraMap === null) {
            return self::FAILURE;
        }

        $audit = [];
        $unmapped = [];
        $patches = [];

        foreach ($reviewStore->listLawMeta() as $row) {
            $documentId = (string) ($row['document_id'] ?? '');
            if ($documentId === '') {
                continue;
            }

            $patch = [];
            $rowUnmapped = false;

            $rawStatus = trim((string) ($row['change_status'] ?? ''));
            if ($rawStatus !== '') {
                $statusCode = $this->resolveMigratedChangeStatusCode($changeStatuses, $extraMap, $rawStatus);
                if ($statusCode === null) {
                    $audit[$rawStatus]['target'] = 'UNMAPPED';
                    $audit[$rawStatus]['count'] = ($audit[$rawStatus]['count'] ?? 0) + 1;
                    $unmapped[$rawStatus][] = $documentId;
                    $rowUnmapped = true;
                } else {
                    $audit[$rawStatus]['target'] = $statusCode;
                    $audit[$rawStatus]['count'] = ($audit[$rawStatus]['count'] ?? 0) + 1;
                    if ($rawStatus !== $statusCode) {
                        $patch['change_status'] = $statusCode;
                    }
                }
            }

            $rawDetails = [];
            foreach ((array) ($row['change_details'] ?? []) as $detail) {
                $raw = trim((string) $detail);
                if ($raw !== '') {
                    $rawDetails[] = $raw;
                }
            }

            if ($rawDetails !== []) {
                $detailCodes = [];
                foreach ($rawDetails as $rawDetail) {
                    $detailCode = $this->resolveMigratedChangeDetailCode($changeStatuses, $extraMap, $rawDetail);
                    if ($detailCode === null) {
                        $audit[$rawDetail]['target'] = 'UNMAPPED';
                        $audit[$rawDetail]['count'] = ($audit[$rawDetail]['count'] ?? 0) + 1;
                        $unmapped[$rawDetail][] = $documentId;
                        $rowUnmapped = true;

                        continue;
                    }

                    $audit[$rawDetail]['target'] = $detailCode;
                    $audit[$rawDetail]['count'] = ($audit[$rawDetail]['count'] ?? 0) + 1;
                    if (! in_array($detailCode, $detailCodes, true)) {
                        $detailCodes[] = $detailCode;
                    }
                }

                if (! $rowUnmapped && $rawDetails !== $detailCodes) {
                    $patch['change_details'] = $detailCodes;
                }
            }

            if (! $rowUnmapped && $patch !== []) {
                $patches[$documentId] = $patch;
            }
        }

        $this->renderAudit($audit);

        if ($unmapped !== []) {
            $this->error('UNMAPPED change status value(s) found.');
            foreach ($unmapped as $value => $documentIds) {
                $this->line($value.': '.implode(', ', array_unique($documentIds)));
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run: '.$this->patchCountLabel($patches).' would be updated.');

            return self::SUCCESS;
        }

        foreach ($patches as $documentId => $patch) {
            $reviewStore->patchLawMeta($documentId, $patch);
        }

        $this->info($this->patchCountLabel($patches).' updated.');
        foreach (array_keys($patches) as $documentId) {
            $this->call('laws:reindex', ['--id' => $documentId]);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>|null
     */
    private function parseChangeStatusMapOptions(ChangeStatuses $changeStatuses): ?array
    {
        $map = [];
        foreach ((array) $this->option('map') as $entry) {
            $parts = explode('=', (string) $entry, 2);
            if (count($parts) !== 2) {
                $this->error('Invalid --map value. Use "legacy value=CHGxx" or "legacy value=CHDxx".');

                return null;
            }

            [$legacy, $target] = $parts;
            $target = trim($target);
            $resolved = $changeStatuses->resolve($target) ?? $changeStatuses->resolveDetail($target);
            if ($resolved === null) {
                $this->error("Invalid --map target: {$target}");

                return null;
            }

            $map[trim($legacy)] = (string) $resolved['code'];
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $extraMap
     */
    private function resolveMigratedChangeStatusCode(ChangeStatuses $changeStatuses, array $extraMap, string $raw): ?string
    {
        if (isset($extraMap[$raw])) {
            return str_starts_with($extraMap[$raw], 'CHG') ? $extraMap[$raw] : null;
        }

        $status = $changeStatuses->resolve($raw);

        return $status === null ? null : (string) $status['code'];
    }

    /**
     * @param  array<string, string>  $extraMap
     */
    private function resolveMigratedChangeDetailCode(ChangeStatuses $changeStatuses, array $extraMap, string $raw): ?string
    {
        if (isset($extraMap[$raw])) {
            return str_starts_with($extraMap[$raw], 'CHD') ? $extraMap[$raw] : null;
        }

        $detail = $changeStatuses->resolveDetail($raw);

        return $detail === null ? null : (string) $detail['code'];
    }

    private function resolveMigratedLawTypeCode(LawTypes $lawTypes, string $rawType, string $rawIssuer): ?string
    {
        $type = $lawTypes->resolve($rawType);
        $typeCode = (string) ($type['code'] ?? '');
        if ($typeCode !== '') {
            if ($this->isAmbiguousLegacyAnnouncement($rawType)) {
                return match ($this->legacyIssuerCode($rawIssuer)) {
                    'ISS01' => 'LTY01',
                    'ISS02' => 'LTY09',
                    null => 'NEEDS_FORM',
                    default => null,
                };
            }

            return $typeCode;
        }

        if ($lawTypes->familyOf($rawType) === 'LFM03') {
            return match ($this->legacyIssuerCode($rawIssuer)) {
                'ISS01' => 'LTY01',
                'ISS02' => 'LTY09',
                null => 'NEEDS_FORM',
                default => null,
            };
        }

        return null;
    }

    private function isAmbiguousLegacyAnnouncement(string $rawType): bool
    {
        return mb_strtoupper(trim($rawType)) === 'LTY01'
            || $this->normalizeMigrationText($rawType) === $this->normalizeMigrationText("\u{0E1B}\u{0E23}\u{0E30}\u{0E01}\u{0E32}\u{0E28}");
    }

    private function legacyIssuerCode(string $rawIssuer): ?string
    {
        $issuer = $this->normalizeMigrationText($rawIssuer);
        if ($issuer === '') {
            return null;
        }

        return match ($issuer) {
            'iss01', $this->normalizeMigrationText("\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}") => 'ISS01',
            'iss02', $this->normalizeMigrationText("\u{0E2A}\u{0E20}\u{0E32}\u{0E21}\u{0E2B}\u{0E32}\u{0E27}\u{0E34}\u{0E17}\u{0E22}\u{0E32}\u{0E25}\u{0E31}\u{0E22}") => 'ISS02',
            default => 'UNMAPPED',
        };
    }

    private function normalizeMigrationText(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
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
