<?php

namespace App\Console\Commands;

use App\Services\ReviewStore;
use App\Services\Search\ElasticClient;
use App\Services\Search\LawIndexDefinition;
use App\Services\Search\LawIndexer;
use Illuminate\Console\Command;

class SeedSampleLawsCommand extends Command
{
    protected $signature = 'laws:seed-sample';

    protected $description = 'Seed a few sample published laws and index them into Elasticsearch (dev only).';

    public function handle(ReviewStore $store, ElasticClient $client, LawIndexer $indexer): int
    {
        if (! $client->indexExists()) {
            $client->createIndex(LawIndexDefinition::definition());
        }

        $samples = [
            [
                'title' => 'พระราชบัญญัติภาษีที่ดินและสิ่งปลูกสร้าง พ.ศ. ๒๕๖๒',
                'law_type' => 'กฎหมายภายนอก',
                'status' => 'มีผลบังคับใช้',
                'change_status' => 'กฎหมายล่าสุด',
                'agency' => 'กระทรวงการคลัง',
                'law_group' => 'ภาษี',
                'signer_group' => 'คณะรัฐมนตรี',
                'published_date' => '2562',
                'keywords' => ['ภาษีที่ดิน', 'สิ่งปลูกสร้าง', 'ผู้เสียภาษี', 'ภาษีท้องถิ่น', 'อากรชุมชน'],
                'text' => 'ผู้เสียภาษีมีหน้าที่ชำระภาษีที่ดินและสิ่งปลูกสร้างตามอัตราที่กำหนด',
            ],
            [
                'title' => 'ระเบียบว่าด้วยการรักษาความปลอดภัย พ.ศ. ๒๕๖๔',
                'law_type' => 'ระเบียบ',
                'status' => 'มีผลบังคับใช้',
                'change_status' => 'ปรับปรุงทั้งฉบับ',
                'agency' => 'สำนักนายกรัฐมนตรี',
                'law_group' => 'ความมั่นคง',
                'signer_group' => 'คณะกรรมการความปลอดภัย',
                'published_date' => '2564',
                'keywords' => ['ความปลอดภัย', 'สถานที่ราชการ', 'หลักเกณฑ์', 'แผนเฝ้าระวัง'],
                'text' => 'การรักษาความปลอดภัยในสถานที่ราชการให้เป็นไปตามหลักเกณฑ์ที่กำหนด',
            ],
            [
                'title' => 'ประกาศกระทรวงสาธารณสุข พ.ศ. ๒๕๖๕',
                'law_type' => 'ประกาศ',
                'status' => 'มีผลบังคับใช้',
                'change_status' => 'กฎหมายล่าสุด',
                'agency' => 'กระทรวงสาธารณสุข',
                'law_group' => 'สาธารณสุข',
                'signer_group' => 'รัฐมนตรีว่าการ',
                'published_date' => '2565',
                'keywords' => ['สาธารณสุข', 'ควบคุมโรค', 'สถานพยาบาล', 'เฝ้าระวังโรค'],
                'text' => 'ให้สถานพยาบาลปฏิบัติตามมาตรฐานการควบคุมโรคติดต่อ',
            ],
        ];

        foreach ($samples as $index => $sample) {
            $documentId = 'sample_law_'.($index + 1);

            $store->writeReviewDocument($documentId, [
                'document_id' => $documentId,
                'source_file' => "{$documentId}.pdf",
                'source_type' => 'pdf',
                'language' => 'th',
                'summary' => ['page_count' => 1, 'block_count' => 1, 'review_required_count' => 0],
                'law_meta' => [
                    'title' => $sample['title'],
                    'law_type' => $sample['law_type'],
                    'status' => $sample['status'],
                    'change_status' => $sample['change_status'],
                    'agency' => $sample['agency'],
                    'law_group' => $sample['law_group'],
                    'signer_group' => $sample['signer_group'],
                    'published_date' => $sample['published_date'],
                    'keywords' => $sample['keywords'],
                    'summary' => $sample['title'],
                ],
                'pages' => [['page_no' => 1, 'blocks' => []]],
            ]);

            $store->writeExport($documentId, [
                'document_id' => $documentId,
                'document_title' => $sample['title'],
                'chunks' => [[
                    'chunk_id' => "{$documentId}-c1",
                    'page_no' => 1,
                    'block_ids' => ["{$documentId}-p1-b1"],
                    'section_path' => 'มาตรา ๑',
                    'text' => $sample['text'],
                    'meta' => [],
                ]],
            ]);

            $indexer->index($documentId);
            $this->info("Seeded + indexed {$documentId}");
        }

        $this->info('Done. Try searching for "ภาษี" on the Database page.');

        return self::SUCCESS;
    }
}
