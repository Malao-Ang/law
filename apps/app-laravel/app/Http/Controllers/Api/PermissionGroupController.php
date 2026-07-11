<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertPermissionGroupRequest;
use App\Services\Permissions\PermissionStore;
use Illuminate\Http\JsonResponse;

class PermissionGroupController extends Controller
{
    public function __construct(private readonly PermissionStore $permissionStore) {}

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
        return response()->json($this->permissionStore->createGroup($request->validated()), 201);
    }

    public function update(UpsertPermissionGroupRequest $request, string $groupId): JsonResponse
    {
        $group = $this->permissionStore->updateGroup($groupId, $request->validated());
        if ($group === null) {
            return response()->json(['message' => 'Permission group not found.'], 404);
        }

        return response()->json($group);
    }

    public function destroy(string $groupId): JsonResponse
    {
        if (! $this->permissionStore->deleteGroup($groupId)) {
            return response()->json(['message' => 'Permission group not found.'], 404);
        }

        return response()->json([
            'group_id' => $groupId,
            'status' => 'deleted',
        ]);
    }
}
