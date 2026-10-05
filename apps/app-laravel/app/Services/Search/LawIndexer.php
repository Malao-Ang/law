<?php

namespace App\Services\Search;

use App\Services\LawMetaNormalizer;
use App\Services\MasterData\ChangeStatuses;
use App\Services\MasterData\LawCategories;
use App\Services\MasterData\LawTypes;
use App\Services\ReviewStore;

class LawIndexer
{
    public function __construct(
        private readonly ElasticClient $client,
        private readonly ReviewStore $store,
        private readonly ?LawTypes $lawTypes = null,
        private readonly ?LawCategories $lawCategories = null,
        private readonly ?ChangeStatuses $changeStatuses = null,
    ) {}

    /** Extract a 4-digit year from a freeform date string (Buddhist or Gregorian). */
    public static function parseYear(?string $date): ?int
    {
        if ($date === null) {
            return null;
        }
        if (preg_match('/\d{4}/', $date, $m) === 1) {
            $year = (int) $m[0];

            return $year >= 2400 ? $year - 543 : $year;
        }
        return null;
    }

    /** Read the export JSON + law_meta for one law and (re)index its chunks. Idempotent. */
    public function index(string $documentId): void
    {
        $review = $this->store->getReviewDocument($documentId) ?? [];
        $meta = $review['law_meta'] ?? [];

        $docs = [];
        $exportPath = $this->store->absolutePath($this->store->exportRelativePath($documentId));
        if (is_file($exportPath)) {
            $export = json_decode((string) file_get_contents($exportPath), true) ?: [];
            foreach ($export['chunks'] ?? [] as $chunk) {
                $docs[] = $this->buildDoc($documentId, $chunk, $meta);
            }
        }

        // Always index at least one metadata doc so the law appears in ES search
        // even when the export has no text chunks.
        if ($docs === []) {
            $docs[] = $this->buildDoc($documentId, [
                'chunk_id'     => $documentId . '_meta',
                'page_no'      => 1,
                'block_ids'    => [],
                'section_path' => null,
                'text'         => '',
            ], $meta);
        }

        if (! $this->client->indexExists()) {
            $this->client->createIndex(LawIndexDefinition::definition());
        }

        $this->client->deleteByLawId($documentId);
        $this->client->bulkIndex($docs);
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizeKeywords(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keywords = [];
        foreach ($value as $entry) {
            $text = trim((string) $entry);
            if ($text !== '' && ! in_array($text, $keywords, true)) {
                $keywords[] = $text;
            }
        }

        return $keywords;
    }

    /** @return array<string,mixed> */
    private function buildDoc(string $documentId, array $chunk, array $meta): array
    {
        $keywords = $this->normalizeKeywords($meta['keywords'] ?? []);
        $permissionGroupIds = is_array($meta['permission_group_ids'] ?? null)
            ? array_values(array_filter(array_map('strval', $meta['permission_group_ids'])))
            : [];
        $lawTypes = $this->lawTypes ?? app(LawTypes::class);
        $lawType = $lawTypes->resolve($meta['law_type'] ?? '');
        $lawTypeCode = (string) ($lawType['code'] ?? ($meta['law_type'] ?? ''));
        $lawFamilyCode = (string) ($lawType['attrs']['family_code'] ?? '');
        $lawCategories = $this->lawCategories ?? app(LawCategories::class);
        $lawGroupValues = is_array($meta['law_groups'] ?? null) ? $meta['law_groups'] : [];
        if ($lawGroupValues === [] && trim((string) ($meta['law_group'] ?? '')) !== '') {
            $lawGroupValues = [$meta['law_group']];
        }
        $lawGroupCodes = $lawCategories->codesOf($lawGroupValues);
        $lawGroupLabels = $lawCategories->labelsOf($lawGroupCodes);
        $changeStatuses = $this->changeStatuses ?? app(ChangeStatuses::class);
        $changeStatus = $changeStatuses->resolve($meta['change_status'] ?? null);
        $changeStatusCode = (string) ($changeStatus['code'] ?? ($meta['change_status'] ?? ''));

        return [
            'law_id'         => $documentId,
            'chunk_id'       => $chunk['chunk_id'],
            'page_no'        => (int) ($chunk['page_no'] ?? 1),
            'block_ids'      => $chunk['block_ids'] ?? [],
            'section_path'   => $chunk['section_path'] ?? null,
            'section_path_suggest' => $chunk['section_path'] ?? null,
            'text'           => $chunk['text'] ?? '',
            'title'          => $meta['title'] ?? null,
            'title_suggest'  => $meta['title'] ?? null,
            'law_type'       => $lawTypeCode !== '' ? $lawTypeCode : null,
            'law_family'     => $lawFamilyCode !== '' ? $lawFamilyCode : null,
            'law_type_label' => $lawType === null ? ($meta['law_type'] ?? null) : (string) ($lawType['name'] ?? ''),
            'issuer'         => null,
            'status'         => LawMetaNormalizer::statusCode($meta['status'] ?? null) ?: null,
            'change_status'  => $changeStatusCode !== '' ? $changeStatusCode : null,
            'change_status_label' => $changeStatus === null ? ($meta['change_status'] ?? null) : (string) ($changeStatus['name'] ?? ''),
            'agency'         => $meta['agency'] ?? null,
            'agencies'       => $meta['agencies'] ?? [],
            'law_group'      => $lawGroupCodes[0] ?? null,
            'law_groups'     => $lawGroupCodes,
            'law_group_labels' => $lawGroupLabels,
            'signer_group'   => $meta['signer_group'] ?? null,
            'access_scope'   => ($meta['access_scope'] ?? 'public') === 'private' ? 'private' : 'public',
            'permission_group_ids' => $permissionGroupIds,
            'visibility'     => LawMetaNormalizer::effectiveVisibility($meta),
            'keywords'       => $keywords,
            'keywords_text'  => implode(' ', $keywords),
            'keywords_suggest' => implode(' ', $keywords),
            'published_date' => $meta['published_date'] ?? null,
            'published_year' => self::parseYear($meta['published_date'] ?? $meta['promulgation_date'] ?? null),
            'summary'        => $meta['summary'] ?? null,
            'doc_number'     => $meta['metadata']['doc_number'] ?? null,
        ];
    }
}
