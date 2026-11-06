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
            'reminder_days' => ['sometimes', 'array', 'min:1', 'max:8'],
            'reminder_days.*' => ['integer', 'distinct', 'between:0,365'],
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
            'reminder_days.required' => 'กรุณาระบุจำนวนวันที่จะแจ้งเตือน',
            'reminder_days.min' => 'ต้องมีอย่างน้อย 1 รอบแจ้งเตือน',
            'reminder_days.max' => 'เพิ่มรอบแจ้งเตือนได้ไม่เกิน 8 รอบ',
            'reminder_days.*.integer' => 'จำนวนวันต้องเป็นจำนวนเต็ม',
            'reminder_days.*.distinct' => 'จำนวนวันซ้ำกัน',
            'reminder_days.*.between' => 'จำนวนวันต้องอยู่ระหว่าง 0 ถึง 365',
        ];
    }
}
