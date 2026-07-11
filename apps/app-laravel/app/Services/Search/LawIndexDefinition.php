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
        ]);

        return [
            'mappings' => [
                'properties' => [
                    'law_id'        => ['type' => 'keyword'],
                    'chunk_id'      => ['type' => 'keyword'],
                    'page_no'       => ['type' => 'integer'],
                    'block_ids'     => ['type' => 'keyword'],
                    'section_path'  => $textThai(true),
                    'title'         => $textThai(true),
                    'text'          => $textThai(false),
                    'law_type'      => ['type' => 'keyword'],
                    'status'        => ['type' => 'keyword'],
                    'change_status' => ['type' => 'keyword'],
                    'agency'        => ['type' => 'keyword'],
                    'agencies'      => ['type' => 'keyword'],
                    'law_group'     => ['type' => 'keyword'],
                    'law_groups'    => ['type' => 'keyword'],
                    'signer_group'  => ['type' => 'keyword'],
                    'keywords'      => ['type' => 'keyword'],
                    'published_date' => ['type' => 'keyword'],
                    'published_year' => ['type' => 'integer'],
                    'summary'       => $textThai(false),
                    'doc_number'    => ['type' => 'keyword'],
                ],
            ],
        ];
    }
}
