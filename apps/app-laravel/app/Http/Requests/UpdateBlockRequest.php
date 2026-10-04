<?php

namespace App\Http\Requests;

use App\Services\MasterData\LegalStructures;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdateBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [
            'mark_uncertain' => filter_var($this->input('mark_uncertain', false), FILTER_VALIDATE_BOOL),
        ];

        if ($this->has('chunk_type')) {
            $chunkType = $this->input('chunk_type');
            if ($chunkType === null || trim((string) $chunkType) === '') {
                $data['chunk_type'] = null;
            } else {
                $item = app(LegalStructures::class)->resolve($chunkType);
                if ($item === null || ! (bool) ($item['is_active'] ?? false)) {
                    throw ValidationException::withMessages([
                        'chunk_type' => ['ไม่พบโครงสร้างกฎหมายที่เลือก'],
                    ]);
                }
                $data['chunk_type'] = (string) $item['code'];
            }
        }

        $this->merge($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page_no' => ['required', 'integer', 'min:1'],
            'approved_text' => ['nullable', 'string'],
            'approved_by' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'mark_uncertain' => ['boolean'],
            'type' => ['nullable', 'string', 'in:title,section_header,paragraph,list_item,table,figure_caption,footnote,unknown'],
            'reading_order' => ['nullable', 'integer', 'min:0'],
            'chunk_type' => ['nullable', 'string'],
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
