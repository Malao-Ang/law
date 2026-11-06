<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogStore $logs) {}

    public function index(Request $request): JsonResponse
    {
        $action = $request->query('action');
        $resource = $request->query('resource');
        $query = $request->query('q');

        return response()->json($this->logs->search(
            is_string($action) ? $action : null,
            is_string($resource) ? $resource : null,
            is_string($query) ? $query : null,
        ));
    }
}
