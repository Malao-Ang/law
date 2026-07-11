<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Permissions\PermissionStore;
use Illuminate\Http\JsonResponse;

class PermissionDirectoryController extends Controller
{
    public function __construct(private readonly PermissionStore $permissionStore) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->permissionStore->directory());
    }
}
