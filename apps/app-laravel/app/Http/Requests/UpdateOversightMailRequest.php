<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOversightMailRequest extends FormRequest
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
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:4000'],
            'link_label' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject.required' => 'กรุณาระบุหัวข้ออีเมล',
            'subject.max' => 'หัวข้ออีเมลยาวเกิน 180 ตัวอักษร',
            'body.required' => 'กรุณาระบุข้อความอีเมล',
            'body.max' => 'ข้อความอีเมลยาวเกิน 4,000 ตัวอักษร',
            'link_label.required' => 'กรุณาระบุข้อความลิงก์',
            'link_label.max' => 'ข้อความลิงก์ยาวเกิน 120 ตัวอักษร',
        ];
    }
}
