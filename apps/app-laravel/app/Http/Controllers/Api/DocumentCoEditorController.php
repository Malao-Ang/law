<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\CurrentUser;
use App\Services\Auth\DocumentAccess;
use App\Services\Auth\DocumentOwnership;
use App\Services\Auth\MongoUserStore;
use App\Services\LawMetaNormalizer;
use App\Services\ReviewStore;
use App\Services\Search\LawIndexer;
use App\Services\Storage\MongoBlobStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentCoEditorController extends Controller
{
    public function __invoke(Request $request, string $documentId, string $username,
        DocumentAccess $access, ReviewStore $documents, MongoUserStore $users,
        CurrentUser $current, MongoBlobStore $blobs, DocumentOwnership $audit, LawIndexer $indexer): JsonResponse
    {
        $status = $documents->getStatus($documentId);
        abort_if($status === null, 404, 'ไม่พบเอกสาร');
        $access->authorize($request, 'manage_co_editors', $status);
        $username = MongoUserStore::key($username);
        $actions = null;
        if (! $request->isMethod('DELETE')) {
            $data = $request->validate(['actions' => ['array'], 'actions.*' => ['string', 'in:edit,delete,esign,publish']], [
                'actions.array' => 'รายการสิทธิ์ไม่ถูกต้อง',
                'actions.*.string' => 'สิทธิ์ไม่ถูกต้อง',
                'actions.*.in' => 'สิทธิ์ไม่ถูกต้อง',
            ]);
            abort_unless(array_key_exists('actions', $data), 422, 'กรุณาระบุรายการสิทธิ์');
            $actions = array_values(array_unique($data['actions']));
        }
        $before = $after = [];
        $actor = $current->user($request);
        $blobs->withLock('review', $documentId, function (array &$review) use ($request, $access, $documents, $users, $documentId, $username, $actions, $actor, &$before, &$after): void {
            $status = $documents->getStatus($documentId);
            abort_if($status === null, 404, 'ไม่พบเอกสาร');
            $access->authorize($request, 'manage_co_editors', $status);
            if ($actions !== null) {
                $target = $users->find($username);
                $membership = null;
                foreach ($target['memberships'] ?? [] as $candidate) {
                    if ($candidate['unit_code'] === ($status['owner_unit_code'] ?? null)) $membership = $candidate;
                }
                abort_if($target === null || ($target['is_disabled'] ?? false)
                    || ! in_array($membership['role'] ?? null, ['editor', 'inner_admin', 'outer_admin'], true), 422, 'ผู้รับผิดชอบร่วมต้องเป็นผู้แก้ไขหรือผู้ดูแลในหน่วยงานเจ้าของเอกสาร');
                abort_if(($status['responsible']['username'] ?? null) === $username, 422, 'ผู้ใช้รายนี้เป็นผู้รับผิดชอบหลักอยู่แล้ว');
                $extra = array_diff($actions, MongoUserStore::effectiveActions($membership));
                $labels = ['edit' => 'แก้ไข', 'delete' => 'ลบ', 'esign' => 'ลงนามอิเล็กทรอนิกส์', 'publish' => 'เผยแพร่'];
                abort_if($extra !== [], 422, 'สิทธิ์เกินกว่าสิทธิ์ปัจจุบันในหน่วยงาน: '.implode(', ', array_map(fn ($action) => $labels[$action], $extra)));
            }
            $before = LawMetaNormalizer::coEditors($review['law_meta']['co_editors'] ?? []);
            $after = array_values(array_filter($before, fn ($person) => $person['username'] !== $username));
            if ($actions !== null) {
                $old = array_values(array_filter($before, fn ($person) => $person['username'] === $username))[0] ?? [];
                $after[] = ['username' => $username, 'name' => $target['name'] ?? $username, 'actions' => $actions,
                    'added_by' => $old['added_by'] ?? ($actor['username'] ?? null), 'added_at' => $old['added_at'] ?? now()->toIso8601String()];
            }
            $review['law_meta']['co_editors'] = $after;
        });
        $audit->record($request, $documentId, $actions === null ? 'co_editors.remove' : 'co_editors.put',
            ['responsible' => $status['responsible'] ?? null, 'co_editors' => $before], ['co_editors' => $after]);
        try {
            $indexer->index($documentId);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Law indexing failed after co-editor update', ['document_id' => $documentId, 'error' => $exception->getMessage()]);
        }
        return response()->json(['co_editors' => $after, 'can' => $access->capabilities($request, $status)]);
    }
}
