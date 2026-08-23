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
            'page_margins' => ['nullable', 'array'],
            'page_margins.top' => ['nullable', 'integer', 'min:0', 'max:5760'],
            'page_margins.bottom' => ['nullable', 'integer', 'min:0', 'max:5760'],
            'page_margins.left' => ['nullable', 'integer', 'min:0', 'max:5760'],
            'page_margins.right' => ['nullable', 'integer', 'min:0', 'max:5760'],
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
            'law_meta.change_status' => ['nullable', 'string', 'max:120'],
            'law_meta.agency' => ['nullable', 'string', 'max:255'],
            'law_meta.signer_group' => ['nullable', 'string', 'max:255'],
            'law_meta.issuer' => ['nullable', 'string', 'max:120'],
            'law_meta.promulgation_date' => ['nullable', 'string', 'max:120'],
            'law_meta.effective_date' => ['nullable', 'string', 'max:120'],
            'law_meta.gazette_reference' => ['nullable', 'string', 'max:255'],
            'law_meta.royal_command' => ['nullable', 'string', 'max:255'],
            'law_meta.repealed_laws' => ['nullable', 'array'],
            'law_meta.repealed_laws.*' => ['nullable', 'string', 'max:255'],
            'law_meta.keywords' => ['nullable', 'array', 'max:30'],
            'law_meta.keywords.*' => ['nullable', 'string', 'max:80'],
            'law_meta.law_groups' => ['nullable', 'array'],
            'law_meta.law_groups.*' => ['nullable', 'string', 'max:120'],
            'law_meta.agencies' => ['nullable', 'array'],
            'law_meta.agencies.*' => ['nullable', 'string', 'max:255'],
            'law_meta.section_count' => ['nullable', 'integer', 'min:0'],
            'law_meta.published_date' => ['nullable', 'string', 'max:120'],
            'law_meta.expiry_date' => ['nullable', 'string', 'max:120'],
            'law_meta.title' => ['nullable', 'string', 'max:500'],
            'law_meta.imported_by' => ['nullable', 'string', 'max:255'],
            'law_meta.parent_document_id' => ['nullable', 'string', 'max:128'],
            'law_meta.access_scope' => ['nullable', 'string', 'in:public,private'],
            'law_meta.permission_group_ids' => ['nullable', 'array'],
            'law_meta.permission_group_ids.*' => ['nullable', 'string', 'max:128'],
            'relations' => ['nullable', 'array'],
            'relations.*.id' => ['nullable', 'string', 'max:64'],
            'relations.*.scope' => ['nullable', 'string', 'in:document,section'],
            'relations.*.block_id' => ['nullable', 'string', 'max:64'],
            'relations.*.type' => ['nullable', 'string', 'in:related,repeals,amends,issued_under,supersedes'],
            'relations.*.target_document_id' => ['nullable', 'string', 'max:128'],
            'relations.*.target_title' => ['nullable', 'string', 'max:255'],
            'relations.*.target_section' => ['nullable', 'string', 'max:120'],
            'relations.*.target_block_id' => ['nullable', 'string', 'max:64'],
            'relations.*.note' => ['nullable', 'string', 'max:500'],
            'relations.*.url' => ['nullable', 'url', 'max:500'],
        ];
    }
}
