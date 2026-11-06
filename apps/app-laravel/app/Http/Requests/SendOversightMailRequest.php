<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendOversightMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('document_id') === '') {
            $this->merge(['document_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_id.regex' => 'รหัสกฎหมายไม่ถูกต้อง',
        ];
    }
}
