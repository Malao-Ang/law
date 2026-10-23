<?php

namespace App\Console\Commands;

use App\Services\Auth\DocumentOwnership;
use App\Services\ReviewStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BackfillDocumentOwnerCommand extends Command
{
    protected $signature = 'documents:backfill-owner {--dry-run : แสดงรายการโดยไม่เขียนข้อมูล}';
    protected $description = 'กำหนดหน่วยงานเจ้าของเอกสารเดิม พร้อมสำรองข้อมูลก่อนเขียน';

    public function handle(ReviewStore $documents, DocumentOwnership $ownership): int
    {
        $code = $ownership->legalUnitCode();
        $rows = [];
        foreach ($documents->listDocuments() as $row) {
            $status = $documents->getStatus($row['document_id']);
            if (empty($status['owner_unit_code'])) {
                $rows[] = $status;
            }
        }
        $this->table(['เอกสาร', 'หน่วยงานเจ้าของ'], array_map(fn ($row) => [$row['document_id'], $code], $rows));
        if ($this->option('dry-run') || $rows === []) {
            $this->info('จำนวนเอกสารที่ต้องปรับ: '.count($rows).' (ยังไม่เขียนข้อมูล)');
            return self::SUCCESS;
        }
        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory, 0700);
        $path = $directory.'/documents-owner-'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
        $backup = json_encode(['at' => now()->toIso8601String(), 'documents' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (File::put($path, $backup) !== strlen($backup)) {
            $this->error('ไม่สามารถสำรองข้อมูลเอกสารได้ ยกเลิกการปรับข้อมูล');
            return self::FAILURE;
        }
        $this->info('สำรองข้อมูลแล้ว: '.$path);
        foreach ($rows as $row) {
            $documents->setStatus($row['document_id'], ['owner_unit_code' => $code, 'responsible' => $row['responsible'] ?? null, 'uploaded_by' => $row['uploaded_by'] ?? null]);
        }
        $this->info('ปรับเจ้าของเอกสารเรียบร้อยแล้ว: '.count($rows).' ฉบับ');
        return self::SUCCESS;
    }
}
