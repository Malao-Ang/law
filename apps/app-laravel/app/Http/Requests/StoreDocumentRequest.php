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
        return [
            'file' => ['required', 'file', 'max:51200', 'mimes:pdf,docx'],
            'scan_extraction_mode' => ['nullable', 'in:auto,local,landingai'],
        ];
    }
}

