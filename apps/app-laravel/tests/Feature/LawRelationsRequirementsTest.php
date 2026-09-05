<?php

namespace Tests\Feature;

use App\Services\ReviewStore;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

class LawRelationsRequirementsTest extends TestCase
{
    private function seedLaw(
        ReviewStore $store,
        string $id,
        string $title,
        array $meta = [],
        array $relations = [],
        string $fileBody = 'PDF',
    ): void {
        $relative = "uploads/{$id}.pdf";
        $absolute = $store->absolutePath($relative);
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $fileBody);

        $store->setStatus($id, [
            'document_id' => $id,
            'status' => 'done',
            'source_file' => "{$title}.pdf",
            'source_path' => $relative,
        ]);

        $store->writeReviewDocument($id, [
            'document_id' => $id,
            'source_file' => "{$title}.pdf",
            'source_type' => 'pdf_scan',
            'language' => 'th',
            'summary' => ['page_count' => 1, 'block_count' => 0, 'review_required_count' => 0],
            'law_meta' => array_merge([
                'title' => $title,
                'law_type' => 'regulation',
                'status' => 'active',
                'published_date' => '2026-01-01',
            ], $meta),
            'relations' => $relations,
            'pages' => [['page_no' => 1, 'blocks' => []]],
        ]);
    }

    public function test_delete_parent_with_active_child_returns_conflict(): void
    {
        $store = app(ReviewStore::class);
        $parentId = 'doc_parent_delete_guard';
        $childId = 'doc_child_delete_guard';

        $this->seedLaw($store, $parentId, 'Parent Law');
        $this->seedLaw($store, $childId, 'Child Law', ['parent_document_id' => $parentId]);

        $this->deleteJson("/api/documents/{$parentId}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'ไม่สามารถลบกฎหมายแม่ได้ เนื่องจากมีกฎหมายลูกที่ยังมีผลบังคับใช้ 1 ฉบับ');

        $this->assertNotNull($store->getStatus($parentId));
    }

    public function test_active_children_endpoint_returns_count_and_titles(): void
    {
        $store = app(ReviewStore::class);
        $parentId = 'doc_parent_active_children';
        $childId = 'doc_child_active_children';
        $inactiveChildId = 'doc_inactive_child';

        $this->seedLaw($store, $parentId, 'Parent Law');
        $this->seedLaw($store, $childId, 'Active Child', ['parent_document_ids' => [$parentId]]);
        $this->seedLaw($store, $inactiveChildId, 'Inactive Child', [
            'parent_document_id' => $parentId,
            'status' => 'cancelled',
        ]);

        $this->getJson("/api/documents/{$parentId}/active-children")
            ->assertOk()
            ->assertJsonPath('document_id', $parentId)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('children.0.document_id', $childId)
            ->assertJsonPath('children.0.title', 'Active Child');
    }

    public function test_related_zip_is_scoped_to_selected_relation_tree(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $store = app(ReviewStore::class);
        $selectedId = 'doc_zip_selected';
        $childId = 'doc_zip_child';
        $linkedId = 'doc_zip_linked';
        $unrelatedId = 'doc_zip_unrelated';

        $this->seedLaw($store, $linkedId, 'Linked Law', [], [], 'linked');
        $this->seedLaw($store, $selectedId, 'Selected Law', [], [[
            'id' => 'rel-linked',
            'scope' => 'document',
            'type' => 'references',
            'target_document_id' => $linkedId,
            'target_title' => 'Linked Law',
        ]], 'selected');
        $this->seedLaw($store, $childId, 'Child Law', ['parent_document_id' => $selectedId], [], 'child');
        $this->seedLaw($store, $unrelatedId, 'Unrelated Law', [], [], 'unrelated');

        $response = $this->get("/api/documents/{$selectedId}/related-download.zip");
        $response->assertOk()->assertHeader('Content-Type', 'application/zip');

        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $zipPath = $response->baseResponse->getFile()->getPathname();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name)) {
                $names[] = $name;
            }
        }
        $zip->close();

        $this->assertContains('Selected Law.pdf', $names);
        $this->assertContains('Child Law.pdf', $names);
        $this->assertContains('Linked Law.pdf', $names);
        $this->assertNotContains('Unrelated Law.pdf', $names);
    }
}
