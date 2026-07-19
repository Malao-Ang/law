<?php

namespace App\Services\Search;

use App\Services\ReviewStore;

class LawIndexer
{
    public function __construct(
        private readonly ElasticClient $client,
        private readonly ReviewStore $store,
    ) {}

    /** Extract a 4-digit year from a freeform date string (Buddhist or Gregorian). */
    public static function parseYear(?string $date): ?int
    {
        if ($date === null) {
            return null;
        }
        if (preg_match('/\d{4}/', $date, $m) === 1) {
            return (int) $m[0];
        }
        return null;
    }

    /** Read the export JSON + law_meta for one law and (re)index its chunks. Idempotent. */
    public function index(string $documentId): void
    {
        $exportPath = $this->store->absolutePath($this->store->exportRelativePath($documentId));
        if (! is_file($exportPath)) {
            return;
        }
        $export = json_decode((string) file_get_contents($exportPath), true) ?: [];
        $chunks = $export['chunks'] ?? [];

        $review = $this->store->getReviewDocument($documentId) ?? [];
        $meta = $review['law_meta'] ?? [];

        $docs = [];
        foreach ($chunks as $chunk) {
            $docs[] = $this->buildDoc($documentId, $chunk, $meta);
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
            'law_type'       => $meta['law_type'] ?? null,
            'status'         => $meta['status'] ?? null,
            'change_status'  => $meta['change_status'] ?? null,
            'agency'         => $meta['agency'] ?? null,
            'agencies'       => $meta['agencies'] ?? [],
            'law_group'      => $meta['law_group'] ?? null,
            'law_groups'     => $meta['law_groups'] ?? [],
            'signer_group'   => $meta['signer_group'] ?? null,
            'access_scope'   => ($meta['access_scope'] ?? 'public') === 'private' ? 'private' : 'public',
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
