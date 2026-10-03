<?php

namespace App\Http\Requests;

use App\Services\MasterData\MasterDataKind;
use Illuminate\Foundation\Http\FormRequest;

class UpsertMasterItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kind = MasterDataKind::tryFrom((string) $this->route('kind'));

        return array_merge([
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
            'attrs' => ['nullable', 'array'],
        ], $kind?->attrRules() ?? []);
    }
}
