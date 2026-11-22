<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOversightRequest extends FormRequest
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
            'primary' => ['required', 'array'],
            'primary.name' => ['nullable', 'string', 'max:120'],
            'primary.email' => ['nullable', 'email', 'max:180'],
            'backup' => ['required', 'array'],
            'backup.name' => ['nullable', 'string', 'max:120'],
            'backup.email' => ['nullable', 'email', 'max:180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'primary.email.email' => 'อีเมลผู้รับผิดชอบหลักไม่ถูกต้อง',
            'backup.email.email' => 'อีเมลผู้ประสานงานสำรองไม่ถูกต้อง',
        ];
    }
}
