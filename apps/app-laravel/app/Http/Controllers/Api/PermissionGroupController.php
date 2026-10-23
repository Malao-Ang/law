<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertPermissionGroupRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Permissions\PermissionStore;
use Illuminate\Http\JsonResponse;

class PermissionGroupController extends Controller
{
    public function __construct(
        private readonly PermissionStore $permissionStore,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'groups' => $this->permissionStore->listGroups(),
        ]);
    }

    public function show(string $groupId): JsonResponse
    {
        $group = $this->permissionStore->findGroup($groupId);
        if ($group === null) {
            return response()->json(['message' => 'Permission group not found.'], 404);
        }

        return response()->json($group);
    }

    public function store(UpsertPermissionGroupRequest $request): JsonResponse
    {
        $group = $this->permissionStore->createGroup($request->validated());
        $this->audit->record(
            'create',
            'permission_group',
            (string) $group['id'],
            'เพิ่มกลุ่มสิทธิ์',
            ['ชื่อ: '.(string) ($group['name'] ?? '')],
            (string) ($group['name'] ?? ''),
        );

        return response()->json($group, 201);
    }

    public function update(UpsertPermissionGroupRequest $request, string $groupId): JsonResponse
    {
        $group = $this->permissionStore->updateGroup($groupId, $request->validated());
        if ($group === null) {
            return response()->json(['message' => 'Permission group not found.'], 404);
        }

        $this->audit->record(
            'update',
            'permission_group',
            $groupId,
            'แก้ไขกลุ่มสิทธิ์',
            ['ชื่อ: '.(string) ($group['name'] ?? '')],
            (string) ($group['name'] ?? $groupId),
        );

        return response()->json($group);
    }

    public function destroy(string $groupId): JsonResponse
    {
        $existing = $this->permissionStore->findGroup($groupId);
        if (! $this->permissionStore->deleteGroup($groupId)) {
            return response()->json(['message' => 'Permission group not found.'], 404);
        }

        $this->audit->record(
            'delete',
            'permission_group',
            $groupId,
            'ลบกลุ่มสิทธิ์',
            [],
            (string) ($existing['name'] ?? $groupId),
        );

        return response()->json([
            'group_id' => $groupId,
            'status' => 'deleted',
        ]);
    }
}
