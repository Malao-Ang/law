<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reset_to_generated' => filter_var($this->input('reset_to_generated', false), FILTER_VALIDATE_BOOL),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'draft_html' => ['nullable', 'string'],
            'approved_by' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reset_to_generated' => ['boolean'],
        ];
    }
}
