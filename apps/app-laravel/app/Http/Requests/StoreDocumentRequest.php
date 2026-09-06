<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
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
        $isOld = $this->input('document_type') === 'old';

        return [
            'file' => array_filter([
                'required', 'file', 'max:51200',
                $isOld ? 'mimes:pdf' : 'mimes:doc,docx',
            ]),
            'scan_extraction_mode' => ['nullable', 'in:local,gemini,landingai'],
            'extraction_engine' => ['nullable', 'in:standard,fast'],
            'document_type' => ['nullable', 'in:new,old'],
            'source' => ['nullable', 'in:internal,external'],
            'law_type' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail, mixed $validator): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $match = collect(config('lookups.document_types'))
                        ->firstWhere('value', $value);
                    if ($match === null) {
                        $fail('ประเภทกฎหมายไม่ถูกต้อง');
                        return;
                    }
                    $data = method_exists($validator, 'getData') ? $validator->getData() : [];
                    $source = $data['source'] ?? $this->input('source');
                    if ($source !== null && ($match['source'] ?? null) !== $source) {
                        $fail('ประเภทกฎหมายไม่ตรงกับแหล่งที่มาที่เลือก');
                    }
                },
            ],
        ];
    }
}
