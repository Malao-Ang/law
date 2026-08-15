<?php

namespace App\Console\Commands;

use App\Services\ReviewStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class MigrateLawTypesCommand extends Command
{
    protected $signature = 'laws:migrate-types {--no-reindex : Skip Elasticsearch reindex after remapping}';

    protected $description = 'Remap stored law_meta.law_type values to the current 4-type taxonomy.';

    public function handle(ReviewStore $store): int
    {
        $changed = 0;

        foreach ($store->listLawMeta() as $row) {
            $documentId = (string) ($row['document_id'] ?? '');
            if ($documentId === '') {
                continue;
            }

            $current = trim((string) ($row['law_type'] ?? ''));
            $next = $this->canonicalLawType($current);
            if ($next === null || $next === $current) {
                continue;
            }

            $store->patchLawMeta($documentId, ['law_type' => $next]);
            $changed++;
            $this->line("{$documentId}: {$current} -> {$next}");
        }

        Cache::forget('law-meta-list');
        $this->info("Remapped {$changed} law type value(s).");

        if (! $this->option('no-reindex')) {
            $this->call('laws:reindex');
        }

        return self::SUCCESS;
    }

    private function canonicalLawType(string $value): ?string
    {
        $normalized = mb_strtolower(trim($value));
        $compact = preg_replace('/\s+/u', '', $normalized) ?? $normalized;

        return match (true) {
            $compact === 'ข้อบังคับ' => 'ข้อบังคับ',
            $compact === 'ระเบียบ' => 'ระเบียบ',
            $compact === 'ประกาศ' => 'ประกาศ',
            str_contains($compact, 'พระราชบัญญัติ'),
                $compact === 'พ.ร.บ.',
                $compact === 'พรบ',
                $compact === 'phrb',
                $compact === 'kotmai-krung',
                $compact === 'kotmai-phaainok',
                $compact === 'กฎหมายภายนอก' => 'กฎหมายภายนอก',
            $compact === 'คำสั่ง',
                $compact === 'command',
                $compact === 'มติ',
                $compact === 'resolution' => 'ประกาศ',
            default => null,
        };
    }
}
