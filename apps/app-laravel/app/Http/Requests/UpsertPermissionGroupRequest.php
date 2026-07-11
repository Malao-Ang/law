<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertPermissionGroupRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'unit_ids' => ['nullable', 'array'],
            'unit_ids.*' => ['nullable', 'string', 'max:128'],
            'position_ids' => ['nullable', 'array'],
            'position_ids.*' => ['nullable', 'string', 'max:128'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['nullable', 'string', 'max:128'],
        ];
    }
}
