<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReprocessBlockRequest extends FormRequest
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
            'page_no' => ['required', 'integer', 'min:1'],
            'mode' => ['required', 'string', 'in:ai_correction'],
        ];
    }
}
