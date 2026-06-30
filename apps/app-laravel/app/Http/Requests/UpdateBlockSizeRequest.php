<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBlockSizeRequest extends FormRequest
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
            'display_width_px' => ['nullable', 'integer', 'min:20', 'max:4000'],
            'display_height_px' => ['nullable', 'integer', 'min:20', 'max:4000'],
        ];
    }
}
