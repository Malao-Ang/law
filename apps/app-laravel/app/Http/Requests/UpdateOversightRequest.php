<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $statuses = config('oversight.statuses');
        $labels = is_array($statuses) ? array_values(array_filter(array_column($statuses, 'label'))) : [];
        $keys = is_array($statuses) ? array_values(array_filter(array_column($statuses, 'key'))) : [];

        return [
            'status' => ['required', 'string', Rule::in($labels)],
            'publish_due_date' => ['nullable', 'date_format:Y-m-d'],
            'steps' => ['required', 'array', 'size:'.count($keys)],
            'steps.*.key' => ['required', 'string', Rule::in($keys)],
            'steps.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'steps.*.completed_on' => ['nullable', 'date_format:Y-m-d'],
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
            'status.in' => 'สถานะไม่ถูกต้อง',
            'primary.email.email' => 'อีเมลผู้รับผิดชอบหลักไม่ถูกต้อง',
            'backup.email.email' => 'อีเมลผู้ประสานงานสำรองไม่ถูกต้อง',
            'publish_due_date.date_format' => 'กำหนดเวลาออกประกาศต้องเป็นรูปแบบ ปี-เดือน-วัน',
            'steps.*.due_date.date_format' => 'กำหนดแล้วเสร็จต้องเป็นรูปแบบ ปี-เดือน-วัน',
            'steps.*.completed_on.date_format' => 'วันแล้วเสร็จต้องเป็นรูปแบบ ปี-เดือน-วัน',
        ];
    }
}
