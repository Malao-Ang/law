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
            'font_family' => ['nullable', 'string', 'in:sarabun,psk-sarabun,angsana'],
            'font_size_pt' => ['nullable', 'integer', 'min:8', 'max:72'],
            'metadata' => ['nullable', 'array'],
            'metadata.department' => ['nullable', 'string', 'max:255'],
            'metadata.doc_number' => ['nullable', 'string', 'max:120'],
            'metadata.date' => ['nullable', 'string', 'max:120'],
            'metadata.subject' => ['nullable', 'string', 'max:255'],
            'metadata.recipient' => ['nullable', 'string', 'max:255'],
            'metadata.reference' => ['nullable', 'string', 'max:255'],
            'metadata.attachments' => ['nullable', 'string', 'max:255'],
            'metadata.urgency' => ['nullable', 'string', 'max:120'],
            'metadata.confidentiality' => ['nullable', 'string', 'max:120'],
            'metadata.signatory_name' => ['nullable', 'string', 'max:255'],
            'metadata.signatory_position' => ['nullable', 'string', 'max:255'],
        ];
    }
}
