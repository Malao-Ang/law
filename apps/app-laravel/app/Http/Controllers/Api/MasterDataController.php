<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MasterDataConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertMasterItemRequest;
use App\Services\MasterData\MasterDataKind;
use App\Services\MasterData\MasterDataStore;
use App\Services\MasterData\UsageCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterDataController extends Controller
{
    public function __construct(
        private readonly MasterDataStore $store,
        private readonly UsageCounter $usageCounter,
    ) {}

    public function index(Request $request, string $kind): JsonResponse
    {
        $masterKind = $this->kind($kind);
        if ($masterKind === null) {
            return $this->unknownKind();
        }

        $active = match ((string) $request->query('active', 'all')) {
            '1' => true,
            '0' => false,
            default => null,
        };

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 10)));
        $result = $this->store->list($masterKind, $request->query('q'), $active, $page, $perPage);
        $result['items'] = array_map(fn (array $item): array => $this->withUsage($masterKind, $item), $result['items']);

        return response()->json($result);
    }

    public function store(UpsertMasterItemRequest $request, string $kind): JsonResponse
    {
        $masterKind = $this->kind($kind);
        if ($masterKind === null) {
            return $this->unknownKind();
        }

        return response()->json($this->store->create($masterKind, $request->validated()), 201);
    }

    public function reorder(Request $request, string $kind): JsonResponse
    {
        $masterKind = $this->kind($kind);
        if ($masterKind === null) {
            return $this->unknownKind();
        }

        $validated = $request->validate([
            'codes' => ['required', 'array'],
            'codes.*' => ['required', 'string', 'max:32'],
        ]);

        $this->store->reorder($masterKind, $validated['codes']);

        return response()->json(['status' => 'ok']);
    }

    public function show(string $kind, string $code): JsonResponse
    {
        $masterKind = $this->kind($kind);
        if ($masterKind === null) {
            return $this->unknownKind();
        }

        $item = $this->store->find($masterKind, $code);
        if ($item === null) {
            return response()->json(['message' => 'Master data item not found.'], 404);
        }

        return response()->json($this->withUsage($masterKind, $item));
    }

    public function update(UpsertMasterItemRequest $request, string $kind, string $code): JsonResponse
    {
        $masterKind = $this->kind($kind);
        if ($masterKind === null) {
            return $this->unknownKind();
        }

        $item = $this->store->update($masterKind, $code, $request->validated());
        if ($item === null) {
            return response()->json(['message' => 'Master data item not found.'], 404);
        }

        return response()->json($item);
    }

    public function setActive(Request $request, string $kind, string $code): JsonResponse
    {
        $masterKind = $this->kind($kind);
        if ($masterKind === null) {
            return $this->unknownKind();
        }

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        try {
            $item = $this->store->setActive($masterKind, $code, (bool) $validated['is_active']);
        } catch (MasterDataConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if ($item === null) {
            return response()->json(['message' => 'Master data item not found.'], 404);
        }

        return response()->json($item);
    }

    public function destroy(string $kind, string $code): JsonResponse
    {
        $masterKind = $this->kind($kind);
        if ($masterKind === null) {
            return $this->unknownKind();
        }

        try {
            $deleted = $this->store->delete($masterKind, $code);
        } catch (MasterDataConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if (! $deleted) {
            return response()->json(['message' => 'Master data item not found.'], 404);
        }

        return response()->json(null, 204);
    }

    private function kind(string $kind): ?MasterDataKind
    {
        return MasterDataKind::tryFrom($kind);
    }

    private function unknownKind(): JsonResponse
    {
        return response()->json(['message' => 'Unknown master data kind'], 404);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function withUsage(MasterDataKind $kind, array $item): array
    {
        return array_merge($item, [
            'usage_count' => $this->usageCounter->count($kind, (string) ($item['code'] ?? '')),
        ]);
    }
}
