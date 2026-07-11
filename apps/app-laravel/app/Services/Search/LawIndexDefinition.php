<?php

namespace App\Services\Search;

class LawIndexDefinition
{
    /** Index mapping. Uses the built-in Thai analyzer for text fields. */
    public static function definition(): array
    {
        $textThai = fn (bool $withKeyword = false) => array_filter([
            'type' => 'text',
            'analyzer' => 'thai',
            'fields' => $withKeyword ? ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]] : null,
        ], fn ($v) => $v !== null);

        return [
            'mappings' => [
                'properties' => [
                    'law_id'        => ['type' => 'keyword'],
                    'chunk_id'      => ['type' => 'keyword'],
                    'page_no'       => ['type' => 'integer'],
                    'block_ids'     => ['type' => 'keyword'],
                    'section_path'  => $textThai(true),
                    'section_path_suggest' => ['type' => 'search_as_you_type', 'analyzer' => 'thai'],
                    'title'         => $textThai(true),
                    'title_suggest' => ['type' => 'search_as_you_type', 'analyzer' => 'thai'],
                    'text'          => $textThai(false),
                    'law_type'      => ['type' => 'keyword'],
                    'status'        => ['type' => 'keyword'],
                    'change_status' => ['type' => 'keyword'],
                    'agency'        => ['type' => 'keyword'],
                    'agencies'      => ['type' => 'keyword'],
                    'law_group'     => ['type' => 'keyword'],
                    'law_groups'    => ['type' => 'keyword'],
                    'signer_group'  => ['type' => 'keyword'],
                    'access_scope'  => ['type' => 'keyword'],
                    'keywords'      => ['type' => 'keyword'],
                    'keywords_text' => $textThai(false),
                    'keywords_suggest' => ['type' => 'search_as_you_type', 'analyzer' => 'thai'],
                    'published_date' => ['type' => 'keyword'],
                    'published_year' => ['type' => 'integer'],
                    'summary'       => $textThai(false),
                    'doc_number'    => ['type' => 'keyword'],
                ],
            ],
        ];
    }
}
