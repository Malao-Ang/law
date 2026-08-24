<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class LookupController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'document_types' => config('lookups.document_types'),
            'statuses' => config('lookups.statuses'),
            'change_statuses' => config('lookups.change_statuses'),
            'agencies' => config('lookups.agencies'),
            'law_groups' => config('lookups.law_groups'),
            'law_sources' => config('lookups.law_sources'),
        ]);
    }
}
