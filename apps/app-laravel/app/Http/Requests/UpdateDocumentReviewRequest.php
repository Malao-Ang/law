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
            'law_meta' => ['nullable', 'array'],
            'law_meta.status' => ['nullable', 'string', 'max:120'],
            'law_meta.law_type' => ['nullable', 'string', 'max:120'],
            'law_meta.law_group' => ['nullable', 'string', 'max:120'],
            'law_meta.agency' => ['nullable', 'string', 'max:255'],
            'law_meta.promulgation_date' => ['nullable', 'string', 'max:120'],
            'law_meta.effective_date' => ['nullable', 'string', 'max:120'],
            'law_meta.gazette_reference' => ['nullable', 'string', 'max:255'],
            'law_meta.royal_command' => ['nullable', 'string', 'max:255'],
            'law_meta.repealed_laws' => ['nullable', 'array'],
            'law_meta.repealed_laws.*' => ['nullable', 'string', 'max:255'],
            'relations' => ['nullable', 'array'],
            'relations.*.id' => ['nullable', 'string', 'max:64'],
            'relations.*.scope' => ['nullable', 'string', 'in:document,section'],
            'relations.*.block_id' => ['nullable', 'string', 'max:64'],
            'relations.*.type' => ['nullable', 'string', 'in:related,repeals'],
            'relations.*.target_document_id' => ['nullable', 'string', 'max:128'],
            'relations.*.target_title' => ['nullable', 'string', 'max:255'],
            'relations.*.target_section' => ['nullable', 'string', 'max:120'],
            'relations.*.note' => ['nullable', 'string', 'max:500'],
            'relations.*.url' => ['nullable', 'url', 'max:500'],
        ];
    }
}
