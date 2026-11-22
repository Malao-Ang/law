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
        $channelRules = [
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:4000'],
            'link_label' => ['required', 'string', 'max:120'],
        ];

        return [
            'deadline' => ['required', 'array'],
            'deadline.subject' => $channelRules['subject'],
            'deadline.body' => $channelRules['body'],
            'deadline.link_label' => $channelRules['link_label'],
            'proclamation' => ['required', 'array'],
            'proclamation.subject' => $channelRules['subject'],
            'proclamation.body' => $channelRules['body'],
            'proclamation.link_label' => $channelRules['link_label'],
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
            'deadline.required' => 'กรุณาระบุข้อความแจ้งเตือนใกล้ครบกำหนด',
            'deadline.subject.required' => 'กรุณาระบุหัวข้ออีเมลใกล้ครบกำหนด',
            'deadline.subject.max' => 'หัวข้ออีเมลยาวเกิน 180 ตัวอักษร',
            'deadline.body.required' => 'กรุณาระบุข้อความอีเมลใกล้ครบกำหนด',
            'deadline.body.max' => 'ข้อความอีเมลยาวเกิน 4,000 ตัวอักษร',
            'deadline.link_label.required' => 'กรุณาระบุข้อความลิงก์ใกล้ครบกำหนด',
            'deadline.link_label.max' => 'ข้อความลิงก์ยาวเกิน 120 ตัวอักษร',
            'proclamation.required' => 'กรุณาระบุข้อความแจ้งเตือนออกประกาศใหม่',
            'proclamation.subject.required' => 'กรุณาระบุหัวข้ออีเมลออกประกาศใหม่',
            'proclamation.subject.max' => 'หัวข้ออีเมลยาวเกิน 180 ตัวอักษร',
            'proclamation.body.required' => 'กรุณาระบุข้อความอีเมลออกประกาศใหม่',
            'proclamation.body.max' => 'ข้อความอีเมลยาวเกิน 4,000 ตัวอักษร',
            'proclamation.link_label.required' => 'กรุณาระบุข้อความลิงก์ออกประกาศใหม่',
            'proclamation.link_label.max' => 'ข้อความลิงก์ยาวเกิน 120 ตัวอักษร',
            'reminder_days.required' => 'กรุณาระบุจำนวนวันที่จะแจ้งเตือน',
            'reminder_days.min' => 'ต้องมีอย่างน้อย 1 รอบแจ้งเตือน',
            'reminder_days.max' => 'เพิ่มรอบแจ้งเตือนได้ไม่เกิน 8 รอบ',
            'reminder_days.*.integer' => 'จำนวนวันต้องเป็นจำนวนเต็ม',
            'reminder_days.*.distinct' => 'จำนวนวันซ้ำกัน',
            'reminder_days.*.between' => 'จำนวนวันต้องอยู่ระหว่าง 0 ถึง 365',
        ];
    }
}
