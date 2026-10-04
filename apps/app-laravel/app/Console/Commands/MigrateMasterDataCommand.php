<?php

namespace App\Console\Commands;

use App\Services\MasterData\EnforcementStatuses;
use App\Services\MasterData\LawCategories;
use App\Services\MasterData\LawTypes;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use App\Services\ReviewStore;
use Illuminate\Console\Command;

class MigrateMasterDataCommand extends Command
{
    protected $signature = 'master-data:migrate
        {kind : Migration kind. Supported: enforcement-status, law-type, law-category}
        {--dry-run : Audit without writing}
        {--map=* : Extra mapping in the form "legacy value=STA0x"}';

    protected $description = 'Migrate legacy data values to master data codes.';

    public function handle(MasterDataStore $masterData, EnforcementStatuses $statuses, LawTypes $lawTypes, LawCategories $lawCategories, ReviewStore $reviewStore): int
    {
        $kind = (string) $this->argument('kind');
        if (in_array($kind, ['law-type', 'law_type'], true)) {
            return $this->migrateLawTypes($masterData, $lawTypes, $reviewStore);
        }
        if (in_array($kind, ['law-category', 'law_category'], true)) {
            return $this->migrateLawCategories($masterData, $lawCategories, $reviewStore);
        }

        if (! in_array($kind, ['enforcement-status', 'enforcement_status'], true)) {
            $this->error('Unknown migration kind. Supported: enforcement-status, law-type, law-category');

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
        $masterData->seedIfEmpty(MasterDataKind::Issuer);
        $masterData->seedIfEmpty(MasterDataKind::LawType);

        $extraMap = $this->parseLawTypeMapOptions($lawTypes);
        if ($extraMap === null) {
            return self::FAILURE;
        }

        $legacyIssuerByType = $this->legacyIssuerByTypeAlias($masterData);
        $audit = [];
        $unmapped = [];
        $patches = [];

        foreach ($reviewStore->listLawMeta() as $row) {
            $documentId = (string) ($row['document_id'] ?? '');
            if ($documentId === '') {
                continue;
            }

            $rawType = trim((string) ($row['law_type'] ?? ''));
            $rawIssuer = trim((string) ($row['issuer'] ?? ''));
            $mapped = $extraMap[$rawType] ?? null;
            $type = $mapped === null ? $lawTypes->resolve($rawType) : $mapped['type'];

            if ($type === null) {
                $audit[$rawType]['target'] = 'UNMAPPED';
                $audit[$rawType]['count'] = ($audit[$rawType]['count'] ?? 0) + 1;
                $unmapped[$rawType][] = $documentId;

                continue;
            }

            $typeCode = (string) ($type['code'] ?? '');
            $requiresIssuer = (bool) ($type['attrs']['requires_issuer'] ?? false);
            $issuerCode = null;
            $issuerFromExisting = $rawIssuer === '' ? null : $lawTypes->issuerResolve($rawIssuer);
            if ($issuerFromExisting !== null) {
                $issuerCode = (string) $issuerFromExisting['code'];
            } elseif ($rawIssuer !== '' && $requiresIssuer) {
                $audit[$rawIssuer]['target'] = 'UNMAPPED';
                $audit[$rawIssuer]['count'] = ($audit[$rawIssuer]['count'] ?? 0) + 1;
                $unmapped[$rawIssuer][] = $documentId;

                continue;
            } elseif (($mapped['issuer'] ?? null) !== null) {
                $issuerCode = (string) $mapped['issuer']['code'];
            } elseif (isset($legacyIssuerByType[$rawType])) {
                $issuerCode = $legacyIssuerByType[$rawType];
            }

            if (! $requiresIssuer) {
                $issuerCode = null;
            }

            $audit[$rawType]['target'] = $typeCode.($issuerCode !== null ? '+'.$issuerCode : '');
            $audit[$rawType]['count'] = ($audit[$rawType]['count'] ?? 0) + 1;

            $patch = [];
            if ($rawType !== $typeCode) {
                $patch['law_type'] = $typeCode;
            }
            if ($issuerCode === null) {
                if ($rawIssuer !== '') {
                    $patch['issuer'] = null;
                }
            } elseif ($rawIssuer !== $issuerCode) {
                $patch['issuer'] = $issuerCode;
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
     * @return array<string, array{type: array<string, mixed>, issuer?: array<string, mixed>}>|null
     */
    private function parseLawTypeMapOptions(LawTypes $lawTypes): ?array
    {
        $map = [];
        foreach ((array) $this->option('map') as $entry) {
            $parts = explode('=', (string) $entry, 2);
            if (count($parts) !== 2) {
                $this->error('Invalid --map value. Use "legacy value=LTY0x" or "legacy value=LTY0x+ISS0x".');

                return null;
            }

            [$legacy, $target] = $parts;
            $targets = array_map('trim', explode('+', $target, 2));
            $type = $lawTypes->resolve($targets[0] ?? '');
            if ($type === null) {
                $this->error("Invalid --map law type target: {$target}");

                return null;
            }

            $map[trim($legacy)] = ['type' => $type];
            if (($targets[1] ?? '') !== '') {
                $issuer = $lawTypes->issuerResolve($targets[1]);
                if ($issuer === null) {
                    $this->error("Invalid --map issuer target: {$target}");

                    return null;
                }
                $map[trim($legacy)]['issuer'] = $issuer;
            }
        }

        return $map;
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

    /**
     * @return array<string, string>
     */
    private function legacyIssuerByTypeAlias(MasterDataStore $masterData): array
    {
        $map = [];
        foreach ($masterData->all(MasterDataKind::Issuer) as $issuer) {
            $issuerCode = (string) ($issuer['code'] ?? '');
            foreach ((array) ($issuer['attrs']['legacy_type_aliases'] ?? []) as $alias) {
                $map[trim((string) $alias)] = $issuerCode;
            }
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
