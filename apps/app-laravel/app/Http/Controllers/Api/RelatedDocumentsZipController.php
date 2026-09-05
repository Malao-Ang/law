<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReviewStore;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use ZipArchive;

class RelatedDocumentsZipController extends Controller
{
    public function __construct(private readonly ReviewStore $reviewStore) {}

    public function __invoke(string $documentId): BinaryFileResponse|Response
    {
        if (! class_exists(ZipArchive::class)) {
            return response('เซิร์ฟเวอร์ยังไม่รองรับการสร้างไฟล์ ZIP', 500);
        }

        $ids = $this->reviewStore->relatedDocumentIdsForDownload($documentId);
        if ($ids === []) {
            abort(404, 'Document not found.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'related-documents-');
        if ($zipPath === false) {
            return response('ไม่สามารถสร้างไฟล์ ZIP ได้', 500);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);

            return response('ไม่สามารถสร้างไฟล์ ZIP ได้', 500);
        }

        $selectedTitle = $documentId;
        $usedNames = [];
        foreach ($ids as $id) {
            $file = $this->reviewStore->downloadableFileForDocument($id);
            if ($file === null) {
                continue;
            }

            if ($id === $documentId) {
                $selectedTitle = $file['title'];
            }

            $zip->addFile(
                $file['path'],
                $this->uniqueZipEntryName($file['title'], $file['extension'], $usedNames),
            );
        }

        $zip->close();

        $zipName = $this->safeFilenameBase($selectedTitle).'-เอกสารที่เกี่ยวข้อง.zip';
        $asciiFallback = trim((string) preg_replace('/[^\x20-\x7e]/', '', $zipName)) ?: 'related-documents.zip';
        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $zipName, $asciiFallback);

        return response()->download($zipPath, $zipName, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => $disposition,
        ])->deleteFileAfterSend(true);
    }

    /**
     * @param  array<string, true>  $usedNames
     */
    private function uniqueZipEntryName(string $title, string $extension, array &$usedNames): string
    {
        $base = $this->safeFilenameBase($title);
        $extension = trim($extension) !== '' ? '.'.ltrim($extension, '.') : '';
        $name = $base.$extension;
        $index = 2;

        while (isset($usedNames[$name])) {
            $name = sprintf('%s (%d)%s', $base, $index, $extension);
            $index++;
        }

        $usedNames[$name] = true;

        return $name;
    }

    private function safeFilenameBase(string $title): string
    {
        $safe = trim((string) preg_replace('/[\/\\\\?%*:|"<>]+/u', '_', $title));
        $safe = trim((string) preg_replace('/\s+/u', ' ', $safe));

        return mb_substr($safe !== '' ? $safe : 'document', 0, 120, 'UTF-8');
    }
}
