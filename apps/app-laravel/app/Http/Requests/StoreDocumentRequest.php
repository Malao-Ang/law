<?php

namespace App\Http\Requests;

use App\Services\MasterData\LawTypes;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lawType = $this->input('law_type');
        if ($lawType !== null && $lawType !== '') {
            $resolved = app(LawTypes::class)->resolve($lawType);
            if ($resolved !== null) {
                $this->merge(['law_type' => (string) $resolved['code']]);
            }
        }
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
            'source' => ['nullable', 'required_if:document_type,old', 'in:internal,external'],
            'law_type' => [
                'nullable',
                'required_if:document_type,old',
                function (string $attribute, mixed $value, \Closure $fail, mixed $validator): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $lawTypes = app(LawTypes::class);
                    $match = $lawTypes->resolve($value);
                    if ($match === null || ! (bool) ($match['is_active'] ?? false)) {
                        $fail('Invalid law type.');

                        return;
                    }

                    $data = method_exists($validator, 'getData') ? $validator->getData() : [];
                    $source = $data['source'] ?? $this->input('source');
                    if ($source !== null && $lawTypes->sourceOf($value) !== $source) {
                        $fail('Law type source does not match the selected source.');
                    }
                },
            ],
        ];
    }
}
