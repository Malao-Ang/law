<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBlockLayoutRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page_no'           => ['required', 'integer', 'min:1'],
            'indent_level'      => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10'],
            'list_marker_level' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:6'],
            'alignment'         => ['sometimes', 'nullable', Rule::in(['left', 'center', 'right', 'justify'])],
            'indent_left'       => ['sometimes', 'nullable', 'integer', 'min:0', 'max:14400'],
            'indent_first_line' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:14400'],
            'indent_hanging'    => ['sometimes', 'nullable', 'integer', 'min:0', 'max:14400'],
            'tabs'              => ['sometimes', 'array', 'max:32'],
            'tabs.*.position'   => ['required_with:tabs', 'integer', 'min:0', 'max:14400'],
            'tabs.*.type'       => ['required_with:tabs', Rule::in(['left', 'center', 'right', 'decimal'])],
        ];
    }
}
