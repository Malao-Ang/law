<?php

namespace App\Http\Requests;

use App\Services\MasterData\EnforcementStatuses;
use Illuminate\Foundation\Http\FormRequest;

class LawSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $filters = $this->input('filters');
        if (is_array($filters) && is_array($filters['status'] ?? null)) {
            $statuses = app(EnforcementStatuses::class);
            $filters['status'] = array_values(array_map(
                static fn (mixed $status): string => (string) ($statuses->resolve($status)['code'] ?? trim((string) $status)),
                $filters['status'],
            ));
        }

        $this->merge(['filters' => $filters]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:500'],
            'filters' => ['nullable', 'array'],
            'filters.law_type' => ['nullable', 'array'],
            'filters.law_type.*' => ['nullable', 'string', 'max:120'],
            'filters.status' => ['nullable', 'array'],
            'filters.status.*' => ['nullable', 'string', 'max:120'],
            'filters.change_status' => ['nullable', 'array'],
            'filters.change_status.*' => ['nullable', 'string', 'max:120'],
            'filters.agency' => ['nullable', 'array'],
            'filters.agency.*' => ['nullable', 'string', 'max:255'],
            'filters.law_group' => ['nullable', 'array'],
            'filters.law_group.*' => ['nullable', 'string', 'max:255'],
            'filters.signer_group' => ['nullable', 'array'],
            'filters.signer_group.*' => ['nullable', 'string', 'max:255'],
            'filters.year_from' => ['nullable', 'integer'],
            'filters.year_to' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
