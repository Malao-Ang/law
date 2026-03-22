<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'mark_uncertain' => filter_var($this->input('mark_uncertain', false), FILTER_VALIDATE_BOOL),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page_no' => ['required', 'integer', 'min:1'],
            'approved_text' => ['required', 'string'],
            'approved_by' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'mark_uncertain' => ['boolean'],
            'type' => ['nullable', 'string', 'in:title,section_header,paragraph,list_item,table,figure_caption,footnote,unknown'],
            'reading_order' => ['nullable', 'integer', 'min:0'],
            'bbox' => ['nullable', 'array', 'size:4'],
            'bbox.*' => ['numeric'],
            'reviewed_html' => ['nullable', 'string'],
            'table' => ['nullable', 'array'],
            'table.headers' => ['nullable', 'array'],
            'table.headers.*' => ['string'],
            'table.rows' => ['nullable', 'array'],
            'table.rows.*' => ['array'],
            'table.rows.*.*' => ['string'],
            'table.cells' => ['nullable', 'array'],
            'table.cells.*' => ['array'],
            'table.cells.*.*.text' => ['required_with:table.cells', 'string'],
            'table.cells.*.*.colspan' => ['nullable', 'integer', 'min:1'],
            'table.cells.*.*.rowspan' => ['nullable', 'integer', 'min:1'],
            'table.cells.*.*.alignment' => ['nullable', 'string'],
        ];
    }
}